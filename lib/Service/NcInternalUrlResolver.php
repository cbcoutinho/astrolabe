<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Resolves the base URL Astrolabe uses for server-to-server requests to
 * this Nextcloud's OIDC endpoints (e.g. /.well-known/openid-configuration
 * and /apps/oidc/token).
 *
 * Centralizes the rule shared between OauthController and
 * IdpTokenRefresher so both legs of the OAuth round-trip resolve to the
 * same host.
 *
 * Priority:
 *   1. astrolabe_internal_url (admin-configurable override)
 *   2. http://localhost (self-hosted/Docker default, where PHP and the
 *      web server share a host)
 *
 * Managed Nextcloud deployments must set the override because there is
 * no local web server to reach via localhost.
 */
class NcInternalUrlResolver {
	public function __construct(
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Resolve the internal base URL.
	 *
	 * Whitespace is trimmed before validation so a trailing space in
	 * config.php does not silently fall back to localhost. Returns the
	 * URL with any trailing slash removed.
	 *
	 * @return string Base URL (no trailing slash)
	 */
	public function resolve(): string {
		$raw = $this->config->getSystemValue('astrolabe_internal_url', '');
		$internalUrl = is_string($raw) ? trim($raw) : '';

		if ($internalUrl === '') {
			return 'http://localhost';
		}

		if (!filter_var($internalUrl, FILTER_VALIDATE_URL)) {
			$this->logger->warning(
				'Invalid astrolabe_internal_url format, falling back to default',
				['configured_url' => $internalUrl],
			);
			return 'http://localhost';
		}

		// High-numbered ports usually indicate an external port mapping
		// (e.g. :8080) accidentally configured as the internal URL.
		if (preg_match('/:\d{4,5}$/', $internalUrl)) {
			$this->logger->warning(
				'astrolabe_internal_url appears to use external port mapping',
				[
					'configured_url' => $internalUrl,
					'hint' => 'Internal URLs should use port 80, not mapped ports like :8080',
				],
			);
		}

		return rtrim($internalUrl, '/');
	}
}
