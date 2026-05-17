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
		$configured = $this->config->getSystemValue('astrolabe_internal_url', '');
		$internalUrl = is_string($configured) ? trim($configured) : '';

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

		// Scheme allowlist: only http/https are valid for OIDC endpoints.
		// FILTER_VALIDATE_URL accepts file://, ftp://, gopher://, etc.,
		// which would let an admin trigger arbitrary protocol fetches.
		$scheme = parse_url($internalUrl, PHP_URL_SCHEME);
		if ($scheme !== 'http' && $scheme !== 'https') {
			$this->logger->warning(
				'Unsupported scheme in astrolabe_internal_url, falling back to default',
				[
					'configured_url' => $internalUrl,
					'scheme' => $scheme,
				],
			);
			return 'http://localhost';
		}

		// Warn only when a loopback host is paired with a non-default port —
		// that signature usually means the admin pasted the externally-mapped
		// port (e.g. http://localhost:8080) where the internal URL belongs.
		// Kubernetes service URLs like http://nextcloud.default.svc:8080 are
		// legitimate and intentionally not flagged.
		$host = parse_url($internalUrl, PHP_URL_HOST);
		$port = parse_url($internalUrl, PHP_URL_PORT);
		if (($host === 'localhost' || $host === '127.0.0.1')
			&& is_int($port)
			&& $port !== 80
		) {
			$this->logger->warning(
				'astrolabe_internal_url appears to use external port mapping',
				[
					'configured_url' => $internalUrl,
					'hint' => 'For localhost, prefer port 80 (or no port) over mapped ports like :8080',
				],
			);
		}

		return rtrim($internalUrl, '/');
	}
}
