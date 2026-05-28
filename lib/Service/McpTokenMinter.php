<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service;

use OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Mints short-lived MCP-server access tokens for the current Nextcloud
 * user by dispatching the Nextcloud `oidc` app's
 * TokenGenerationRequestEvent.
 *
 * No persistence — every call produces a fresh token from the IdP, then
 * memoizes it for the current request only. The Nextcloud session cookie
 * is the source of identity; there is no OAuth refresh token, no
 * `offline_access` scope, and no DB row tied to this flow.
 *
 * Requires the `oidc` app to be installed and an OIDC client registered
 * there whose identifier is stored in the `astrolabe_client_id` system
 * config. The MCP server's public URL becomes the `aud` claim
 * (RFC 9068 / RFC 8707 resource indicator).
 */
class McpTokenMinter {
	/** @var array<string, string> request-scoped cache keyed by "uid|scopes" */
	private array $cache = [];

	/** @psalm-suppress PossiblyUnusedMethod — instantiated by the Nextcloud DI container. */
	public function __construct(
		private IEventDispatcher $eventDispatcher,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Mint an access token for the given Nextcloud user.
	 *
	 * @param string $userId Nextcloud user ID (typically from
	 *                       IUserSession::getUser()->getUID()).
	 * @param string $extraScopes Optional extra scopes appended to the
	 *                            client's default scope list. Empty in
	 *                            normal call sites.
	 * @return string Bearer access token, ready to send to the MCP server.
	 * @throws McpTokenMintException If the client identifier is missing,
	 *                               the `oidc` app is not loaded, or the listener returned no
	 *                               token (e.g. client expired, client not found in `oidc`).
	 */
	public function mintForUser(string $userId, string $extraScopes = ''): string {
		$cacheKey = $userId . '|' . $extraScopes;
		if (isset($this->cache[$cacheKey])) {
			return $this->cache[$cacheKey];
		}

		$clientId = (string)$this->config->getSystemValue('astrolabe_client_id', '');
		if ($clientId === '') {
			throw new McpTokenMintException(
				'astrolabe_client_id system config is not set; '
				. "register an OIDC client in the 'oidc' app and copy its identifier "
				. 'into Nextcloud admin settings -> MCP Server Configuration.'
			);
		}

		// Resource indicator (RFC 8707) — becomes the `aud` of the minted
		// token. Use the public URL the MCP server advertises, falling back
		// to the internal URL when no separate public URL is configured.
		$resource = (string)$this->config->getSystemValue('mcp_server_public_url', '');
		if ($resource === '') {
			$resource = (string)$this->config->getSystemValue('mcp_server_url', '');
		}

		if (!class_exists(TokenGenerationRequestEvent::class)) {
			throw new McpTokenMintException(
				"The Nextcloud 'oidc' app is not installed or not loaded; "
				. 'Astrolabe needs it to mint MCP-server access tokens.'
			);
		}

		$event = new TokenGenerationRequestEvent(
			$clientId,
			$userId,
			$extraScopes,
			$resource,
		);
		$this->eventDispatcher->dispatchTyped($event);

		$token = $event->getAccessToken();
		if ($token === null || $token === '') {
			$this->logger->error('OIDC TokenGenerationRequestEvent returned no token', [
				'user_id' => $userId,
				'client_id' => $clientId,
				'resource' => $resource,
			]);
			throw new McpTokenMintException(
				"The 'oidc' app did not issue a token for client '$clientId'. "
				. 'Verify the client exists in the OIDC app, the client has not '
				. 'expired (for DCR clients), and the resource URL is correct.'
			);
		}

		$this->cache[$cacheKey] = $token;
		return $token;
	}
}
