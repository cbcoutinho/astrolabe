<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Controller;

use OCA\Astrolabe\Service\IdpTokenRefresher;
use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\McpTokenStorage;
use OCA\Astrolabe\Service\WebhookPresets;
use OCA\Astrolabe\Settings\Admin as AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * API controller for MCP Server UI.
 *
 * Handles form submissions and AJAX requests from settings panels.
 */
class ApiController extends Controller {
	/**
	 * Canonical user-facing message for the "MCP server authorization
	 * required" failure mode. Used by every endpoint that bails out when
	 * ``$this->tokenStorage->getAccessToken()`` returns null, so the
	 * banner the user sees is identical regardless of which endpoint
	 * tripped it.
	 */
	private const AUTH_REQUIRED_MESSAGE
		= 'MCP server authorization required. Please authorize the app first.';

	private McpServerClient $client;
	private IUserSession $userSession;
	private IURLGenerator $urlGenerator;
	private LoggerInterface $logger;
	private McpTokenStorage $tokenStorage;
	private IConfig $config;
	private IdpTokenRefresher $tokenRefresher;
	private IGroupManager $groupManager;

	public function __construct(
		string $appName,
		IRequest $request,
		McpServerClient $client,
		IUserSession $userSession,
		IURLGenerator $urlGenerator,
		LoggerInterface $logger,
		McpTokenStorage $tokenStorage,
		IConfig $config,
		IdpTokenRefresher $tokenRefresher,
		IGroupManager $groupManager,
	) {
		parent::__construct($appName, $request);
		$this->client = $client;
		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
		$this->tokenStorage = $tokenStorage;
		$this->config = $config;
		$this->tokenRefresher = $tokenRefresher;
		$this->groupManager = $groupManager;
	}

	/**
	 * Build the "MCP server authorization required" JSON body, optionally
	 * including the refresh-failure reason captured by IdpTokenRefresher.
	 *
	 * The reason is only included for admin users — it can contain bits of
	 * the IdP's response body (see IdpTokenRefresher::$lastError docblock),
	 * which we don't want surfaced to every user. For non-admin users the
	 * body remains the generic message they already get today.
	 *
	 * The detail goes in a separate `refresh_error` key rather than being
	 * concatenated into `error`. The frontend keeps the short message in
	 * its main banner and renders `refresh_error` as a secondary line, so
	 * the primary UI stays compact even when the reason is long.
	 */
	private function authRequiredBody(string $message, ?string $userId): array {
		$body = [
			'success' => false,
			'error' => $message,
		];

		if ($userId !== null && $this->groupManager->isAdmin($userId)) {
			$refreshError = $this->tokenRefresher->getLastError();
			if ($refreshError !== null) {
				$body['refresh_error'] = $refreshError;
			}
		}

		return $body;
	}

	/**
	 * Build a 401 JSONResponse for the "MCP server authorization required"
	 * failure mode. Wraps authRequiredBody() so endpoints can return the
	 * canonical unauthorized response in one statement, which keeps the
	 * six getAccessToken()-null branches in this controller structurally
	 * identical and well below SonarCloud's duplication threshold.
	 */
	private function unauthorizedResponse(string $message, ?string $userId): JSONResponse {
		return new JSONResponse(
			$this->authRequiredBody($message, $userId),
			Http::STATUS_UNAUTHORIZED
		);
	}

