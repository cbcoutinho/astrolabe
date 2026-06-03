<?php

declare(strict_types=1);

/**
 * Stubs for internal OC\ classes not available in nextcloud/ocp.
 *
 * These are needed so PHPUnit can create mocks for controllers that
 * depend on internal Nextcloud interfaces.
 */

namespace OC\Authentication\Token {
	if (!interface_exists(IProvider::class)) {
		// Subset of the server-internal token provider Astrolabe uses for
		// admin provisioning (mint + invalidate). Loose signatures mirror the
		// real interface so PHPUnit can mock these methods.
		interface IProvider {
			public function generateToken(string $token, string $uid, string $loginName, ?string $password, string $name, int $type = 1, int $remember = 0, ?array $scope = null);

			public function invalidateToken(string $token);
		}
	}
	if (!interface_exists(IToken::class)) {
		interface IToken {
		}
	}
}

namespace OCA\OIDCIdentityProvider\Event {
	/*
	 * Stub for the OIDC IdentityProvider app's TokenGenerationRequestEvent.
	 * Real implementation: https://github.com/H2CK/oidc/blob/master/lib/Event/TokenGenerationRequestEvent.php
	 *
	 * Mirrors the constructor + getters/setters McpTokenMinter relies on,
	 * so unit tests can instantiate it without the oidc app being present
	 * on disk.
	 */
	if (!class_exists(TokenGenerationRequestEvent::class)) {
		class TokenGenerationRequestEvent extends \OCP\EventDispatcher\Event {
			private ?string $accessToken = null;
			private ?int $expiresIn = null;

			public function __construct(
				private string $clientIdentifier,
				private string $userId,
				private string $extraScopes = '',
				private string $resource = '',
			) {
				parent::__construct();
			}

			public function getClientIdentifier(): string {
				return $this->clientIdentifier;
			}
			public function getUserId(): string {
				return $this->userId;
			}
			public function getExtraScopes(): string {
				return $this->extraScopes;
			}
			public function getResource(): ?string {
				return $this->resource;
			}
			public function getAccessToken(): ?string {
				return $this->accessToken;
			}
			public function setAccessToken(string $accessToken): void {
				$this->accessToken = $accessToken;
			}
			public function getExpiresIn(): ?int {
				return $this->expiresIn;
			}
			public function setExpiresIn(?int $expiresIn): void {
				$this->expiresIn = $expiresIn;
			}
		}
	}
}
