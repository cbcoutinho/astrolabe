<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Settings;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Service\BackgroundSyncCredentialStorage;
use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\SearchSources;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\Settings\ISettings;

/**
 * Personal settings panel for Astrolabe.
 *
 * Shows MCP server status and the user's background-indexing
 * provisioning state. There is no per-user OAuth flow — access tokens
 * are minted on demand from the active Nextcloud session. The only
 * per-user credential here is the optional app password the MCP server
 * uses to read the user's files for background indexing.
 */
class Personal implements ISettings {
	public function __construct(
		private McpServerClient $client,
		private IUserSession $userSession,
		private BackgroundSyncCredentialStorage $credentialStorage,
		private IAppConfig $appConfig,
		private IInitialState $initialState,
		private SearchSources $searchSources,
	) {
	}

	public function getForm(): TemplateResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new TemplateResponse(Application::APP_ID, 'settings/error', [
				'error' => 'User not authenticated',
			], TemplateResponse::RENDER_AS_BLANK);
		}

		$userId = $user->getUID();
		$serverStatus = $this->client->getStatus();

		if (isset($serverStatus['error'])) {
			return new TemplateResponse(
				Application::APP_ID,
				'settings/error',
				[
					'error' => 'Cannot connect to MCP server',
					'details' => $serverStatus['error'],
					'server_url' => $this->client->getPublicServerUrl(),
				],
				TemplateResponse::RENDER_AS_BLANK,
			);
		}

		$allowUserSelfProvision = $this->appConfig->getValueBool(
			Application::APP_ID,
			Admin::SETTING_ALLOW_USER_SELF_PROVISION,
			Admin::DEFAULT_ALLOW_USER_SELF_PROVISION,
		);

		$this->initialState->provideInitialState('personal-config', [
			'userId' => $userId,
			'serverUrl' => $this->client->getPublicServerUrl(),
			'serverStatus' => $serverStatus,
			'vectorSyncEnabled' => $serverStatus['vector_sync_enabled'] ?? false,
			'hasBackgroundAccess' => $this->credentialStorage->hasAccess($userId),
			'backgroundSyncProvisionedAt' => $this->credentialStorage->getProvisionedAt($userId),
			'allowUserSelfProvision' => $allowUserSelfProvision,
			// Per-user searchable-source narrowing (within the admin ceiling).
			'searchSources' => $this->searchSources->userConfigurableSources(),
		]);

		return new TemplateResponse(
			Application::APP_ID,
			'settings/personal',
			[],
			TemplateResponse::RENDER_AS_BLANK,
		);
	}

	public function getSection(): string {
		return 'astrolabe';
	}

	public function getPriority(): int {
		return 50;
	}
}
