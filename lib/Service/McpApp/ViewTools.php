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
	/** Longest view URI accepted: each distinct URI costs a lookup and a cache entry. */
	public const MAX_URI = 512;
	/**
	 * Short: a server upgrade or a changed scope grant shows up within minutes.
	 * The key is the user and URI, not their grant, so for up to this long a
	 * user who lost a scope still sees the tool, whose call then fails at the
	 * IdP (every token is capped there), and one who gained it waits.
	 */
	private const TTL = 300;
	/** An empty answer is kept briefly: an upgrade shows up fast, a flood does not reconnect. */
	private const EMPTY_TTL = 30;
	/** Room for a tool title in the host's confirmation. */
	private const MAX_TITLE = 80;
	/** An OAuth scope token as the MCP server names them (e.g. "files.read"). */
	private const SCOPE_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\z/';

	private ICache $cache;

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private McpClientFactory $clientFactory,
		ICacheFactory $cacheFactory,
	) {
		// Bump the version when the entry shape changes; isEntries() drops a stale one anyway.
		$this->cache = $cacheFactory->createDistributed('astrolabe-mcp-app-tools-v1');
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
		if (self::isEntries($cached)) {
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
		// cached only briefly, so a server upgrade takes effect within seconds.
		$this->cache->set($key, $tools, $tools === [] ? self::EMPTY_TTL : self::TTL);
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
				'title' => mb_substr(self::title($tool), 0, self::MAX_TITLE),
			];
		}
		return $tools;
	}

	/** The first non-empty of the tool's title, its annotations' title and its name. */
	private static function title(Tool $tool): string {
		foreach ([$tool->title, $tool->annotations?->title] as $title) {
			if ($title !== null && $title !== '') {
				return $title;
			}
		}
		return $tool->name;
	}

	/**
	 * Whether a cached value has the shape forView() returns, so an entry from
	 * an older shape (or anything else in the cache) is looked up again
	 * instead of failing later.
	 *
	 * @psalm-assert-if-true array<string, array{scopes: string, write: bool, title: string}> $value
	 */
	public static function isEntries(mixed $value): bool {
		if (!is_array($value)) {
			return false;
		}
		foreach ($value as $name => $tool) {
			if (!is_string($name) || !is_array($tool)
				|| !is_string($tool['scopes'] ?? null) || !is_bool($tool['write'] ?? null) || !is_string($tool['title'] ?? null)) {
				return false;
			}
		}
		return true;
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
