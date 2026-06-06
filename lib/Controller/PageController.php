<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Controller;

use OCA\Astrolabe\AppInfo\Application;
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
		]);

		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}
}