	/**
	 * Build the refresh callback passed to McpTokenStorage::getAccessToken().
	 *
	 * Every endpoint that talks to the MCP server needs the same closure:
	 * call the IdP, propagate the failure (null) as-is, and on success
	 * normalize the returned shape with sane defaults for the optional
	 * ``refresh_token`` (IdP may rotate or omit it) and ``expires_in``.
	 * Centralizing it here keeps the six endpoints structurally identical
	 * and below SonarCloud's duplication threshold.
	 *
	 * @return callable(string): ?array{access_token: string, refresh_token: string, expires_in: int}
	 */
	private function makeRefreshCallback(): callable {
		return function (string $refreshToken): ?array {
			$newTokenData = $this->tokenRefresher->refreshAccessToken($refreshToken);

			if ($newTokenData === null) {
				return null;
			}

			// Array values come back as mixed; pin the types here so
			// the closure's declared return shape (consumed by
			// McpTokenStorage::getAccessToken) is honored without
			// Psalm flagging a MixedReturnTypeCoercion.
			/** @var string $accessToken */
			$accessToken = $newTokenData['access_token'];
			/** @var string $newRefreshToken */
			$newRefreshToken = $newTokenData['refresh_token'] ?? $refreshToken;
			$expiresIn = (int)($newTokenData['expires_in'] ?? 3600);

			return [
				'access_token' => $accessToken,
				'refresh_token' => $newRefreshToken,
				'expires_in' => $expiresIn,
			];
		};
	}

