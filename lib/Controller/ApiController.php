<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Controller;

use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\McpTokenMinter;
use OCA\Astrolabe\Service\McpTokenMintException;
use OCA\Astrolabe\Service\WebhookPresets;
use OCA\Astrolabe\Settings\Admin as AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * API controller for MCP Server UI.
 *
 * Handles form submissions and AJAX requests from settings panels.
 * Authenticates to the MCP server by minting a short-lived JWT for the
 * current Nextcloud session user via McpTokenMinter — no per-user
 * OAuth tokens are persisted by this controller or the app at large.
 */
class ApiController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private McpServerClient $client,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private McpTokenMinter $tokenMinter,
		private IConfig $config,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Mint an MCP token for the current authenticated user, or return a
	 * structured JSONResponse describing why we couldn't.
	 *
	 * Single helper so every endpoint in this controller bails out the
	 * same way and the SonarCloud duplication threshold stays untripped.
	 *
	 * Returns the minted token (string) on success, or a JSONResponse to
	 * return as-is on failure. Callers narrow with ``instanceof JSONResponse``
	 * so the success path keeps a non-null string without tripping Psalm's
	 * nullable-return checks (a tuple return decorrelates the two halves).
	 */
	private function tokenForCurrentUser(): JSONResponse|string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse([
				'success' => false,
				'error' => 'User not authenticated',
			], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return $this->tokenMinter->mintForUser($user->getUID());
		} catch (McpTokenMintException $e) {
			$this->logger->error('MCP token mint failed', [
				'user_id' => $user->getUID(),
				'error' => $e->getMessage(),
			]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage(),
			], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}

	/**
	 * Execute semantic search via MCP server.
	 *
	 * AJAX endpoint for vector search UI in app page.
	 */
	#[NoAdminRequired]
	public function search(
		string $query = '',
		string $algorithm = 'hybrid',
		int $limit = 10,
		string $doc_types = '',
		string $include_pca = 'true',
		string $modified_after = '',
		string $modified_before = '',
		string $path_prefix = '',
		string $path_prefixes = '',
	): JSONResponse {
		if (empty($query)) {
			return new JSONResponse([
				'success' => false,
				'error' => 'Missing required parameter: query',
			], Http::STATUS_BAD_REQUEST);
		}

		// ADR-027 modified-date range filter. Validate the RFC 3339 / ISO 8601
		// bounds here so malformed input or an inverted range surfaces as a 400
		// rather than a 500 from the MCP server. Empty string ⇒ open bound.
		$afterTs = null;
		$beforeTs = null;
		try {
			if ($modified_after !== '') {
				$afterTs = (new \DateTimeImmutable($modified_after))->getTimestamp();
			}
			if ($modified_before !== '') {
				$beforeTs = (new \DateTimeImmutable($modified_before))->getTimestamp();
			}
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => 'modified_after/modified_before must be RFC 3339 datetimes',
			], Http::STATUS_BAD_REQUEST);
		}
		if ($afterTs !== null && $beforeTs !== null && $afterTs > $beforeTs) {
			return new JSONResponse([
				'success' => false,
				'error' => 'modified_after must be on or before modified_before',
			], Http::STATUS_BAD_REQUEST);
		}

		$accessToken = $this->tokenForCurrentUser();
		if ($accessToken instanceof JSONResponse) {
			return $accessToken;
		}

		// Validate algorithm
		$validAlgorithms = ['semantic', 'bm25', 'hybrid'];
		if (!in_array($algorithm, $validAlgorithms)) {
			$algorithm = 'hybrid';
		}

		// Enforce limit bounds
		$limit = max(1, min($limit, 50));

		// Parse doc_types filter
		$docTypesArray = null;
		if (!empty($doc_types)) {
			$validDocTypes = ['note', 'file', 'deck_card', 'calendar', 'contact', 'news_item'];
			$docTypesArray = array_filter(
				explode(',', $doc_types),
				fn ($t) => in_array(trim($t), $validDocTypes),
			);
			$docTypesArray = array_map('trim', $docTypesArray);
			if (empty($docTypesArray)) {
				$docTypesArray = null;
			}
		}

		$includePcaBool = in_array(strtolower($include_pca), ['true', '1', 'yes'], true);

		// ADR-027 Phase 2 path filter. Accept a newline-separated path_prefixes
		// list (multi-folder) alongside the legacy single path_prefix; trim,
		// drop blanks, and dedupe so the folders OR cleanly on the MCP server.
		// Newline is the delimiter because, unlike a comma, it can't appear in a
		// POSIX path, so folder names are never split mid-value.
		$pathPrefixesArray = [];
		foreach (array_merge([$path_prefix], explode("\n", $path_prefixes)) as $folder) {
			$folder = trim($folder);
			if ($folder !== '' && !in_array($folder, $pathPrefixesArray, true)) {
				$pathPrefixesArray[] = $folder;
			}
		}
		// Cap the OR-filter width so a malformed/hostile client can't build an
		// unbounded should-clause on the MCP server.
		$pathPrefixesArray = array_slice($pathPrefixesArray, 0, 20);

		$result = $this->client->search(
			$query,
			$algorithm,
			$limit,
			$includePcaBool,
			$docTypesArray,
			$accessToken,
			$modified_after !== '' ? $modified_after : null,
			$modified_before !== '' ? $modified_before : null,
			$pathPrefixesArray !== [] ? $pathPrefixesArray : null,
		);

		if (isset($result['error'])) {
			return new JSONResponse([
				'success' => false,
				'error' => $result['error'],
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$response = [
			'success' => true,
			'results' => $result['results'] ?? [],
			'algorithm_used' => $result['algorithm_used'] ?? $algorithm,
			'total_documents' => $result['total_documents'] ?? 0,
		];

		if ($includePcaBool) {
			$response['coordinates_3d'] = $result['coordinates_3d'] ?? [];
			$response['query_coords'] = $result['query_coords'] ?? [];
			if (isset($result['pca_variance'])) {
				$response['pca_variance'] = $result['pca_variance'];
			}
		}

		return new JSONResponse($response);
	}

	/**
	 * Get vector sync status from MCP server (public endpoint on the
	 * MCP server, no token required).
	 */
	#[NoAdminRequired]
	public function vectorStatus(): JSONResponse {
		$status = $this->client->getVectorSyncStatus();
		if (isset($status['error'])) {
			return new JSONResponse([
				'success' => false,
				'error' => $status['error'],
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse(['success' => true, 'status' => $status]);
	}

	/**
	 * Get MCP server status. Admin-only via SecurityMiddleware (no
	 * #[NoAdminRequired]).
	 */
	public function serverStatus(): JSONResponse {
		$status = $this->client->getStatus();
		if (!is_array($status)) {
			return new JSONResponse([
				'success' => false,
				'error' => 'Invalid response from MCP server',
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		if (isset($status['error'])) {
			return new JSONResponse([
				'success' => false,
				'error' => $status['error'],
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse(['success' => true, 'status' => $status]);
	}

	/**
	 * Get vector sync status for admin panel. Admin-only.
	 */
	public function adminVectorStatus(): JSONResponse {
		$status = $this->client->getVectorSyncStatus();
		if (!is_array($status)) {
			return new JSONResponse([
				'success' => false,
				'error' => 'Invalid response from MCP server',
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		if (isset($status['error'])) {
			return new JSONResponse([
				'success' => false,
				'error' => $status['error'],
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse(['success' => true, 'status' => $status]);
	}

	/**
	 * Save admin search settings. Admin-only.
	 */
	public function saveSearchSettings(
		string $algorithm = AdminSettings::DEFAULT_SEARCH_ALGORITHM,
		string $fusion = AdminSettings::DEFAULT_SEARCH_FUSION,
		int $scoreThreshold = AdminSettings::DEFAULT_SEARCH_SCORE_THRESHOLD,
		int $limit = AdminSettings::DEFAULT_SEARCH_LIMIT,
	): JSONResponse {
		// Parameters are populated by the framework from the JSON request body
		// (no need to read php://input directly).
		$validAlgorithms = ['hybrid', 'semantic', 'bm25'];
		if (!in_array($algorithm, $validAlgorithms, true)) {
			$algorithm = AdminSettings::DEFAULT_SEARCH_ALGORITHM;
		}
		$this->config->setAppValue($this->appName, AdminSettings::SETTING_SEARCH_ALGORITHM, $algorithm);

		$validFusions = ['rrf', 'dbsf'];
		if (!in_array($fusion, $validFusions, true)) {
			$fusion = AdminSettings::DEFAULT_SEARCH_FUSION;
		}
		$this->config->setAppValue($this->appName, AdminSettings::SETTING_SEARCH_FUSION, $fusion);

		$scoreThreshold = max(0, min(100, $scoreThreshold));
		$this->config->setAppValue($this->appName, AdminSettings::SETTING_SEARCH_SCORE_THRESHOLD, (string)$scoreThreshold);

		$limit = max(5, min(100, $limit));
		$this->config->setAppValue($this->appName, AdminSettings::SETTING_SEARCH_LIMIT, (string)$limit);

		$this->logger->info('Admin search settings saved', [
			'algorithm' => $algorithm,
			'fusion' => $fusion,
			'scoreThreshold' => $scoreThreshold,
			'limit' => $limit,
		]);

		return new JSONResponse([
			'success' => true,
			'settings' => [
				'algorithm' => $algorithm,
				'fusion' => $fusion,
				'scoreThreshold' => $scoreThreshold,
				'limit' => $limit,
			],
		]);
	}

	/**
	 * List webhook presets and which are currently enabled. Admin-only.
	 *
	 * The admin's session-derived JWT is used to talk to the MCP server;
	 * there is no longer a separate "must enable semantic search first"
	 * gate — being a logged-in Nextcloud admin is enough.
	 */
	public function getWebhookPresets(): JSONResponse {
		$accessToken = $this->tokenForCurrentUser();
		if ($accessToken instanceof JSONResponse) {
			return $accessToken;
		}

		$installedAppsResult = $this->client->getInstalledApps($accessToken);
		if (isset($installedAppsResult['error'])) {
			return $this->mcpErrorResponse($installedAppsResult);
		}
		$installedApps = $installedAppsResult['apps'] ?? [];

		$webhooksResult = $this->client->listWebhooks($accessToken);
		if (isset($webhooksResult['error'])) {
			return $this->mcpErrorResponse($webhooksResult);
		}
		$registeredWebhooks = $webhooksResult['webhooks'] ?? [];

		$presets = WebhookPresets::filterPresetsByInstalledApps($installedApps);

		// Mark each preset enabled iff all of its (event, filter) pairs
		// match a registered webhook. Both criteria are required because
		// Notes and Files both fire FILE_EVENT_* — only the filter
		// distinguishes them.
		$presetsWithStatus = [];
		foreach ($presets as $presetId => $preset) {
			$allEventsRegistered = true;
			foreach ($preset['events'] as $presetEvent) {
				$eventMatched = false;
				foreach ($registeredWebhooks as $webhook) {
					if ($webhook['event'] !== $presetEvent['event']) {
						continue;
					}
					$presetFilter = !empty($presetEvent['filter']) ? $presetEvent['filter'] : null;
					$webhookFilter = !empty($webhook['eventFilter']) ? $webhook['eventFilter'] : null;
					if (json_encode($presetFilter) === json_encode($webhookFilter)) {
						$eventMatched = true;
						break;
					}
				}
				if (!$eventMatched) {
					$allEventsRegistered = false;
					break;
				}
			}
			$presetsWithStatus[$presetId] = array_merge($preset, ['enabled' => $allEventsRegistered]);
		}

		return new JSONResponse(['success' => true, 'presets' => $presetsWithStatus]);
	}

	/**
	 * Enable a webhook preset by registering each of its events with
	 * the MCP server. Admin-only.
	 */
	public function enableWebhookPreset(string $presetId): JSONResponse {
		$accessToken = $this->tokenForCurrentUser();
		if ($accessToken instanceof JSONResponse) {
			return $accessToken;
		}

		$preset = WebhookPresets::getPreset($presetId);
		if ($preset === null) {
			return new JSONResponse([
				'success' => false,
				'error' => "Unknown preset: $presetId",
			], Http::STATUS_BAD_REQUEST);
		}

		$mcpServerUrl = $this->client->getServerUrl();
		$callbackUri = $mcpServerUrl . '/webhooks/nextcloud';

		$registered = [];
		$errors = [];
		foreach ($preset['events'] as $eventConfig) {
			$result = $this->client->createWebhook(
				$eventConfig['event'],
				$callbackUri,
				!empty($eventConfig['filter']) ? $eventConfig['filter'] : null,
				$accessToken,
			);

			if (isset($result['error'])) {
				// Bail out immediately on provisioning-required — every
				// subsequent createWebhook would fail identically.
				if (!empty($result['provisioning_required'])) {
					return $this->mcpErrorResponse($result);
				}
				$errors[] = [
					'event' => $eventConfig['event'],
					'error' => $result['error'],
				];
			} else {
				$registered[] = $result;
			}
		}

		if (!empty($errors)) {
			return new JSONResponse([
				'success' => false,
				'error' => 'Failed to register some webhooks',
				'registered' => $registered,
				'errors' => $errors,
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->logger->info("Enabled webhook preset $presetId", [
			'preset_id' => $presetId,
			'webhooks_registered' => count($registered),
		]);

		return new JSONResponse([
			'success' => true,
			'message' => "Enabled {$preset['name']}",
			'webhooks' => $registered,
		]);
	}

	/**
	 * Disable a webhook preset by deleting its registered events. Admin-only.
	 */
	public function disableWebhookPreset(string $presetId): JSONResponse {
		$accessToken = $this->tokenForCurrentUser();
		if ($accessToken instanceof JSONResponse) {
			return $accessToken;
		}

		$preset = WebhookPresets::getPreset($presetId);
		if ($preset === null) {
			return new JSONResponse([
				'success' => false,
				'error' => "Unknown preset: $presetId",
			], Http::STATUS_BAD_REQUEST);
		}

		$webhooksResult = $this->client->listWebhooks($accessToken);
		if (isset($webhooksResult['error'])) {
			return $this->mcpErrorResponse($webhooksResult);
		}
		$registeredWebhooks = $webhooksResult['webhooks'] ?? [];

		// Match BOTH event type AND filter so we don't blow away webhooks
		// belonging to a different preset that happens to share an event
		// type (Notes vs Files both use FILE_EVENT_*).
		$webhooksToDelete = [];
		foreach ($registeredWebhooks as $webhook) {
			foreach ($preset['events'] as $presetEvent) {
				if ($webhook['event'] !== $presetEvent['event']) {
					continue;
				}
				$presetFilter = !empty($presetEvent['filter']) ? $presetEvent['filter'] : null;
				$webhookFilter = !empty($webhook['eventFilter']) ? $webhook['eventFilter'] : null;
				if (json_encode($presetFilter) === json_encode($webhookFilter)) {
					$webhooksToDelete[] = $webhook;
					break;
				}
			}
		}

		$deleted = [];
		$errors = [];
		foreach ($webhooksToDelete as $webhook) {
			$result = $this->client->deleteWebhook($webhook['id'], $accessToken);
			if (isset($result['error'])) {
				if (!empty($result['provisioning_required'])) {
					return $this->mcpErrorResponse($result);
				}
				$errors[] = [
					'webhook_id' => $webhook['id'],
					'event' => $webhook['event'],
					'error' => $result['error'],
				];
			} else {
				$deleted[] = $webhook['id'];
			}
		}

		if (!empty($errors)) {
			return new JSONResponse([
				'success' => false,
				'error' => 'Failed to delete some webhooks',
				'deleted' => $deleted,
				'errors' => $errors,
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->logger->info("Disabled webhook preset $presetId", [
			'preset_id' => $presetId,
			'webhooks_deleted' => count($deleted),
		]);

		return new JSONResponse([
			'success' => true,
			'message' => "Disabled {$preset['name']}",
			'deleted' => $deleted,
		]);
	}

	/**
	 * Get chunk context for visualization.
	 */
	#[NoAdminRequired]
	public function chunkContext(
		string $doc_type,
		string $doc_id,
		int $start,
		int $end,
		?int $chunk_index = null,
		?int $total_chunks = null,
	): JSONResponse {
		$accessToken = $this->tokenForCurrentUser();
		if ($accessToken instanceof JSONResponse) {
			return $accessToken;
		}

		$result = $this->client->getChunkContext(
			$doc_type,
			$doc_id,
			$start,
			$end,
			$accessToken,
			$chunk_index,
			$total_chunks,
		);

		if (isset($result['error'])) {
			return new JSONResponse([
				'success' => false,
				'error' => $result['error'],
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result);
	}

	/**
	 * Server-side rendered PDF page preview.
	 */
	#[NoAdminRequired]
	public function pdfPreview(
		string $file_path,
		int $page = 1,
		float $scale = 2.0,
	): JSONResponse {
		$accessToken = $this->tokenForCurrentUser();
		if ($accessToken instanceof JSONResponse) {
			return $accessToken;
		}

		$result = $this->client->getPdfPreview($file_path, $page, $scale, $accessToken);
		if (isset($result['error'])) {
			return new JSONResponse([
				'success' => false,
				'error' => $result['error'],
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result);
	}

	/**
	 * Translate an MCP client error result into a JSONResponse.
	 *
	 * The MCP server returns 428 (Precondition Required) when the user
	 * has not completed Login Flow v2 background-indexing provisioning.
	 * Surface that as a structured response so the admin UI can render a
	 * "complete authorization" CTA instead of an opaque error.
	 */
	private function mcpErrorResponse(array $result): JSONResponse {
		if (!empty($result['provisioning_required'])) {
			return new JSONResponse([
				'success' => false,
				'error' => $result['error'] ?? 'Nextcloud access not provisioned',
				'provisioning_required' => true,
			], Http::STATUS_PRECONDITION_REQUIRED);
		}

		return new JSONResponse([
			'success' => false,
			'error' => $result['error'] ?? 'Unknown error',
		], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
