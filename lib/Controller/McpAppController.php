<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Controller;

use Mcp\Schema\Content\TextResourceContents;
use OCA\Astrolabe\Http\McpAppViewResponse;
use OCA\Astrolabe\Service\Assistant\AgentException;
use OCA\Astrolabe\Service\Assistant\AgentLoop;
use OCA\Astrolabe\Service\Mcp\McpClientFactory;
use OCA\Astrolabe\Service\Mcp\McpUnavailableException;
use OCA\Astrolabe\Service\McpApp\RedactionProposals;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * MCP Apps host proxy (exploratory POC): the browser-side host relays the
 * view's tools/call, the ui:// resource read and ui/message through here, so
 * every MCP call runs with this user's own minted token.
 *
 * @psalm-api
 */
final class McpAppController extends Controller {
	/**
	 * Tools the view may call, with the scopes minted for that call only. All
	 * carry "app" in _meta.ui.visibility; anything else is refused.
	 */
	private const TOOLS = [
		'open_document' => 'files.read',
		'read_document_bytes' => 'files.read',
		'preview_redaction' => 'files.read',
		'save_redaction' => 'files.read files.write',
	];

	public function __construct(
		string $appName,
		IRequest $request,
		private McpClientFactory $clientFactory,
		private AgentLoop $agentLoop,
		private IUserSession $userSession,
		private RedactionProposals $proposals,
	) {
		parent::__construct($appName, $request);
	}

	/** @param array<string, mixed> $arguments */
	#[NoAdminRequired]
	public function callTool(string $name, array $arguments = []): JSONResponse {
		if (!isset(self::TOOLS[$name])) {
			return new JSONResponse(['error' => "Tool '$name' is not available to this app"], Http::STATUS_FORBIDDEN);
		}
		if ($name === 'open_document') {
			$arguments = $this->withProposal($arguments);
		}
		return $this->withClient(self::TOOLS[$name], fn ($c) => $c->callTool($name, $arguments)->jsonSerialize());
	}

	/**
	 * Swap a viewer link's proposal id for the stored redact_text, for this user
	 * only ({@see RedactionProposals}). Unknown or expired: open without regions.
	 *
	 * @param array<string, mixed> $arguments
	 * @return array<string, mixed>
	 */
	private function withProposal(array $arguments): array {
		$id = isset($arguments['proposal']) && is_string($arguments['proposal']) ? $arguments['proposal'] : null;
		unset($arguments['proposal'], $arguments['redact_text'], $arguments['review']);
		if (is_string($id) && is_numeric($arguments['file_id'] ?? null)) {
			$texts = $this->proposals->texts($this->uid(), $id, (int)$arguments['file_id']);
			if ($texts !== []) {
				$arguments['redact_text'] = $texts;
			}
			$review = $this->proposals->review($this->uid(), $id, (int)$arguments['file_id']);
			if ($review !== null) {
				$arguments['review'] = $review;
			}
		}
		return $arguments;
	}

	/**
	 * The view itself, served from our origin so the iframe gets its own CSP
	 * (a srcdoc frame would inherit Nextcloud's nonce-only script-src). Embed
	 * with sandbox="allow-scripts" and no allow-same-origin: the frame is then
	 * opaque-origin and cannot reach Nextcloud cookies or DOM.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function view(string $uri): Response {
		if (!str_starts_with($uri, 'ui://')) {
			return new JSONResponse(['error' => 'Only ui:// resources'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$client = $this->clientFactory->connect($this->uid(), 'files.read', true);
			try {
				$contents = $client->readResource($uri)->contents[0] ?? null;
			} finally {
				$client->disconnect();
			}
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
		if (!$contents instanceof TextResourceContents) {
			return new JSONResponse(['error' => 'Resource has no HTML'], Http::STATUS_BAD_GATEWAY);
		}
		/** @var mixed $csp */
		$csp = $contents->meta['ui']['csp'] ?? [];
		return new McpAppViewResponse($contents->text, self::viewCsp(is_array($csp) ? $csp : []));
	}

	/** @param array<array-key, mixed> $csp _meta.ui.csp of the resource */
	public static function viewCsp(array $csp): string {
		$list = static function (string $key) use ($csp): string {
			$domains = array_filter(
				(array)($csp[$key] ?? []),
				static fn ($d) => is_string($d) && preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $d) === 1,
			);
			return implode(' ', $domains);
		};
		$res = $list('resourceDomains');
		$connect = $list('connectDomains');
		return implode('; ', [
			"default-src 'none'",
			"script-src 'unsafe-inline' $res",
			"style-src 'unsafe-inline' $res",
			"img-src data: blob: $res",
			"font-src data: $res",
			"worker-src blob: $res",
			'connect-src ' . ($connect !== '' ? $connect : "'none'"),
			"frame-ancestors 'self'",
		]);
	}

	/** The viewer's current staged state, for the user's next chat turn (POC). */
	#[NoAdminRequired]
	public function modelContext(int $fileId, string $text): JSONResponse {
		$this->proposals->saveViewerContext($this->uid(), $fileId, $text);
		return new JSONResponse([]);
	}

	#[NoAdminRequired]
	public function message(string $text): JSONResponse {
		try {
			$result = $this->agentLoop->run($this->uid(), $text, []);
		} catch (AgentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
		return new JSONResponse(['output' => $result['output'], 'sources' => $result['sources']]);
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	/** @param callable(\Mcp\Client\Client): array<array-key, mixed> $fn */
	private function withClient(string $scopes, callable $fn): JSONResponse {
		try {
			$client = $this->clientFactory->connect($this->uid(), $scopes, true);
		} catch (McpUnavailableException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}
		try {
			return new JSONResponse($fn($client));
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		} finally {
			try {
				$client->disconnect();
			} catch (\Throwable) {
			}
		}
	}
}
