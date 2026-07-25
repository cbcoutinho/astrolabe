<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Settings;

use OCA\Astrolabe\AppInfo\Application;
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

	// Native sync listeners: when enabled, Astrolabe's own event listeners deliver
	// change events straight to the MCP server's webhook ingress (see
	// SyncEventListener) instead of registering webhooks with Nextcloud's
	// webhook_listeners app via the MCP server. Master switch, bool via IAppConfig.
	public const SETTING_NATIVE_SYNC_ENABLED = 'native_sync_enabled';
	public const DEFAULT_NATIVE_SYNC_ENABLED = true;

	// Sync presets (see WebhookPresets) the admin has turned on, stored as a JSON
	// array of preset ids. Replaces the old "which webhooks are registered on the
	// MCP server" state. Default empty ⇒ no native listeners fire.
	public const SETTING_ENABLED_SYNC_PRESETS = 'enabled_sync_presets';
	public const DEFAULT_ENABLED_SYNC_PRESETS = '[]';

	// Registers Astrolabe as a TaskProcessing provider for the core
	// `core:contextagent:interaction` task type, which makes the Assistant's
	// "Chat with AI" route every message through Astrolabe instead of the
	// Context Agent app.
	//
	// Deliberately OFF by default and opt-in. A task type counts as "available"
	// the moment any provider registers for it, so registering unconditionally
	// would silently rewire every Assistant chat on the instance the moment this
	// app is installed. If context_agent is also installed both providers claim
	// the same task type and the admin picks between them in the AI settings.
	public const SETTING_AGENT_ENABLED = 'agent_enabled';
	public const DEFAULT_AGENT_ENABLED = false;

	private $config;
	private $appConfig;
	private $initialState;
	private $searchSources;

	public function __construct(
		IConfig $config,
		IAppConfig $appConfig,
		IInitialState $initialState,
		SearchSources $searchSources,
	) {
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

		// Native sync-listener state for the "Webhook management" panel. The secret
		// itself is never exposed — only whether one is configured — so the UI can
		// warn when delivery would be impossible.
		$nativeSyncEnabled = $this->appConfig->getValueBool(
			Application::APP_ID,
			self::SETTING_NATIVE_SYNC_ENABLED,
			self::DEFAULT_NATIVE_SYNC_ENABLED,
		);
		$webhookSecretConfigured = $this->config->getSystemValue('mcp_webhook_secret', '') !== '';

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
			'nativeSyncEnabled' => $nativeSyncEnabled,
			'webhookSecretConfigured' => $webhookSecretConfigured,
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
