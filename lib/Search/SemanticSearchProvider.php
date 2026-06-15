<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Search;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\McpTokenMinter;
use OCA\Astrolabe\Service\McpTokenMintException;
use OCA\Astrolabe\Service\SearchSources;
use OCA\Astrolabe\Settings\Admin as AdminSettings;
use OCP\Files\FileInfo;
use OCP\Files\IMimeTypeDetector;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IPreview;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;

/**
 * Unified Search provider for MCP Server semantic search.
 *
 * Delegates search queries to the MCP server's vector search API,
 * returning semantically relevant results from indexed Nextcloud content
 * (notes, files, calendar, deck cards).
 *
 * Security: Results are filtered server-side to only include documents
 * the searching user has access to. User identity comes from the
 * session-derived JWT minted by McpTokenMinter for the current
 * Nextcloud session user.
 */
class SemanticSearchProvider implements IProvider {
	/** Seconds to cache the (non-user-specific) MCP status across keystrokes. */
	private const STATUS_CACHE_TTL = 30;
	private const STATUS_CACHE_KEY = 'mcp_status';

	public function __construct(
		private McpServerClient $client,
		private McpTokenMinter $tokenMinter,
		private IConfig $config,
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private IMimeTypeDetector $mimeTypeDetector,
		private IPreview $previewManager,
		private LoggerInterface $logger,
		private ICacheFactory $cacheFactory,
		private SearchSources $searchSources,
	) {
	}

	/**
	 * Unique identifier for this search provider.
	 */
	public function getId(): string {
		return Application::APP_ID . '_semantic';
	}

	/**
	 * Display name shown in search results grouping.
	 */
	public function getName(): string {
		return $this->l10n->t('Astrolabe');
	}

	/**
	 * Order in search results. Lower = higher priority.
	 * Use negative value when user is in our app's context.
	 */
	public function getOrder(string $route, array $routeParameters): int {
		if (str_contains($route, Application::APP_ID)) {
			return -1; // Prioritize when in Astrolabe app
		}
		return 40; // Above most apps, below files/mail
	}

