<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service;

use OCP\App\IAppManager;
use OCP\IConfig;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for communicating with the MCP server's management API.
 *
 * This service wraps the MCP server's REST API endpoints defined in ADR-018.
 * It handles authentication via OAuth bearer tokens and provides typed methods
 * for all management operations.
 */
class McpServerClient {
	private ClientInterface $httpClient;
	private RequestFactoryInterface $requestFactory;
	private StreamFactoryInterface $streamFactory;
	private IConfig $config;
	private LoggerInterface $logger;
	private string $baseUrl;
	private string $userAgent;

	public function __construct(
		ClientInterface $httpClient,
		RequestFactoryInterface $requestFactory,
		StreamFactoryInterface $streamFactory,
		IConfig $config,
		LoggerInterface $logger,
		IAppManager $appManager,
	) {
		$this->httpClient = $httpClient;
		$this->requestFactory = $requestFactory;
		$this->streamFactory = $streamFactory;
		$this->config = $config;
		$this->logger = $logger;

		// Get MCP server configuration from Nextcloud config
		$baseUrl = $this->config->getSystemValue('mcp_server_url', 'http://localhost:8000');
		$this->baseUrl = is_string($baseUrl) ? $baseUrl : 'http://localhost:8000';

		// User-Agent identifies this app + version to the MCP server, so backend
		// access logs can see which Astrolabe release is calling them.
		$this->userAgent = 'Nextcloud-Astrolabe/' . $appManager->getAppVersion('astrolabe');
	}

