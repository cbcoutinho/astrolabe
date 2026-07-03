<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Controller;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Exception\UnsupportedSearchTypeException;
use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\McpTokenMinter;
use OCA\Astrolabe\Service\McpTokenMintException;
use OCA\Astrolabe\Service\SearchCapabilities;
use OCA\Astrolabe\Service\SearchSources;
use OCA\Astrolabe\Service\WebhookPresets;
use OCA\Astrolabe\Settings\Admin as AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
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
		private IAppConfig $appConfig,
		private SearchSources $searchSources,
		private SearchCapabilities $searchCapabilities,
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

		// Gate the requested algorithm on what the MCP server actually advertises
		// (ADR-030). The UI hides unsupported options, but a direct or stale
		// client can still ask for e.g. "semantic" against a keyword-only server
		// — reject it here rather than silently coercing to "hybrid" (which would
		// return BM25 results dressed up as a semantic answer). Mirrors the
		// server's own 422 backstop.
		try {
			$this->searchCapabilities->assertSupported($algorithm);
		} catch (UnsupportedSearchTypeException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => 'unsupported_search_type',
				'requested' => $e->getRequested(),
				'supported_search_types' => $e->getSupported(),
			], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		// Enforce limit bounds
		$limit = max(1, min($limit, 50));

		// Restrict to admin-approved, installed sources. This is the
		// authoritative consent gate for the in-app search path; the MCP server
		// enforces the same gate independently for its direct Qdrant queries.
		// An empty doc_types filter means "all" only *within* this set, so when
		// the client requests no explicit types we pass the enabled set
		// explicitly rather than null (which the MCP server treats as "every
		// indexed type", bypassing consent).
		$enabledDocTypes = $this->searchSources->effectiveEnabledDocTypes();
		if (!empty($doc_types)) {
			$requested = array_map('trim', explode(',', $doc_types));
			$docTypesArray = array_values(array_intersect($requested, $enabledDocTypes));
		} else {
			$docTypesArray = $enabledDocTypes;
		}

		// No installed + approved source matches the request — nothing to
		// search. Short-circuit instead of falling through to a null filter.
		if ($docTypesArray === []) {
			return new JSONResponse([
				'success' => true,
				'results' => [],
				'algorithm_used' => $algorithm,
				'total_documents' => 0,
			]);
		}

		$includePcaBool = in_array(strtolower($include_pca), ['true', '1', 'yes'], true);

		// Server-side enforcement: when the admin has disabled the visualization
		// panel, never compute PCA — regardless of what the client (or a direct
		// API call) requests. The client also skips it, but this is the
		// authoritative gate.
		if (!$this->appConfig->getValueBool($this->appName, AdminSettings::SETTING_SHOW_VISUALIZATION, AdminSettings::DEFAULT_SHOW_VISUALIZATION)) {
			$includePcaBool = false;
		}

		// ADR-027 Phase 2 path filter. Accept a newline-separated path_prefixes
		// list (multi-folder) alongside the legacy single path_prefix; trim,
		// drop blanks, and dedupe so the folders OR cleanly on the MCP server.
		// Newline is the delimiter because, unlike a comma, it can't appear in a
		// POSIX path, so folder names are never split mid-value.
		//
		// Trust boundary: these values are user-controlled but are only used as
		// MatchText *filters* on the indexed file_path payload, never to access
		// the filesystem. The MCP server always AND-s them under an ACL owner
		// filter, so a hostile value (e.g. "/../etc/passwd") can only ever
		// narrow a user's own results — it cannot widen scope or traverse paths.
		// No path-shape validation is applied here precisely so that legitimate,
		// unusual folder names are never silently dropped.
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
		bool $showVisualization = AdminSettings::DEFAULT_SHOW_VISUALIZATION,
	): JSONResponse {
		// Parameters are populated by the framework from the JSON request body
		// (no need to read php://input directly).
		// Admins may only persist an algorithm the MCP server can actually serve
		// (ADR-030). The admin UI hides unsupported options, but reject an
		// out-of-band save (e.g. a keyword-only server) rather than silently
		// coercing to the default, so the stored config never drifts from what
		// the server advertises.
		try {
			$this->searchCapabilities->assertSupported($algorithm);
		} catch (UnsupportedSearchTypeException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => 'unsupported_search_type',
				'requested' => $e->getRequested(),
				'supported_search_types' => $e->getSupported(),
			], Http::STATUS_UNPROCESSABLE_ENTITY);
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

		// Bool stored via IAppConfig so it round-trips with Admin::getForm()'s
		// getValueBool() read (the string app-config values above are written
		// through IConfig for parity with the rest of this method).
		$this->appConfig->setValueBool($this->appName, AdminSettings::SETTING_SHOW_VISUALIZATION, $showVisualization);

		$this->logger->info('Admin search settings saved', [
			'algorithm' => $algorithm,
			'fusion' => $fusion,
			'scoreThreshold' => $scoreThreshold,
			'limit' => $limit,
			'showVisualization' => $showVisualization,
		]);

		return new JSONResponse([
			'success' => true,
			'settings' => [
				'algorithm' => $algorithm,
				'fusion' => $fusion,
				'scoreThreshold' => $scoreThreshold,
				'limit' => $limit,
				'showVisualization' => $showVisualization,
			],
		]);
	}

	/**
	 * Save which content sources are approved for semantic search. Admin-only.
	 *
	 * The request carries the desired *disabled* source app ids (toggled-off
	 * sources). Disabling a source is consent-binding on data-at-rest: any
	 * source that transitions enabled->disabled has its already-indexed vectors
	 * purged from the MCP server, not merely hidden.
	 *
	 * The config is persisted first so indexing stops immediately even if the
	 * eager purge cannot run (e.g. the MCP server is unreachable); the MCP
	 * server's scanner reconcile-purge is the backstop. Purge problems are
	 * reported in the response without failing the save.
	 *
	 * @param list<string> $disabledSources Source app ids to disable
	 */
	public function saveSearchSources(array $disabledSources = []): JSONResponse {
		$newDisabled = SearchSources::normalizeSourceIds($disabledSources);
		$oldDisabled = $this->searchSources->getDisabledSources();

		// Doc types of sources that just transitioned enabled -> disabled.
		$newlyDisabled = array_values(array_diff($newDisabled, $oldDisabled));
		$docTypesToPurge = SearchSources::docTypesForSources($newlyDisabled);

		// Persist first: stop future indexing/search before attempting purge.
		$this->appConfig->setValueString(
			$this->appName,
			AdminSettings::SETTING_DISABLED_SEARCH_SOURCES,
			json_encode($newDisabled, JSON_THROW_ON_ERROR),
		);

		$this->logger->info('Admin search sources saved', [
			'disabled' => $newDisabled,
			'newly_disabled' => $newlyDisabled,
		]);

		$purge = null;
		if ($docTypesToPurge !== []) {
			$purge = ['docTypes' => $docTypesToPurge];
			$accessToken = $this->tokenForCurrentUser();
			if ($accessToken instanceof JSONResponse) {
				// Couldn't mint a token to reach the MCP server. The config is
				// already saved, so indexing/search are gated; the scanner will
				// reconcile-purge later. Surface a warning, not a failure.
				$purge['warning'] = 'Could not reach the MCP server to delete indexed documents now; they will be removed on the next sync.';
			} else {
				$result = $this->client->purgeDocTypes($docTypesToPurge, $accessToken);
				if (isset($result['error'])) {
					$purge['warning'] = $result['error'];
				} else {
					$purge['result'] = $result['purged'] ?? [];
					// Partial failure: the MCP server reports which doc types it
					// could not purge. Surface a warning so the admin knows
					// consent isn't yet enforced for them (the MCP scanner
					// backstop will catch up).
					if (isset($result['failed']) && $result['failed'] !== []) {
						$failed = implode(', ', $result['failed']);
						$purge['warning'] = "Some content could not be deleted yet ($failed); it will be removed on the next sync.";
					}
				}
			}
		}

		return new JSONResponse([
			'success' => true,
			'searchSources' => $this->searchSources->installedSources(),
			'purge' => $purge,
		]);
	}

	/**
	 * Save the current user's personal search-source narrowing.
	 *
	 * Any authenticated user may disable sources for *their own* semantic search,
	 * within the admin-enabled ceiling (a user can't re-enable an admin-disabled
	 * source). Unlike the admin endpoint there is no eager purge: shrinking the
	 * user's effective ``enabled_doc_types`` (exposed per-user via the capability)
	 * makes the MCP scanner's per-user consent backstop delete that user's points
	 * on the next sync — see the per-user delete path in vector/scanner.py.
	 *
	 * @param list<string> $disabledSources Source app ids the user disables for themselves
	 */
	#[NoAdminRequired]
	public function saveUserSearchSources(array $disabledSources = []): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse([
				'success' => false,
				'error' => 'User not authenticated',
			], Http::STATUS_UNAUTHORIZED);
		}

		$normalized = SearchSources::normalizeSourceIds($disabledSources);
		// Use Application::APP_ID (not $this->appName) so the write key matches
		// how SearchSources::getUserDisabledSources() reads it back.
		$this->config->setUserValue(
			$user->getUID(),
			Application::APP_ID,
			SearchSources::USER_SETTING_DISABLED_SEARCH_SOURCES,
			json_encode($normalized, JSON_THROW_ON_ERROR),
		);

		$this->logger->info('User search sources saved', [
			'user_id' => $user->getUID(),
			'disabled' => $normalized,
		]);

		return new JSONResponse([
			'success' => true,
			'searchSources' => $this->searchSources->userConfigurableSources(),
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