	/**
	 * Revoke user's background access (delete refresh token).
	 *
	 * Called from personal settings form POST.
	 * Redirects back to personal settings after completion.
	 *
	 * @return RedirectResponse
	 */
	#[NoAdminRequired]
	public function revokeAccess(): RedirectResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			// Should not happen (NoAdminRequired ensures user is logged in)
			$this->logger->error('Revoke access called without authenticated user');
			return new RedirectResponse(
				$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'astrolabe'])
			);
		}

		$userId = $user->getUID();

		// Get user's OAuth token
		$token = $this->tokenStorage->getUserToken($userId);
		if (!$token) {
			$this->logger->error("Cannot revoke access: No token found for user $userId");
			return new RedirectResponse(
				$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'astrolabe'])
			);
		}

		$accessToken = $token['access_token'];

		// Call MCP server API to revoke access
		$result = $this->client->revokeUserAccess($userId, $accessToken);

		if (isset($result['error'])) {
			$this->logger->error("Failed to revoke access for user $userId", [
				'error' => $result['error']
			]);
			// TODO: Add flash message/notification for user feedback
		} else {
			$this->logger->info("Successfully revoked background access for user $userId");

			// Delete local OAuth tokens from Nextcloud config
			// This ensures hasBackgroundAccess() returns false on next page load
			$this->tokenStorage->deleteUserToken($userId);
			$this->logger->debug("Deleted local OAuth tokens for user $userId");

			// TODO: Add success flash message/notification
		}

		// Redirect back to personal settings
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'astrolabe'])
		);
	}

	/**
	 * Execute semantic search via MCP server.
	 *
	 * AJAX endpoint for vector search UI in app page.
	 * Uses user's OAuth token for authentication.
	 *
	 * @param string $query Search query
	 * @param string $algorithm Search algorithm (semantic, bm25, hybrid)
	 * @param int $limit Number of results (max 50)
	 * @param string $doc_types Comma-separated document types (e.g., "note,file")
	 * @param string $include_pca Whether to include PCA coordinates for visualization
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function search(
		string $query = '',
		string $algorithm = 'hybrid',
		int $limit = 10,
		string $doc_types = '',
		string $include_pca = 'true',
	): JSONResponse {
		if (empty($query)) {
			return new JSONResponse([
				'success' => false,
				'error' => 'Missing required parameter: query'
			], Http::STATUS_BAD_REQUEST);
		}

		// Get current user
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse([
				'success' => false,
				'error' => 'User not authenticated'
			], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		// Get user's OAuth token for MCP server with automatic refresh
		$accessToken = $this->tokenStorage->getAccessToken($userId, $this->makeRefreshCallback());
		if ($accessToken === null) {
			return $this->unauthorizedResponse(self::AUTH_REQUIRED_MESSAGE, $userId);
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
				fn ($t) => in_array(trim($t), $validDocTypes)
			);
			$docTypesArray = array_map('trim', $docTypesArray);
			if (empty($docTypesArray)) {
				$docTypesArray = null;
			}
		}

		// Parse include_pca (string "true"/"false" from query params)
		$includePcaBool = in_array(strtolower($include_pca), ['true', '1', 'yes'], true);

		// Execute search via MCP server with OAuth token
		$result = $this->client->search($query, $algorithm, $limit, $includePcaBool, $docTypesArray, $accessToken);

		if (isset($result['error'])) {
			return new JSONResponse([
				'success' => false,
				'error' => $result['error']
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$response = [
			'success' => true,
			'results' => $result['results'] ?? [],
			'algorithm_used' => $result['algorithm_used'] ?? $algorithm,
			'total_documents' => $result['total_documents'] ?? 0,
		];

		// Include PCA visualization coordinates if requested and available
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
	 * Get vector sync status from MCP server.
	 *
	 * AJAX endpoint for status refresh in personal settings.
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function vectorStatus(): JSONResponse {
		$status = $this->client->getVectorSyncStatus();

		if (isset($status['error'])) {
			return new JSONResponse([
				'success' => false,
				'error' => $status['error']
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse([
			'success' => true,
			'status' => $status
		]);
	}

	/**
	 * Get MCP server status.
	 *
	 * Admin-only endpoint for admin settings page.
	 * Returns server version, uptime, and vector sync availability.
	 *
	 * @return JSONResponse
	 */
	public function serverStatus(): JSONResponse {
		$status = $this->client->getStatus();

		// Validate that status is an array before accessing
		if (!is_array($status)) {
			return new JSONResponse([
				'success' => false,
				'error' => 'Invalid response from MCP server'
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if (isset($status['error'])) {
			return new JSONResponse([
				'success' => false,
				'error' => $status['error']
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse([
			'success' => true,
			'status' => $status
		]);
	}

	/**
	 * Get vector sync status for admin.
	 *
	 * Admin-only endpoint for admin settings page.
	 * Returns indexing metrics and sync status.
	 *
	 * @return JSONResponse
	 */
	public function adminVectorStatus(): JSONResponse {
		$status = $this->client->getVectorSyncStatus();

		// Validate that status is an array before accessing
		if (!is_array($status)) {
			return new JSONResponse([
				'success' => false,
				'error' => 'Invalid response from MCP server'
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if (isset($status['error'])) {
			return new JSONResponse([
				'success' => false,
				'error' => $status['error']
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse([
			'success' => true,
			'status' => $status
		]);
	}

	/**
	 * Save admin search settings.
	 *
	 * Admin-only endpoint to configure AI Search provider parameters.
	 *
	 * @return JSONResponse
	 */
	public function saveSearchSettings(): JSONResponse {
		// Parse JSON body
		$input = file_get_contents('php://input');
		$data = json_decode($input, true);

		if ($data === null) {
			return new JSONResponse([
				'success' => false,
				'error' => 'Invalid JSON body'
			], Http::STATUS_BAD_REQUEST);
		}

		// Validate and save algorithm
		$validAlgorithms = ['hybrid', 'semantic', 'bm25'];
		$algorithm = $data['algorithm'] ?? AdminSettings::DEFAULT_SEARCH_ALGORITHM;
		if (!in_array($algorithm, $validAlgorithms)) {
			$algorithm = AdminSettings::DEFAULT_SEARCH_ALGORITHM;
		}
		$this->config->setAppValue(
			$this->appName,
			AdminSettings::SETTING_SEARCH_ALGORITHM,
			$algorithm
		);

		// Validate and save fusion method
		$validFusions = ['rrf', 'dbsf'];
		$fusion = $data['fusion'] ?? AdminSettings::DEFAULT_SEARCH_FUSION;
		if (!in_array($fusion, $validFusions)) {
			$fusion = AdminSettings::DEFAULT_SEARCH_FUSION;
		}
		$this->config->setAppValue(
			$this->appName,
			AdminSettings::SETTING_SEARCH_FUSION,
			$fusion
		);

		// Validate and save score threshold (0-100)
		$scoreThreshold = (int)($data['scoreThreshold'] ?? AdminSettings::DEFAULT_SEARCH_SCORE_THRESHOLD);
		$scoreThreshold = max(0, min(100, $scoreThreshold));
		$this->config->setAppValue(
			$this->appName,
			AdminSettings::SETTING_SEARCH_SCORE_THRESHOLD,
			(string)$scoreThreshold
		);

		// Validate and save limit (5-100)
		$limit = (int)($data['limit'] ?? AdminSettings::DEFAULT_SEARCH_LIMIT);
		$limit = max(5, min(100, $limit));
		$this->config->setAppValue(
			$this->appName,
			AdminSettings::SETTING_SEARCH_LIMIT,
			(string)$limit
		);

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
			]
		]);
	}

	/**
	 * Get available webhook presets.
	 *
	 * Admin-only endpoint that lists webhook presets filtered by installed apps.
	 *
	 * @return JSONResponse
	 */
	public function getWebhookPresets(): JSONResponse {
		// Get admin's OAuth token for API calls
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse([
				'success' => false,
				'error' => 'User not authenticated'
			], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		// Get access token with automatic refresh
		$accessToken = $this->tokenStorage->getAccessToken($userId, $this->makeRefreshCallback());
		if ($accessToken === null) {
			return $this->unauthorizedResponse(self::AUTH_REQUIRED_MESSAGE, $userId);
		}

		// Get installed apps to filter presets
		$installedAppsResult = $this->client->getInstalledApps($accessToken);
		if (isset($installedAppsResult['error'])) {
			return $this->mcpErrorResponse($installedAppsResult);
		}

		$installedApps = $installedAppsResult['apps'] ?? [];

		// Get registered webhooks to check preset status
		$webhooksResult = $this->client->listWebhooks($accessToken);
		if (isset($webhooksResult['error'])) {
			return $this->mcpErrorResponse($webhooksResult);
		}

		$registeredWebhooks = $webhooksResult['webhooks'] ?? [];

		// Filter presets by installed apps
		$presets = WebhookPresets::filterPresetsByInstalledApps($installedApps);

		// Add enabled status to each preset
		// IMPORTANT: Match both event type AND filter to avoid false positives
		// (e.g., Notes and Files both use FILE_EVENT_* but with different filters)
		$presetsWithStatus = [];
		foreach ($presets as $presetId => $preset) {
			// Check if all events for this preset are registered with matching filters
			$allEventsRegistered = true;
			foreach ($preset['events'] as $presetEvent) {
				$eventMatched = false;
				foreach ($registeredWebhooks as $webhook) {
					// Match event type
					if ($webhook['event'] !== $presetEvent['event']) {
						continue;
					}

					// Match filter (both must have filter or both must not have filter)
					$presetFilter = !empty($presetEvent['filter']) ? $presetEvent['filter'] : null;
					$webhookFilter = !empty($webhook['eventFilter']) ? $webhook['eventFilter'] : null;

					// Compare filters (use json_encode for deep comparison)
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

			$presetsWithStatus[$presetId] = array_merge($preset, [
				'enabled' => $allEventsRegistered
			]);
		}

		return new JSONResponse([
			'success' => true,
			'presets' => $presetsWithStatus
		]);
	}

	/**
	 * Enable a webhook preset.
	 *
	 * Admin-only endpoint that registers all webhooks for a preset.
	 *
	 * @param string $presetId Preset ID to enable
	 * @return JSONResponse
	 */
	public function enableWebhookPreset(string $presetId): JSONResponse {
		// Get admin's OAuth token
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse([
				'success' => false,
				'error' => 'User not authenticated'
			], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		// Get access token with automatic refresh
		$accessToken = $this->tokenStorage->getAccessToken($userId, $this->makeRefreshCallback());
		if ($accessToken === null) {
			return $this->unauthorizedResponse(self::AUTH_REQUIRED_MESSAGE, $userId);
		}

		// Get preset configuration
		$preset = WebhookPresets::getPreset($presetId);
		if ($preset === null) {
			return new JSONResponse([
				'success' => false,
				'error' => "Unknown preset: $presetId"
			], Http::STATUS_BAD_REQUEST);
		}

		// Get MCP server URL for webhook callback URI
		$mcpServerUrl = $this->client->getServerUrl();
		$callbackUri = $mcpServerUrl . '/webhooks/nextcloud';

		// Register each event in the preset
		$registered = [];
		$errors = [];
		foreach ($preset['events'] as $eventConfig) {
			$result = $this->client->createWebhook(
				$eventConfig['event'],
				$callbackUri,
				!empty($eventConfig['filter']) ? $eventConfig['filter'] : null,
				$accessToken
			);

			if (isset($result['error'])) {
				// Bail out immediately on provisioning-required — every
				// subsequent createWebhook would fail the same way and
				// pollute the error list.
				if (!empty($result['provisioning_required'])) {
					return $this->mcpErrorResponse($result);
				}
				$errors[] = [
					'event' => $eventConfig['event'],
					'error' => $result['error']
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
				'errors' => $errors
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->logger->info("Enabled webhook preset $presetId for user $userId", [
			'preset_id' => $presetId,
			'webhooks_registered' => count($registered)
		]);

		return new JSONResponse([
			'success' => true,
			'message' => "Enabled {$preset['name']}",
			'webhooks' => $registered
		]);
	}

	/**
	 * Disable a webhook preset.
	 *
	 * Admin-only endpoint that deletes all webhooks for a preset.
	 *
	 * @param string $presetId Preset ID to disable
	 * @return JSONResponse
	 */
	public function disableWebhookPreset(string $presetId): JSONResponse {
		// Get admin's OAuth token
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse([
				'success' => false,
				'error' => 'User not authenticated'
			], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		// Get access token with automatic refresh
		$accessToken = $this->tokenStorage->getAccessToken($userId, $this->makeRefreshCallback());
		if ($accessToken === null) {
			return $this->unauthorizedResponse(self::AUTH_REQUIRED_MESSAGE, $userId);
		}

		// Get preset configuration
		$preset = WebhookPresets::getPreset($presetId);
		if ($preset === null) {
			return new JSONResponse([
				'success' => false,
				'error' => "Unknown preset: $presetId"
			], Http::STATUS_BAD_REQUEST);
		}

		// Get all registered webhooks
		$webhooksResult = $this->client->listWebhooks($accessToken);
		if (isset($webhooksResult['error'])) {
			return $this->mcpErrorResponse($webhooksResult);
		}

		$registeredWebhooks = $webhooksResult['webhooks'] ?? [];

		// Find webhooks that match this preset's events AND filters
		// IMPORTANT: Must match both event type AND filter to avoid deleting
		// webhooks from other presets (e.g., Notes vs Files both use FILE_EVENT_*)
		$webhooksToDelete = [];
		foreach ($registeredWebhooks as $webhook) {
			// Check if this webhook matches any event in the preset
			foreach ($preset['events'] as $presetEvent) {
				// Match event type
				if ($webhook['event'] !== $presetEvent['event']) {
					continue;
				}

				// Match filter (both must have filter or both must not have filter)
				$presetFilter = !empty($presetEvent['filter']) ? $presetEvent['filter'] : null;
				$webhookFilter = !empty($webhook['eventFilter']) ? $webhook['eventFilter'] : null;

				// Compare filters (use json_encode for deep comparison)
				if (json_encode($presetFilter) === json_encode($webhookFilter)) {
					$webhooksToDelete[] = $webhook;
					break; // This webhook matches, no need to check other preset events
				}
			}
		}

		// Delete each matching webhook
		$deleted = [];
		$errors = [];
		foreach ($webhooksToDelete as $webhook) {
			$result = $this->client->deleteWebhook($webhook['id'], $accessToken);

			if (isset($result['error'])) {
				// Bail out immediately on provisioning-required — every
				// subsequent deleteWebhook would fail the same way.
				if (!empty($result['provisioning_required'])) {
					return $this->mcpErrorResponse($result);
				}
				$errors[] = [
					'webhook_id' => $webhook['id'],
					'event' => $webhook['event'],
					'error' => $result['error']
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
				'errors' => $errors
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->logger->info("Disabled webhook preset $presetId for user $userId", [
			'preset_id' => $presetId,
			'webhooks_deleted' => count($deleted)
		]);

		return new JSONResponse([
			'success' => true,
			'message' => "Disabled {$preset['name']}",
			'deleted' => $deleted
		]);
	}

	/**
	 * Get chunk context for visualization.
	 *
	 * @param string $doc_type Document type
	 * @param string $doc_id Document ID
	 * @param int $start Start offset
	 * @param int $end End offset
	 * @param int|null $chunk_index Zero-based chunk index in document (optional;
	 *                              when provided, lets the MCP server use the always-indexed chunk_index
	 *                              field for lookup instead of the offset filter)
	 * @param int|null $total_chunks Total chunks in document (optional)
	 * @return JSONResponse
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
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		// Get user's OAuth token for MCP server with automatic refresh
		$accessToken = $this->tokenStorage->getAccessToken($userId, $this->makeRefreshCallback());
		if ($accessToken === null) {
			return $this->unauthorizedResponse(self::AUTH_REQUIRED_MESSAGE, $userId);
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
			return new JSONResponse(['success' => false, 'error' => $result['error']], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result);
	}

	/**
	 * Get PDF page preview (server-side rendered).
	 *
	 * AJAX endpoint for PDF viewer in semantic search UI.
	 * Uses server-side PyMuPDF rendering to avoid CSP/worker issues.
	 *
	 * @param string $file_path WebDAV path to PDF file
	 * @param int $page Page number (1-indexed, default: 1)
	 * @param float $scale Zoom factor (default: 2.0)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function pdfPreview(
		string $file_path,
		int $page = 1,
		float $scale = 2.0,
	): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['success' => false, 'error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		// Get user's OAuth token for MCP server with automatic refresh
		$accessToken = $this->tokenStorage->getAccessToken($userId, $this->makeRefreshCallback());
		if ($accessToken === null) {
			return $this->unauthorizedResponse(self::AUTH_REQUIRED_MESSAGE, $userId);
		}

		$result = $this->client->getPdfPreview($file_path, $page, $scale, $accessToken);

		if (isset($result['error'])) {
			return new JSONResponse(['success' => false, 'error' => $result['error']], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result);
	}

	/**
	 * Convert an MCP client error result into a JSONResponse with the right
	 * HTTP status code.
	 *
	 * The MCP server returns 428 (Precondition Required) when the user has not
	 * completed Login Flow v2 provisioning. ``McpServerClient`` surfaces that
	 * as ``['error' => ..., 'provisioning_required' => true]``. Pass it back
	 * to the frontend with ``provisioning_required`` set so AdminSettings.vue
	 * can render a "complete authorization" CTA instead of an opaque error.
	 *
	 * @param array $result The MCP client result containing 'error' (and
	 *                      optionally 'provisioning_required').
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

	/**
	 * Diagnose why OAuth refresh is failing for the current user.
	 *
	 * Admin-only. Runs against the current user's stored token so the admin
	 * can reproduce by signing in as themselves on the affected deployment.
	 * Returns a structured report covering token state and the most recent
	 * IdpTokenRefresher failure reason, so an authorization failure can be
	 * diagnosed without `occ` / nextcloud.log access (e.g. Hetzner Storage
	 * Share, where logreader is disabled).
	 *
	 * Never returns the access_token or refresh_token themselves — only
	 * their presence and expiration metadata.
	 *
	 * SECURITY — admin gating is layered:
	 *   1. Nextcloud default: methods without #[NoAdminRequired] are
	 *      admin-only at the SecurityMiddleware dispatch layer. This
	 *      method INTENTIONALLY omits that attribute and MUST NOT have
	 *      one added — doing so would widen exposure to any logged-in
	 *      user and let an attacker who compromised a non-admin account
	 *      read $lastError (which can embed truncated IdP error_description
	 *      snippets).
	 *   2. In-method isAdmin($userId) re-check below: defense-in-depth so
	 *      future refactors (changing the class-level default, invoking
	 *      this from another controller, etc.) cannot silently lower the
	 *      gate without also tripping this guard.
	 */
	public function refreshDiagnostic(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse([
				'success' => false,
				'error' => 'User not authenticated',
			], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		// Defense in depth: Nextcloud's SecurityMiddleware already enforces
		// admin-only at the dispatch layer because this method has no
		// #[NoAdminRequired] attribute. Re-checking here keeps the guarantee
		// local to the handler so future refactors (e.g. accidentally
		// adding the attribute, or invoking this method from another
		// controller) cannot silently widen access to the diagnostic.
		if (!$this->groupManager->isAdmin($userId)) {
			return new JSONResponse([
				'success' => false,
				'error' => 'Admin privileges required',
			], Http::STATUS_FORBIDDEN);
		}

		$diagnostic = [
			'user_id' => $userId,
			'client_secret_configured' => !empty($this->config->getSystemValue('astrolabe_client_secret', '')),
			'mcp_server_url_configured' => !empty($this->config->getSystemValue('mcp_server_url', '')),
		];

		$token = $this->tokenStorage->getUserToken($userId);
		if ($token === null) {
			$diagnostic['has_stored_token'] = false;
			$diagnostic['conclusion'] = 'No OAuth token stored — user has not completed initial authorization.';
			return new JSONResponse(['success' => true, 'diagnostic' => $diagnostic]);
		}

		$now = time();
		$expiresAt = (int)($token['expires_at'] ?? 0);
		$issuedAt = isset($token['issued_at']) ? (int)$token['issued_at'] : null;
		// Array values come back as mixed; guard the type at the outer
		// read the same way the inner-lock read does (see $candidate
		// below). A corrupted storage entry (non-string under
		// 'refresh_token') would otherwise reach refreshAccessToken()
		// with an unexpected type.
		/** @psalm-suppress MixedAssignment — array values are mixed; guarded below */
		$rawRefreshToken = $token['refresh_token'] ?? '';
		$refreshToken = is_string($rawRefreshToken) ? $rawRefreshToken : '';

		$diagnostic['has_stored_token'] = true;
		$diagnostic['has_refresh_token'] = $refreshToken !== '';
		$diagnostic['access_token_expired'] = $this->tokenStorage->isExpired($token);
		$diagnostic['expires_in_seconds'] = $expiresAt - $now;
		// Omit issued_at entirely when the stored token doesn't carry it,
		// matching how refresh_error is only present when set. A null in
		// the JSON would be ambiguous: "field unavailable" vs. "explicitly
		// unset".
		if ($issuedAt !== null) {
			$diagnostic['issued_at'] = $issuedAt;
		}
		$diagnostic['expires_at'] = $expiresAt;

		// Attempt a refresh and capture the outcome.
		//
		// We MUST persist the new token if the IdP rotates refresh tokens —
		// otherwise this diagnostic call would invalidate the old refresh
		// token at the IdP without storing the new one, breaking the chain
		// and forcing the user to re-authorize. The lock keeps this in sync
		// with concurrent on-demand / background refreshes.
		//
		// Re-read the stored token inside the lock (double-check pattern,
		// same as McpTokenStorage::getAccessToken). The outer read above
		// could otherwise be stale by the time we acquire the lock — if
		// RefreshUserTokens rotated the refresh_token in the meantime, we'd
		// redeem an already-consumed value and report a false "revoked"
		// failure for a chain that is actually healthy.
		// Capture a concurrent-deletion race separately from a real
		// refresh failure: if a background job (RefreshUserTokens) or a
		// user-triggered revoke deletes the token between the outer
		// read and lock acquisition, `refreshAccessToken()` is never
		// called and `getLastError()` would return null, leading to a
		// misleading "see refresh_error" conclusion below.
		$refreshResult = $this->tokenStorage->withTokenLock($userId, function () use ($userId, $refreshToken): ?array {
			$latestToken = $this->tokenStorage->getUserToken($userId);
			if ($latestToken === null) {
				// Sentinel — the 'aborted' key distinguishes
				// "concurrent deletion, no refresh attempted" from
				// "refresh attempted and failed" (null). Returned
				// through withTokenLock's mixed return; narrowed at
				// the call site below. Using a return-value sentinel
				// instead of a by-reference capture also keeps psalm
				// happy: it doesn't track mutations through closure
				// reference captures and would otherwise infer the
				// outer flag as always-false.
				return ['aborted' => true];
			}
			// Strict check: array values come back as mixed, so guard the
			// type explicitly rather than relying on truthy/falsy. Fall
			// back to the pre-lock value if storage returned anything
			// other than a non-empty string (corrupted entry, race with
			// deletion partway through, etc.).
			/** @psalm-suppress MixedAssignment - array values are mixed; guarded below */
			$candidate = $latestToken['refresh_token'] ?? '';
			$currentRefreshToken = (is_string($candidate) && $candidate !== '')
				? $candidate
				: $refreshToken;

			$result = $this->tokenRefresher->refreshAccessToken($currentRefreshToken);
			if ($result === null || !isset($result['access_token'])) {
				return null;
			}
			$nowInner = time();
			/** @var string $accessToken */
			$accessToken = $result['access_token'];
			/** @var string $newRefreshToken */
			$newRefreshToken = $result['refresh_token'] ?? $currentRefreshToken;
			$expiresIn = (int)($result['expires_in'] ?? 3600);
			$this->tokenStorage->storeUserToken(
				$userId,
				$accessToken,
				$newRefreshToken,
				$nowInner + $expiresIn,
				$nowInner,
			);
			return $result;
		});

		if (is_array($refreshResult) && isset($refreshResult['aborted'])) {
			// No refresh was attempted — the field is intentionally
			// absent so callers don't confuse "aborted" with "failed
			// with no detail".
			$diagnostic['refresh_attempt'] = 'aborted';
			$diagnostic['conclusion'] = 'Token was concurrently deleted by another process (RefreshUserTokens job or revoke) between the outer read and lock acquisition. No refresh attempt was made; rerun the diagnostic to see the current state.';
		} elseif ($refreshResult !== null && isset($refreshResult['access_token'])) {
			$diagnostic['refresh_attempt'] = 'success';
			$diagnostic['new_access_token_expires_in'] = (int)($refreshResult['expires_in'] ?? 0);
			$diagnostic['idp_rotated_refresh_token'] = isset($refreshResult['refresh_token']);
			$diagnostic['conclusion'] = 'Refresh succeeded. Token chain is healthy and stored token has been updated.';
		} else {
			$diagnostic['refresh_attempt'] = 'failed';
			$diagnostic['refresh_error'] = $this->tokenRefresher->getLastError();
			$diagnostic['conclusion'] = 'Refresh failed — see refresh_error. The stored token has NOT been deleted by this diagnostic; the next user-triggered API call will trigger the same failure and delete it.';
		}

		return new JSONResponse(['success' => true, 'diagnostic' => $diagnostic]);
	}
}
