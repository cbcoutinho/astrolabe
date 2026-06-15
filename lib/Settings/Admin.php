<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Settings;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\SearchSources;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Settings\ISettings;

/**
 * Admin settings panel for Astrolabe.
 *
 * Displays semantic search service status, indexing metrics,
 * configuration, and provides administrative controls.
 */
class Admin implements ISettings {
	// Search settings keys and defaults
	public const SETTING_SEARCH_ALGORITHM = 'search_algorithm';
	public const SETTING_SEARCH_FUSION = 'search_fusion';
	public const SETTING_SEARCH_SCORE_THRESHOLD = 'search_score_threshold';
	public const SETTING_SEARCH_LIMIT = 'search_limit';

	public const DEFAULT_SEARCH_ALGORITHM = 'hybrid';
	public const DEFAULT_SEARCH_FUSION = 'rrf';
	public const DEFAULT_SEARCH_SCORE_THRESHOLD = 0;
	public const DEFAULT_SEARCH_LIMIT = 20;

	// Whether users may self-provision background indexing. When disabled, only
	// admins can provision app passwords (existing user-provisioned passwords
	// keep working). Stored as a bool app-config value via IAppConfig.
	public const SETTING_ALLOW_USER_SELF_PROVISION = 'allow_user_self_provision';
	public const DEFAULT_ALLOW_USER_SELF_PROVISION = true;

	// Whether the 3D vector-space visualization panel is shown on the app page.
	// When disabled, the Plotly panel is hidden and the search request skips
	// the (more expensive) PCA computation. Stored as a bool via IAppConfig.
	public const SETTING_SHOW_VISUALIZATION = 'show_visualization';
	public const DEFAULT_SHOW_VISUALIZATION = true;

	// Source apps the admin has DISABLED for semantic search, stored as a JSON
	// array of source app ids (see SearchSources::CATALOG). The disabled-set is
	// stored (rather than the enabled-set) so the default empty value means
	// "all sources allowed" and sources added to the catalog later are allowed
	// by default. A source is searchable only if installed AND not in this set.
	public const SETTING_DISABLED_SEARCH_SOURCES = 'disabled_search_sources';
	public const DEFAULT_DISABLED_SEARCH_SOURCES = '[]';

	private $client;
	private $config;
	private $appConfig;
	private $initialState;
	private $searchSources;

	public function __construct(
		McpServerClient $client,
		IConfig $config,
		IAppConfig $appConfig,
		IInitialState $initialState,
		SearchSources $searchSources,
	) {
		$this->client = $client;
		$this->config = $config;
		$this->appConfig = $appConfig;
		$this->initialState = $initialState;
		$this->searchSources = $searchSources;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		// Get configuration from config.php (local, fast)
		$serverUrl = $this->config->getSystemValue('mcp_server_url', '');
		$clientId = $this->config->getSystemValue('astrolabe_client_id', '');
		$clientIdConfigured = !empty($clientId);

		// Load search settings from app config
		$searchSettings = [
			'algorithm' => $this->appConfig->getValueString(
				Application::APP_ID,
				self::SETTING_SEARCH_ALGORITHM,
				self::DEFAULT_SEARCH_ALGORITHM
			),
			'fusion' => $this->appConfig->getValueString(
				Application::APP_ID,
				self::SETTING_SEARCH_FUSION,
				self::DEFAULT_SEARCH_FUSION
			),
			'scoreThreshold' => $this->appConfig->getValueInt(
				Application::APP_ID,
				self::SETTING_SEARCH_SCORE_THRESHOLD,
				self::DEFAULT_SEARCH_SCORE_THRESHOLD
			),
			'limit' => $this->appConfig->getValueInt(
				Application::APP_ID,
				self::SETTING_SEARCH_LIMIT,
				self::DEFAULT_SEARCH_LIMIT
			),
			'showVisualization' => $this->appConfig->getValueBool(
				Application::APP_ID,
				self::SETTING_SHOW_VISUALIZATION,
				self::DEFAULT_SHOW_VISUALIZATION
			),
		];

		$allowUserSelfProvision = $this->appConfig->getValueBool(
			Application::APP_ID,
			self::SETTING_ALLOW_USER_SELF_PROVISION,
			self::DEFAULT_ALLOW_USER_SELF_PROVISION,
		);

		// Installed search sources with their current admin-consent state.
		// Only installed apps are listed — admins consent to what they actually
		// have. Uninstalled apps are excluded entirely.
		$searchSources = $this->searchSources->installedSources();

		// Provide initial state for Vue.js frontend
		// MCP server data will be fetched asynchronously by Vue component
		$this->initialState->provideInitialState('admin-config', [
			'config' => [
				'serverUrl' => $serverUrl,
				'clientIdConfigured' => $clientIdConfigured,
			],
			'searchSettings' => $searchSettings,
			'searchSources' => $searchSources,
			'allowUserSelfProvision' => $allowUserSelfProvision,
		]);

		$parameters = [];

		return new TemplateResponse(
			Application::APP_ID,
			'settings/admin',
			$parameters,
			TemplateResponse::RENDER_AS_BLANK
		);
	}

	/**
	 * @return string The section ID
	 */
	public function getSection(): string {
		return 'astrolabe';
	}

	/**
	 * @return int Priority (lower = higher up)
	 *
	 * Rendered after the declarative "MCP Server Configuration" form
	 * (priority 10) so that the connection config is the first section on the
	 * page and the Vue status/webhooks/search/provisioning sections follow.
	 */
	public function getPriority(): int {
		return 50;
	}
}