	/**
	 * Execute semantic search via MCP server.
	 *
	 * SECURITY: Results are filtered server-side to only include documents
	 * the searching user has access to. User identity comes from a JWT
	 * minted on-demand from the Nextcloud session.
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = $query->getTerm();
		$limit = $query->getLimit();
		$cursor = $query->getCursor();

		// Skip empty queries
		if (empty(trim($term))) {
			return SearchResult::complete($this->getName(), []);
		}

		$userId = $user->getUID();

		// Mint a session-derived JWT for the MCP server. Unified Search
		// runs on every keystroke in the global search bar, so a mint
		// failure (e.g. the `oidc` app is broken or the client is missing
		// from admin settings) must NOT raise — log and return empty so
		// the rest of unified search keeps working.
		try {
			$accessToken = $this->tokenMinter->mintForUser($userId);
		} catch (McpTokenMintException $e) {
			$this->logger->debug('Skipping semantic search: token mint failed', [
				'user_id' => $userId,
				'error' => $e->getMessage(),
			]);
			return SearchResult::complete($this->getName(), []);
		}

		// Check if MCP server is available and vector sync enabled
		// Cached briefly: Unified Search fires on every keystroke and the MCP
		// status is neither user-specific nor fast-changing, so this avoids an
		// outbound HTTP GET per character.
		$status = $this->getCachedStatus();
		if (!empty($status['error']) || ($status['vector_sync_enabled'] ?? false) !== true) {
			$this->logger->debug('MCP server not available or vector sync disabled', [
				'status' => $status,
			]);
			return SearchResult::complete($this->getName(), []);
		}

		// Load admin search settings
		$algorithm = $this->config->getAppValue(
			Application::APP_ID,
			AdminSettings::SETTING_SEARCH_ALGORITHM,
			AdminSettings::DEFAULT_SEARCH_ALGORITHM
		);
		$fusion = $this->config->getAppValue(
			Application::APP_ID,
			AdminSettings::SETTING_SEARCH_FUSION,
			AdminSettings::DEFAULT_SEARCH_FUSION
		);
		$scoreThreshold = (int)$this->config->getAppValue(
			Application::APP_ID,
			AdminSettings::SETTING_SEARCH_SCORE_THRESHOLD,
			(string)AdminSettings::DEFAULT_SEARCH_SCORE_THRESHOLD
		);
		$configuredLimit = (int)$this->config->getAppValue(
			Application::APP_ID,
			AdminSettings::SETTING_SEARCH_LIMIT,
			(string)AdminSettings::DEFAULT_SEARCH_LIMIT
		);

		// Use configured limit if query limit is higher
		$effectiveLimit = min($limit, $configuredLimit);

		// Calculate offset from cursor
		$offset = $cursor ? (int)$cursor : 0;

		// Restrict to admin-approved, installed source types. When no source is
		// enabled there is nothing to search — return empty rather than letting
		// the server default to "all indexed types".
		$enabledDocTypes = $this->searchSources->effectiveEnabledDocTypes();
		if ($enabledDocTypes === []) {
			return SearchResult::complete($this->getName(), []);
		}

		// Execute semantic search with OAuth token and admin settings
		// Server extracts user_id from token - results filtered to that user's documents
		$results = $this->client->searchForUnifiedSearch(
			query: $term,
			token: $accessToken,
			limit: $effectiveLimit,
			offset: $offset,
			algorithm: $algorithm,
			fusion: $fusion,
			scoreThreshold: (float)$scoreThreshold / 100.0, // Convert percentage to 0-1 range
			docTypes: $enabledDocTypes,
		);

		if (!empty($results['error'])) {
			$this->logger->warning('Semantic search failed', [
				'error' => $results['error'],
				'query' => $term,
			]);
			return SearchResult::complete($this->getName(), []);
		}

		// Transform results to SearchResultEntry objects
		$entries = [];
		foreach ($results['results'] ?? [] as $result) {
			$entries[] = $this->transformResult($result);
		}

		// Return paginated if more results might exist
		$totalFound = $results['total_found'] ?? count($entries);
		if (count($entries) >= $effectiveLimit && $totalFound > $offset + $effectiveLimit) {
			return SearchResult::paginated(
				$this->getName(),
				$entries,
				(string)($offset + $effectiveLimit)
			);
		}

		return SearchResult::complete($this->getName(), $entries);
	}

	/**
	 * Fetch the MCP server status, cached across keystrokes.
	 *
	 * The status (server reachable + vector_sync_enabled) is global, not
	 * per-user, and does not change between keystrokes, yet the provider runs
	 * on every keystroke of the global search bar. Cache a healthy status for
	 * a short TTL to avoid an HTTP GET per character. Errors/unavailable states
	 * are deliberately NOT cached, so search resumes the instant the server
	 * recovers rather than staying suppressed for the TTL.
	 *
	 * @return array Decoded MCP status payload (same shape as McpServerClient::getStatus)
	 */
	private function getCachedStatus(): array {
		$cache = $this->cacheFactory->createDistributed('astrolabe_semantic_search');
		/** @var mixed $cached */
		$cached = $cache->get(self::STATUS_CACHE_KEY);
		if (is_array($cached)) {
			return $cached;
		}

		$status = $this->client->getStatus();
		if (empty($status['error'])) {
			$cache->set(self::STATUS_CACHE_KEY, $status, self::STATUS_CACHE_TTL);
		}
		return $status;
	}

	/**
	 * Transform MCP search result to Nextcloud SearchResultEntry.
	 */
	private function transformResult(array $result): SearchResultEntry {
		$docType = $result['doc_type'] ?? 'unknown';
		$title = $result['title'] ?? $this->l10n->t('Untitled');
		$score = $result['score'] ?? 0;
		$id = isset($result['id']) ? (string)$result['id'] : null;
		$mimeType = $result['mime_type'] ?? null;

		// Build resource URL based on document type
		$resourceUrl = $this->buildResourceUrl($result);

		// Get icon and thumbnail based on document type
		[$thumbnailUrl, $iconClass] = $this->getIconAndThumbnail($docType, $id, $mimeType);

		// Build metadata string with chunk and page info
		$metadataParts = [];

		// Chunk info (always available)
		if (isset($result['chunk_index']) && isset($result['total_chunks'])) {
			$chunkNum = $result['chunk_index'] + 1; // Convert 0-based to 1-based
			$metadataParts[] = sprintf('Chunk %d/%d', $chunkNum, $result['total_chunks']);
		}

		// Page info for PDFs
		if (!empty($result['page_number']) && !empty($result['page_count'])) {
			$metadataParts[] = sprintf('Page %d/%d', $result['page_number'], $result['page_count']);
		}

		// Combine metadata parts
		$metadata = !empty($metadataParts) ? implode(' · ', $metadataParts) : '';

		// Subline shows only chunk/page metadata (no excerpt, consistent with chunk viz)
		$subline = $metadata ?: sprintf(
			'%s · %d%% %s',
			$this->getDocTypeLabel($docType),
			(int)($score * 100),
			$this->l10n->t('relevant')
		);

		return new SearchResultEntry(
			$thumbnailUrl,
			$title,
			$subline,
			$resourceUrl,
			$iconClass,
			false // not rounded
		);
	}