	/**
	 * Merge a Nextcloud-Astrolabe User-Agent header into the request options.
	 *
	 * Use at every outbound HTTP call site so MCP server logs see a stable
	 * client identity instead of Guzzle's default UA.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function withUserAgent(array $options = []): array {
		/** @var array<string, string> $headers */
		$headers = $options['headers'] ?? [];
		$headers['User-Agent'] = $this->userAgent;
		$options['headers'] = $headers;
		return $options;
	}

	/**
	 * Build a PSR-7 request from the small subset of Guzzle-style options the
	 * call sites use ('headers', 'json', 'query') and send it via the PSR-18
	 * client.
	 *
	 * PSR-18 clients return a response for every HTTP status and only throw on
	 * transport errors, so the legacy 'http_errors' option is implicit and
	 * ignored here; callers detect non-2xx via detectErrorResponse() or the
	 * status guard in sendAndDecode().
	 *
	 * @param array<string, mixed> $options
	 */
	private function send(string $method, string $url, array $options = []): ResponseInterface {
		/** @var array<string, scalar> $query */
		$query = $options['query'] ?? [];
		if ($query !== []) {
			$separator = str_contains($url, '?') ? '&' : '?';
			$url .= $separator . http_build_query($query);
		}

		$request = $this->requestFactory->createRequest($method, $url);

		/** @var array<string, string> $headers */
		$headers = $options['headers'] ?? [];
		foreach ($headers as $name => $value) {
			$request = $request->withHeader($name, $value);
		}

		if (array_key_exists('json', $options)) {
			$body = json_encode($options['json'], JSON_THROW_ON_ERROR);
			$request = $request->withBody($this->streamFactory->createStream($body));
			if (!$request->hasHeader('Content-Type')) {
				$request = $request->withHeader('Content-Type', 'application/json');
			}
		}

		return $this->httpClient->sendRequest($request);
	}

	/**
	 * Run an HTTP request closure, optionally route non-2xx responses through
	 * detectErrorResponse(), JSON-decode the body, and turn any failure
	 * (transport, non-2xx, malformed JSON) into a structured ['error' => …]
	 * array. Centralises the try / decode / catch boilerplate that previously
	 * lived in every public method.
	 *
	 * @param callable(): ResponseInterface $request HTTP request closure
	 * @param string $errorMessage Log message on failure
	 * @param array<string, mixed> $logContext Extra fields merged into the log entry
	 * @param bool $usesErrorDetection When true, runs non-2xx responses through detectErrorResponse()
	 * @return array<string, mixed>
	 *
	 * @psalm-suppress MixedReturnTypeCoercion - body returns dynamic json_decode shape; callers narrow via their own @return.
	 */
	private function sendAndDecode(
		callable $request,
		string $errorMessage,
		array $logContext = [],
		bool $usesErrorDetection = false,
	): array {
		try {
			$response = $request();
			if ($usesErrorDetection) {
				$errorResult = $this->detectErrorResponse($response);
				if ($errorResult !== null) {
					return $errorResult;
				}
			} else {
				// PSR-18 clients don't throw on HTTP status, so preserve the
				// prior http_errors=true behaviour for callers that don't run
				// their own error detection.
				$statusCode = $response->getStatusCode();
				if ($statusCode < 200 || $statusCode >= 300) {
					throw new \RuntimeException("Unexpected HTTP $statusCode from MCP server");
				}
			}
			/** @var mixed $data */
			$data = json_decode((string)$response->getBody(), true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new \RuntimeException('Invalid JSON response from server');
			}
			return is_array($data) ? $data : [];
		} catch (\Exception $e) {
			$this->logger->error($errorMessage, ['error' => $e->getMessage()] + $logContext);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Convert a non-2xx response into a structured error array.
	 *
	 * Webhook-related methods set ``http_errors => false`` on the Guzzle
	 * client so they can intercept HTTP 428 (Precondition Required) — the
	 * MCP server returns that when the user has not completed Login Flow v2
	 * provisioning, and the controller maps it to a "complete authorization"
	 * CTA. Once exceptions are disabled, every other non-2xx response would
	 * silently fall through to ``json_decode`` and be treated as success, so
	 * this helper turns any non-2xx into an explicit error array.
	 *
	 * 428 → ``provisioning_required => true`` (mapped to a CTA in the UI).
	 * Any other non-2xx → generic error with the status code in the message.
	 * 2xx → null (caller continues with the success path).
	 *
	 * @return array{error: string, provisioning_required?: true}|null
	 */
	private function detectErrorResponse(ResponseInterface $response): ?array {
		$statusCode = $response->getStatusCode();

		if ($statusCode === 428) {
			return [
				'error' => $this->extractProvisioningMessage($response),
				'provisioning_required' => true,
			];
		}

		if ($statusCode < 200 || $statusCode >= 300) {
			return ['error' => "Unexpected HTTP $statusCode from MCP server"];
		}

		return null;
	}

	/**
	 * Pull the human-readable message out of a 428 response body, falling
	 * back to a generic "complete authorization" string if the body is not
	 * a JSON object or has no ``message`` field.
	 *
	 * @psalm-suppress MixedAssignment $rawMessage is intentionally mixed
	 *                  until is_string narrows it to a string below.
	 */
	private function extractProvisioningMessage(ResponseInterface $response): string {
		$default = 'Nextcloud access not provisioned. Complete authorization in Personal Settings.';

		$decoded = json_decode((string)$response->getBody(), true);
		if (!is_array($decoded)) {
			return $default;
		}

		$rawMessage = $decoded['message'] ?? null;
		return is_string($rawMessage) ? $rawMessage : $default;
	}

	/**
	 * Get server status (version, auth mode, features).
	 *
	 * Public endpoint - no authentication required.
	 *
	 * @return array{
	 *   version?: string,
	 *   auth_mode?: string,
	 *   vector_sync_enabled?: bool,
	 *   uptime_seconds?: int,
	 *   management_api_version?: string,
	 *   error?: string
	 * }
	 *
	 * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement - sendAndDecode returns array<string, mixed>; runtime shape comes from MCP server JSON.
	 */
	public function getStatus(): array {
		return $this->sendAndDecode(
			fn (): ResponseInterface => $this->send('GET',
				$this->baseUrl . '/api/v1/status',
				$this->withUserAgent(),
			),
			'Failed to get MCP server status',
			['server_url' => $this->baseUrl],
		);
	}


	/**
	 * Get vector sync status (indexing metrics).
	 *
	 * Public endpoint - no authentication required.
	 * Only available if VECTOR_SYNC_ENABLED=true on server.
	 *
	 * @return array{
	 *   status?: string,
	 *   indexed_documents?: int,
	 *   pending_documents?: int,
	 *   last_sync_time?: string,
	 *   documents_per_second?: float,
	 *   errors_24h?: int,
	 *   error?: string
	 * }
	 *
	 * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement - sendAndDecode returns array<string, mixed>; runtime shape comes from MCP server JSON.
	 */
	public function getVectorSyncStatus(): array {
		return $this->sendAndDecode(
			fn (): ResponseInterface => $this->send('GET',
				$this->baseUrl . '/api/v1/vector-sync/status',
				$this->withUserAgent(),
			),
			'Failed to get vector sync status',
		);
	}

	/**
	 * Execute semantic search for vector visualization.
	 *
	 * Requires OAuth bearer token for user-filtered search.
	 * Only available if VECTOR_SYNC_ENABLED=true on server.
	 *
	 * @param string $query Search query string
	 * @param string $algorithm Search algorithm: "semantic", "bm25", or "hybrid"
	 * @param int $limit Number of results (max 50)
	 * @param bool $includePca Whether to include PCA coordinates for 2D plot
	 * @param array|null $docTypes Document types to filter (e.g., ['note', 'file'])
	 * @param string|null $token OAuth bearer token for authentication
	 * @param string|null $modifiedAfter RFC 3339 lower bound on last-modified (open if null)
	 * @param string|null $modifiedBefore RFC 3339 upper bound on last-modified (open if null)
	 * @param list<string>|null $pathPrefixes Folder filters (files only), OR-ed; no filter if null/empty
	 * @return array{
	 *   results?: array,
	 *   pca_coordinates?: array,
	 *   algorithm_used?: string,
	 *   total_documents?: int,
	 *   error?: string
	 * }
	 *
	 * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement - sendAndDecode returns array<string, mixed>; runtime shape comes from MCP server JSON.
	 */
	public function search(
		string $query,
		string $algorithm = 'hybrid',
		int $limit = 10,
		bool $includePca = true,
		?array $docTypes = null,
		?string $token = null,
		?string $modifiedAfter = null,
		?string $modifiedBefore = null,
		?array $pathPrefixes = null,
	): array {
		$requestBody = [
			'query' => $query,
			'algorithm' => $algorithm,
			'limit' => min($limit, 50), // Enforce max limit
			'include_pca' => $includePca,
		];

		// Add doc_types filter if specified
		if ($docTypes !== null && count($docTypes) > 0) {
			$requestBody['doc_types'] = $docTypes;
		}

		// ADR-027 modified-date range filter. Sent as RFC 3339 / ISO 8601
		// strings; the MCP server normalizes them to int Unix seconds for the
		// numeric Range filter. Omitted bounds stay open-ended.
		if ($modifiedAfter !== null && $modifiedAfter !== '') {
			$requestBody['modified_after'] = $modifiedAfter;
		}
		if ($modifiedBefore !== null && $modifiedBefore !== '') {
			$requestBody['modified_before'] = $modifiedBefore;
		}

		// ADR-027 Phase 2 path filter (files only). Sent as a list; the MCP
		// server ORs the folders, matching each against the file_path payload
		// (MatchText). Omitted/empty ⇒ no path filter.
		if ($pathPrefixes !== null && count($pathPrefixes) > 0) {
			$requestBody['path_prefixes'] = $pathPrefixes;
		}

		$options = ['json' => $requestBody];

		// Add authorization header if token provided
		if ($token !== null) {
			$options['headers'] = [
				'Authorization' => 'Bearer ' . $token,
			];
		}

		return $this->sendAndDecode(
			fn (): ResponseInterface => $this->send('POST',
				$this->baseUrl . '/api/v1/vector-viz/search',
				$this->withUserAgent($options),
			),
			'Failed to execute search',
			['query' => $query, 'algorithm' => $algorithm],
		);
	}

	/**
	 * Execute semantic search for Nextcloud Unified Search.
	 *
	 * Simplified search method specifically for the unified search provider.
	 * Uses OAuth bearer token for authentication and user-scoped filtering.
	 *
	 * @param string $query Search query string
	 * @param string $token OAuth bearer token for authentication
	 * @param int $limit Maximum number of results (default: 20)
	 * @param int $offset Pagination offset (default: 0)
	 * @param string $algorithm Search algorithm: hybrid, semantic, or bm25 (default: hybrid)
	 * @param string $fusion Fusion method for hybrid: rrf or dbsf (default: rrf)
	 * @param float $scoreThreshold Minimum score threshold 0-1 (default: 0)
	 * @return array{
	 *   results?: array<array{
	 *     id?: string|int,
	 *     title?: string,
	 *     doc_type?: string,
	 *     excerpt?: string,
	 *     score?: float,
	 *     path?: string,
	 *     board_id?: int,
	 *     card_id?: int
	 *   }>,
	 *   total_found?: int,
	 *   algorithm_used?: string,
	 *   error?: string
	 * }
	 *
	 * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement - sendAndDecode returns array<string, mixed>; runtime shape comes from MCP server JSON.
	 */
	public function searchForUnifiedSearch(
		string $query,
		string $token,
		int $limit = 20,
		int $offset = 0,
		string $algorithm = 'hybrid',
		string $fusion = 'rrf',
		float $scoreThreshold = 0.0,
	): array {
		return $this->sendAndDecode(
			fn (): ResponseInterface => $this->send('POST',
				$this->baseUrl . '/api/v1/search',
				$this->withUserAgent([
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						'Content-Type' => 'application/json',
					],
					'json' => [
						'query' => $query,
						'algorithm' => $algorithm,
						'fusion' => $fusion,
						'score_threshold' => $scoreThreshold,
						'limit' => min($limit, 100),
						'offset' => $offset,
						'include_pca' => false,
						'include_chunks' => true,
					],
				]),
			),
			'Unified search failed',
			['query' => $query],
		);
	}

	/**
	 * Check if the MCP server is reachable and healthy.
	 *
	 * @return bool True if server is reachable and healthy
	 */
	public function isServerReachable(): bool {
		$status = $this->getStatus();
		return !isset($status['error']);
	}

	/**
	 * Get the configured MCP server internal URL (for API calls).
	 *
	 * @return string The internal base URL
	 */
	public function getServerUrl(): string {
		return $this->baseUrl;
	}

	/**
	 * Get the public MCP server URL (for display, OAuth audience).
	 *
	 * Falls back to internal URL if public URL not configured.
	 *
	 * @return string The public URL users/browsers see
	 */
	public function getPublicServerUrl(): string {
		// getSystemValue's default only applies when the key is *absent*; a key
		// present with an empty-string value returns '', not $this->baseUrl. So
		// fall back explicitly on empty, mirroring McpTokenMinter's resource
		// resolution.
		$publicUrl = (string)$this->config->getSystemValue('mcp_server_public_url', '');
		return $publicUrl !== '' ? $publicUrl : $this->baseUrl;
	}


	/**
	 * List all registered webhooks for a user.
	 *
	 * Requires OAuth bearer token for authentication.
	 *
	 * @param string $token OAuth bearer token
	 * @return array{
	 *   webhooks?: array<array{
	 *     id?: int,
	 *     event?: string,
	 *     uri?: string,
	 *     event_filter?: array,
	 *     enabled?: bool
	 *   }>,
	 *   error?: string,
	 *   provisioning_required?: true
	 * }
	 *
	 * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement - sendAndDecode returns array<string, mixed>; runtime shape comes from MCP server JSON.
	 */
	public function listWebhooks(string $token): array {
		return $this->sendAndDecode(
			fn (): ResponseInterface => $this->send('GET',
				$this->baseUrl . '/api/v1/webhooks',
				$this->withUserAgent([
					'headers' => ['Authorization' => 'Bearer ' . $token],
					'http_errors' => false,
				]),
			),
			'Failed to list webhooks',
			usesErrorDetection: true,
		);
	}

	/**
	 * Create a new webhook registration.
	 *
	 * Requires OAuth bearer token for authentication.
	 *
	 * @param string $event Event type (e.g., "\\OCA\\Files::postCreate")
	 * @param string $uri Callback URI for webhook notifications
	 * @param array|null $eventFilter Optional event filter parameters
	 * @param string $token OAuth bearer token
	 * @return array{
	 *   id?: int,
	 *   event?: string,
	 *   uri?: string,
	 *   event_filter?: array,
	 *   enabled?: bool,
	 *   error?: string,
	 *   provisioning_required?: true
	 * }
	 *
	 * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement - sendAndDecode returns array<string, mixed>; runtime shape comes from MCP server JSON.
	 */
	public function createWebhook(
		string $event,
		string $uri,
		?array $eventFilter,
		string $token,
	): array {
		$requestBody = [
			'event' => $event,
			'uri' => $uri,
		];

		if ($eventFilter !== null) {
			$requestBody['event_filter'] = $eventFilter;
		}

		return $this->sendAndDecode(
			fn (): ResponseInterface => $this->send('POST',
				$this->baseUrl . '/api/v1/webhooks',
				$this->withUserAgent([
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						'Content-Type' => 'application/json',
					],
					'json' => $requestBody,
					'http_errors' => false,
				]),
			),
			'Failed to create webhook',
			['event' => $event],
			usesErrorDetection: true,
		);
	}

	/**
	 * Delete a webhook registration.
	 *
	 * Requires OAuth bearer token for authentication.
	 *
	 * @param int $webhookId Webhook ID to delete
	 * @param string $token OAuth bearer token
	 * @return array{success?: bool, error?: string, provisioning_required?: true}
	 */
	public function deleteWebhook(int $webhookId, string $token): array {
		try {
			$response = $this->send(
				'DELETE',
				$this->baseUrl . '/api/v1/webhooks/' . $webhookId,
				$this->withUserAgent([
					'headers' => [
						'Authorization' => 'Bearer ' . $token
					],
				])
			);

			$errorResult = $this->detectErrorResponse($response);
			if ($errorResult !== null) {
				return $errorResult;
			}

			// Successful DELETE may return 204 No Content
			if ($response->getStatusCode() === 204) {
				return ['success' => true];
			}

			/** @var mixed $data */
			$data = json_decode((string)$response->getBody(), true);

			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new \RuntimeException('Invalid JSON response from server');
			}

			// A body of literal "null" decodes to null (valid JSON), so guard
			// like sendAndDecode() does — callers index into the return array.
			if (!is_array($data)) {
				return [];
			}
			/** @var array{success?: bool, error?: string, provisioning_required?: true} $data */
			return $data;
		} catch (\Exception $e) {
			$this->logger->error('Failed to delete webhook', [
				'error' => $e->getMessage(),
				'webhook_id' => $webhookId,
			]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Get list of installed Nextcloud apps.
	 *
	 * Used to filter webhook presets based on available apps.
	 * Requires OAuth bearer token for authentication.
	 *
	 * @param string $token OAuth bearer token
	 * @return array{
	 *   apps?: array<string>,
	 *   error?: string,
	 *   provisioning_required?: true
	 * }
	 *
	 * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement - sendAndDecode returns array<string, mixed>; runtime shape comes from MCP server JSON.
	 */
	public function getInstalledApps(string $token): array {
		return $this->sendAndDecode(
			fn (): ResponseInterface => $this->send('GET',
				$this->baseUrl . '/api/v1/apps',
				$this->withUserAgent([
					'headers' => ['Authorization' => 'Bearer ' . $token],
					'http_errors' => false,
				]),
			),
			'Failed to get installed apps',
			usesErrorDetection: true,
		);
	}

	/**
	 * Get chunk context (text, surrounding context, page image).
	 *
	 * Requires OAuth bearer token for authentication.
	 *
	 * @param string $docType Document type
	 * @param string $docId Document ID
	 * @param int $start Start offset
	 * @param int $end End offset
	 * @param string $token OAuth bearer token
	 * @param int|null $chunkIndex Zero-based chunk index (optional). When
	 *                             provided, the MCP server uses the always-indexed chunk_index field
	 *                             for lookup instead of the offset filter.
	 * @param int|null $totalChunks Total chunks in document (optional)
	 * @return array
	 */
	public function getChunkContext(
		string $docType,
		string $docId,
		int $start,
		int $end,
		string $token,
		?int $chunkIndex = null,
		?int $totalChunks = null,
	): array {
		$query = [
			'doc_type' => $docType,
			'doc_id' => $docId,
			'start' => $start,
			'end' => $end,
			'context' => 500,
		];
		if ($chunkIndex !== null) {
			$query['chunk_index'] = $chunkIndex;
		}
		if ($totalChunks !== null) {
			$query['total_chunks'] = $totalChunks;
		}

		return $this->sendAndDecode(
			fn (): ResponseInterface => $this->send('GET',
				$this->baseUrl . '/api/v1/chunk-context',
				$this->withUserAgent([
					'headers' => ['Authorization' => 'Bearer ' . $token],
					'query' => $query,
				]),
			),
			'Failed to get chunk context',
			['doc_type' => $docType, 'doc_id' => $docId],
		);
	}

	/**
	 * Get PDF page preview (server-side rendered).
	 *
	 * Renders a PDF page to PNG using PyMuPDF on the server.
	 * This avoids client-side PDF.js issues with CSP and ES private fields.
	 *
	 * Requires OAuth bearer token for authentication.
	 *
	 * @param string $filePath WebDAV path to PDF file
	 * @param int $page Page number (1-indexed)
	 * @param float $scale Zoom factor (default: 2.0)
	 * @param string $token OAuth bearer token
	 * @return array{
	 *   success?: bool,
	 *   image?: string,
	 *   page_number?: int,
	 *   total_pages?: int,
	 *   error?: string
	 * }
	 */
	public function getPdfPreview(
		string $filePath,
		int $page,
		float $scale,
		string $token,
	): array {
		try {
			$response = $this->send(
				'GET',
				$this->baseUrl . '/api/v1/pdf-preview',
				$this->withUserAgent([
					'headers' => [
						'Authorization' => 'Bearer ' . $token
					],
					'query' => [
						'file_path' => $filePath,
						'page' => $page,
						'scale' => $scale,
					]
				])
			);

			// PSR-18 clients don't throw on HTTP status; turn non-2xx into an
			// error here, mirroring the prior http_errors=true behaviour.
			$statusCode = $response->getStatusCode();
			if ($statusCode < 200 || $statusCode >= 300) {
				throw new \RuntimeException("Unexpected HTTP $statusCode from MCP server");
			}

			/** @var array{success?: bool, image?: string, page_number?: int, total_pages?: int, error?: string} $data */
			$data = json_decode((string)$response->getBody(), true);

			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new \RuntimeException('Invalid JSON response from server');
			}

			return $data;
		} catch (\Exception $e) {
			$this->logger->error('Failed to get PDF preview', [
				'error' => $e->getMessage(),
				'file_path' => $filePath,
				'page' => $page,
			]);
			return ['error' => $e->getMessage()];
		}
	}
}
