<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service;

use OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent;
use OCA\UserOIDC\Event\ExchangedTokenRequestedEvent;
use OCA\UserOIDC\Event\ExternalTokenRequestedEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Mints short-lived MCP-server access tokens for the current Nextcloud user.
 *
 * Nextcloud can be the IdP or merely a client of one, and Astrolabe has to work
 * either way (GH #324):
 *
 * - **Internal IdP** — the `oidc` app makes Nextcloud the identity provider.
 *   Astrolabe asks it for a token with the `TokenGenerationRequestEvent`.
 * - **External IdP** — the `user_oidc` app makes Nextcloud a client of
 *   Keycloak/Authentik/… . There is no local IdP to mint from, so Astrolabe
 *   asks `user_oidc` for the *user's own* IdP token: exchanged for the MCP
 *   server's audience (RFC 8693) when the IdP supports it, otherwise the login
 *   token as issued.
 *
 * No persistence — every call produces a fresh token from the IdP, then
 * memoizes it for the current request only. The Nextcloud session is the source
 * of identity; there is no OAuth refresh token and no DB row tied to this flow.
 *
 * `astrolabe_client_id` names the OIDC client the token is for: a client
 * registered in the `oidc` app (internal), or the IdP client that is the token
 * exchange's target audience (external).
 */
class McpTokenMinter {
	/** @var array<string, string> request-scoped cache keyed by "uid|scopes" */
	private array $cache = [];

	/** @psalm-suppress PossiblyUnusedMethod — instantiated by the Nextcloud DI container. */
	public function __construct(
		private IEventDispatcher $eventDispatcher,
		private IConfig $config,
		private IAppManager $appManager,
		private IUserSession $userSession,
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
	 *                               neither IdP app is enabled, or the IdP
	 *                               returned no token.
	 */
	public function mintForUser(string $userId, string $extraScopes = ''): string {
		// json_encode (not "uid|scopes") so a UID containing the separator
		// cannot collide with a different (userId, scopes) pair.
		$cacheKey = json_encode([$userId, $extraScopes], JSON_THROW_ON_ERROR);
		if (isset($this->cache[$cacheKey])) {
			return $this->cache[$cacheKey];
		}

		$clientId = (string)$this->config->getSystemValue('astrolabe_client_id', '');
		if ($clientId === '') {
			throw new McpTokenMintException(
				'astrolabe_client_id system config is not set; set it in Nextcloud '
				. 'admin settings -> MCP Server Configuration to the OIDC client '
				. "Astrolabe should mint tokens for (a client registered in the 'oidc' "
				. 'app, or the IdP client the MCP server accepts when using an '
				. 'external identity provider).'
			);
		}

		$token = $this->appManager->isEnabledForUser('oidc')
			? $this->mintFromInternalIdp($userId, $extraScopes, $clientId)
			: $this->mintFromExternalIdp($userId, $extraScopes, $clientId);

		$this->cache[$cacheKey] = $token;
		return $token;
	}

	/**
	 * Internal IdP: the `oidc` app issues a token for one of its own clients.
	 *
	 * @throws McpTokenMintException
	 */
	private function mintFromInternalIdp(string $userId, string $extraScopes, string $clientId): string {
		// Resource indicator (RFC 8707) — becomes the `aud` of the minted
		// token. Use the public URL the MCP server advertises, falling back
		// to the internal URL when no separate public URL is configured.
		$resource = (string)$this->config->getSystemValue('mcp_server_public_url', '');
		if ($resource === '') {
			$resource = (string)$this->config->getSystemValue('mcp_server_url', '');
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

		return $token;
	}

	/**
	 * External IdP: `user_oidc` hands out the session user's own IdP token.
	 *
	 * Both `user_oidc` events act on the *session* user — they read the token
	 * stored when that user logged in through the IdP — so this path can only
	 * serve the currently authenticated user, and only when the session was
	 * created by an OIDC login (not an app password or a local account).
	 *
	 * @throws McpTokenMintException
	 */
	private function mintFromExternalIdp(string $userId, string $extraScopes, string $audience): string {
		if (!$this->appManager->isEnabledForUser('user_oidc')) {
			throw new McpTokenMintException(
				'Astrolabe needs an OIDC integration to authenticate you against the '
				. "MCP server: install the 'oidc' app (Nextcloud as identity provider) "
				. "or the 'user_oidc' app (Nextcloud as a client of an external "
				. 'identity provider).'
			);
		}

		$sessionUser = $this->userSession->getUser();
		if ($sessionUser === null || $sessionUser->getUID() !== $userId) {
			throw new McpTokenMintException(
				'With an external identity provider Astrolabe can only obtain a token '
				. "for the signed-in user; no session token is available for '$userId'."
			);
		}

		/** @var list<string> $scopes user_oidc takes scopes as a list, the `oidc` app as one string */
		$scopes = $extraScopes === '' ? [] : array_values(array_filter(explode(' ', trim($extraScopes))));

		// Preferred: a token minted for the MCP server's audience only.
		// ponytail: an IdP without token exchange logs one user_oidc error per
		// request before the fallback wins. Add an opt-out config if that noise
		// bothers anyone; the mint itself is memoized per request already.
		try {
			$exchange = new ExchangedTokenRequestedEvent($audience, $scopes);
			$this->eventDispatcher->dispatchTyped($exchange);
			$token = $exchange->getToken()?->getAccessToken() ?? '';
			if ($token !== '') {
				return $token;
			}
		} catch (\Throwable $e) {
			// Token exchange is optional in the IdP (and off by default in some).
			// Fall through to the login token rather than failing the request.
			$this->logger->debug('user_oidc token exchange unavailable, falling back to the login token', [
				'audience' => $audience,
				'exception' => $e,
			]);
		}

		// Fallback: the token this user logged in with, as issued. Requires
		// user_oidc's `store_login_token` to be enabled.
		try {
			$external = new ExternalTokenRequestedEvent();
			$this->eventDispatcher->dispatchTyped($external);
			$token = $external->getToken()?->getAccessToken() ?? '';
		} catch (\Throwable $e) {
			$this->logger->error('user_oidc could not provide a token for the session user', [
				'user_id' => $userId,
				'audience' => $audience,
				'exception' => $e,
			]);
			throw new McpTokenMintException(
				'The user_oidc app could not provide an access token for your session: '
				. $e->getMessage()
				. " Enable 'store_login_token' in user_oidc so the identity provider's "
				. 'token is kept for the session.',
				0,
				$e,
			);
		}

		if ($token === '') {
			throw new McpTokenMintException(
				'The user_oidc app returned no access token for your session. Sign in '
				. 'again through the identity provider, and make sure user_oidc keeps '
				. "the login token ('store_login_token')."
			);
		}

		return $token;
	}
}
