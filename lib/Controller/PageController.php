<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Controller;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Service\SearchCapabilities;
use OCA\Astrolabe\Service\SearchSources;
use OCA\Astrolabe\Settings\Admin as AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * @psalm-suppress UnusedClass
 */
class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IAppConfig $appConfig,
		private IInitialState $initialState,
		private SearchSources $searchSources,
		private SearchCapabilities $searchCapabilities,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		// Surface admin-controlled page config to the Vue app. The
		// visualization toggle is the authoritative gate: when off the Plotly
		// panel is hidden and the search request skips PCA.
		$this->initialState->provideInitialState('app-config', [
			'showVisualization' => $this->appConfig->getValueBool(
				Application::APP_ID,
				AdminSettings::SETTING_SHOW_VISUALIZATION,
				AdminSettings::DEFAULT_SHOW_VISUALIZATION,
			),
			// Per-user effective doc types (admin ∩ user), so the search page's
			// type filter only offers sources enabled for this user. The search
			// backend already intersects requested types with this set.
			'enabledDocTypes' => $this->searchSources->effectiveEnabledDocTypes(),
			// Query algorithms the MCP server can actually serve (ADR-030), so
			// the algorithm picker hides semantic/hybrid when the server runs
			// keyword-only. The search backend rejects unsupported requests (422)
			// as the backstop.
			'supportedSearchTypes' => $this->searchCapabilities->getSupportedSearchTypes(),
		]);

		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}
}
