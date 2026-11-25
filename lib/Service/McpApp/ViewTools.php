<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\McpApp;

use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Tool;
use OCA\Astrolabe\Service\Mcp\McpClientFactory;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * The tools a ui:// view may call through the host, discovered from the MCP
 * server rather than listed here.
 *
 * The server publishes the scopes each viewer tool needs in its
 * `_meta["nextcloud-mcp/scopes"]`, and the union on the view resource. tools/list
 * hides a tool whose scopes the token lacks, so the view's tools are listed with
 * a token requesting that union; the IdP narrows it to what this user may have,
 * which is how a user without files.write never gets apply_changes. Each call is
 * then made with a token for that tool's own scopes.
 *
 * Trust: this trusts the configured MCP server to say which of its tools a
 * view may call and which scopes they need, as it is already trusted with
 * the user's tokens and data. The host's own guarantees do not depend on it:
 * the IdP caps every token at the user's grant and the Astrolabe client's
 * allowed scopes, scopes must be well-formed, only tools bound to this view
 * and visible to apps are reachable, and a tool not declared read-only waits
 * for the user's confirmation.
 *
 * @psalm-suppress ClassMustBeFinal — kept non-final so the controller tests can mock it.
 */
class ViewTools {
	public const SCOPES_META_KEY = 'nextcloud-mcp/scopes';
	/** Short: a server upgrade or a changed scope grant shows up within minutes. */
	private const TTL = 300;
	/** An OAuth scope token as the MCP server names them (e.g. "files.read"). */
	private const SCOPE_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\z/';

	private ICache $cache;

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private McpClientFactory $clientFactory,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('astrolabe-mcp-app-tools');
	}

	/**
	 * Name => what calling it needs, for the tools `$uri` may call as `$userId`.
	 *
	 * @return array<string, array{scopes: string, write: bool, title: string}>
	 * @throws \Throwable when the MCP server cannot be reached; nothing is cached then
	 */
	public function forView(string $userId, string $uri): array {
		$key = hash('sha256', json_encode([$userId, $uri], JSON_THROW_ON_ERROR));
		/** @var mixed $cached */
		$cached = $this->cache->get($key);
		if (is_array($cached)) {
			/** @var array<string, array{scopes: string, write: bool, title: string}> $cached */
			return $cached;
		}
		// The resource needs no scope; its _meta names the ones to list with.
		$client = $this->clientFactory->connect($userId, '', true);
		try {
			$contents = $client->readResource($uri)->contents[0] ?? null;
		} finally {
			$client->disconnect();
		}
		$scopes = self::scopes($contents instanceof TextResourceContents ? $contents->meta : null);
		$tools = [];
		if ($scopes !== '') {
			$client = $this->clientFactory->connect($userId, $scopes, true);
			try {
				$tools = self::fromListing($client->listTools()->tools, $uri);
			} finally {
				$client->disconnect();
			}
		}
		// An empty answer (an older server, or none of its tools reachable) is
		// not cached, so a server upgrade takes effect on the next call.
		if ($tools !== []) {
			$this->cache->set($key, $tools, self::TTL);
		}
		return $tools;
	}

	/**
	 * The tools in a tools/list result that `$uri` may call: bound to that view,
	 * visible to apps, and publishing their scopes. A tool without published
	 * scopes is left out (fail closed): there is no token to mint for it.
	 *
	 * @param array<array-key, Tool> $listed
	 * @return array<string, array{scopes: string, write: bool, title: string}>
	 */
	public static function fromListing(array $listed, string $uri): array {
		$tools = [];
		foreach ($listed as $tool) {
			$ui = $tool->meta['ui'] ?? null;
			if (!is_array($ui) || ($ui['resourceUri'] ?? null) !== $uri) {
				continue;
			}
			// MCP Apps: no visibility means ["model", "app"].
			$visibility = $ui['visibility'] ?? ['model', 'app'];
			if (!is_array($visibility) || !in_array('app', $visibility, true)) {
				continue;
			}
			$scopes = self::scopes($tool->meta);
			if ($scopes === '') {
				continue;
			}
			// Anything not declared read-only is a write, which the host confirms.
			$tools[$tool->name] = [
				'scopes' => $scopes,
				'write' => $tool->annotations?->readOnlyHint !== true,
				// What the host names in its confirmation.
				'title' => $tool->title ?? $tool->annotations?->title ?? $tool->name,
			];
		}
		return $tools;
	}

	/**
	 * The published scopes as a space-separated list, or '' when there are none
	 * or any is malformed (all or nothing: a half-read list would mint the wrong token).
	 *
	 * @param array<array-key, mixed>|null $meta
	 */
	public static function scopes(?array $meta): string {
		$scopes = $meta[self::SCOPES_META_KEY] ?? null;
		if (!is_array($scopes) || $scopes === []) {
			return '';
		}
		foreach ($scopes as $scope) {
			if (!is_string($scope) || preg_match(self::SCOPE_PATTERN, $scope) !== 1) {
				return '';
			}
		}
		/** @var list<string> $scopes */
		return implode(' ', array_values(array_unique($scopes)));
	}
}
