<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Controller;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Service\Assistant\AssistantCapabilities;
use OCA\Astrolabe\Service\SearchCapabilities;
use OCA\Astrolabe\Service\SearchSources;
use OCA\Astrolabe\Settings\Admin as AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\ContentSecurityPolicy;
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
		private AssistantCapabilities $assistantCapabilities,
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
			// Query algorithms the MCP server can actually serve, so the algorithm
			// picker hides all options only when the advertised set is [] (vector
			// sync off). The search backend rejects unsupported requests (422) as
			// the backstop.
			'supportedSearchTypes' => $this->searchCapabilities->getSupportedSearchTypes(),
			// Summary tiers a TaskProcessing provider can actually serve, so the
			// chunk viewer only offers the action when something can answer it.
			// Empty ⇒ the button is hidden entirely. Provided here as well as on
			// the OCS capabilities endpoint so the page needs no extra round-trip.
			'summaryModes' => $this->assistantCapabilities->getSummaryModes(),
		]);

		$response = new TemplateResponse(
			Application::APP_ID,
			'index',
		);

		// PDF chunk previews are rasterized in the browser by PDF.js, which runs
		// its parser in a Web Worker. Nextcloud's default policy sets no
		// worker-src, so it falls back through child-src to a nonce-only
		// script-src — and a worker URL cannot carry a nonce, so the worker is
		// blocked outright. That block is what previously forced page rendering
		// onto the MCP server, where reading whole documents into memory
		// OOMKilled the API pod.
		//
		// blob: is what the bundled worker actually uses: Vite's inline-worker
		// helper builds a Blob and calls createObjectURL, only falling back to a
		// data: URL if Blob is unavailable. Allowing blob: keeps that fallback
		// off the table, so data: is deliberately not permitted here.
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedWorkerSrcDomain('blob:');
		$response->setContentSecurityPolicy($csp);

		return $response;
	}
}