	/**
	 * Build URL to navigate to Astrolabe with chunk viewer.
	 *
	 * Links to Astrolabe app with query parameters that trigger the chunk modal,
	 * allowing users to preview the chunk before navigating to the full document.
	 */
	private function buildResourceUrl(array $result): string {
		// Build base URL to Astrolabe app
		$baseUrl = $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index');

		// Extract chunk parameters
		$docType = $result['doc_type'] ?? 'unknown';
		$id = $result['id'] ?? null;
		$chunkStart = $result['chunk_start_offset'] ?? null;
		$chunkEnd = $result['chunk_end_offset'] ?? null;

		// If we have chunk information, build URL with parameters
		if ($id !== null && $chunkStart !== null && $chunkEnd !== null) {
			$params = [
				'doc_type' => $docType,
				'doc_id' => $id,
				'chunk_start' => $chunkStart,
				'chunk_end' => $chunkEnd,
			];

			// Add optional metadata
			if (isset($result['title'])) {
				$params['title'] = $result['title'];
			}
			if (isset($result['path'])) {
				$params['path'] = $result['path'];
			}
			if (isset($result['page_number'])) {
				$params['page_number'] = $result['page_number'];
			}
			if (isset($result['board_id'])) {
				$params['board_id'] = $result['board_id'];
			}

			// Encode parameters for URL
			$queryString = http_build_query($params);
			return $baseUrl . '?' . $queryString;
		}

		// Fallback to base URL if no chunk information
		return $baseUrl;
	}

	/**
	 * Get icon and thumbnail for document type.
	 *
	 * Returns [thumbnailUrl, iconClass] tuple.
	 * For files, uses mimetype-specific icons and preview thumbnails when available.
	 * For other document types, uses appropriate icon classes.
	 *
	 * @return array{string, string} [thumbnailUrl, iconClass]
	 */
	private function getIconAndThumbnail(string $docType, ?string $id, ?string $mimeType): array {
		if ($docType === 'file' && $id !== null && $mimeType !== null) {
			// For files, check if preview is supported
			$thumbnailUrl = '';
			if ($this->previewManager->isMimeSupported($mimeType)) {
				$thumbnailUrl = $this->urlGenerator->linkToRouteAbsolute(
					'core.Preview.getPreviewByFileId',
					['x' => 32, 'y' => 32, 'fileId' => $id]
				);
			}

			// Get mimetype-specific icon class
			$iconClass = $mimeType === FileInfo::MIMETYPE_FOLDER
				? 'icon-folder'
				: $this->mimeTypeDetector->mimeTypeIcon($mimeType);

			return [$thumbnailUrl, $iconClass];
		}

		// For non-file document types, use icon classes
		$iconClass = match ($docType) {
			'note' => 'icon-notes',
			'deck_card' => 'icon-deck',
			'calendar', 'calendar_event' => 'icon-calendar',
			'news_item' => 'icon-rss',
			'contact' => 'icon-contacts',
			default => 'icon-file',
		};

		return ['', $iconClass];
	}

	/**
	 * Get human-readable label for document type.
	 */
	private function getDocTypeLabel(string $docType): string {
		return match ($docType) {
			'note' => $this->l10n->t('Note'),
			'file' => $this->l10n->t('File'),
			'deck_card' => $this->l10n->t('Deck Card'),
			'calendar', 'calendar_event' => $this->l10n->t('Calendar'),
			'news_item' => $this->l10n->t('News'),
			'contact' => $this->l10n->t('Contact'),
			default => $this->l10n->t('Document'),
		};
	}

}
