<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Controller;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Service\BackgroundSyncCredentialStorage;
use OCA\Astrolabe\Service\SearchSources;
use OCA\Astrolabe\Settings\Admin as AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Pact provider-state hook (ADR-029): seeds the states the
 * nextcloud-mcp-server consumer pacts declare, so
 * tests/contract/Provider/CredentialsPactVerifyTest can replay them against a
 * running Nextcloud.
 *
 * TEST-ONLY. Every request answers 404 unless the system config (config.php)
 * flag `astrolabe.pact_state_endpoint_enabled` is true. That flag cannot be
 * set from the UI or app config, so the endpoint cannot be switched on by
 * accident in production. It is public because the verifier POSTs here
 * without credentials. Never set the flag outside a throwaway CI stack:
 * anyone who can reach the instance could then rewrite this app's settings.
 *
 * @psalm-suppress UnusedClass — routed via appinfo/routes.php.
 */
final class PactProviderStateController extends Controller {
	public const SYSTEM_FLAG = 'astrolabe.pact_state_endpoint_enabled';

	/**
	 * The user the verifier authenticates as (an app password for the e2e
	 * stack's admin, sent as a custom header). Per-user state is seeded for
	 * them, since the capabilities pact does not pin who is asking.
	 */
	public const VERIFIER_USER = 'admin';

	/** A dummy app password; nothing authenticates with it. */
	private const DUMMY_APP_PASSWORD = 'pact-provider-state-dummy-app-password';

	/** @psalm-suppress PossiblyUnusedMethod — instantiated by the Nextcloud DI container. */
	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private IAppConfig $appConfig,
		private BackgroundSyncCredentialStorage $credentialStorage,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Teardown resets to the default rather than the prior value: the
	 * provider stack is throwaway and starts from the defaults. A state whose
	 * setup already leaves the default in place has nothing to undo.
	 *
	 * @psalm-suppress PossiblyUnusedMethod — routed via appinfo/routes.php.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function handle(string $state = '', string $action = 'setup'): JSONResponse {
		if (!$this->config->getSystemValueBool(self::SYSTEM_FLAG, false)) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		$setup = $action === 'setup';

		switch ($state) {
			case 'user alice has provisioned background-sync credentials':
				if ($setup) {
					$this->credentialStorage->storeAppPassword('alice', self::DUMMY_APP_PASSWORD);
				} else {
					$this->credentialStorage->deleteAppPassword('alice');
				}
				break;
			case 'user bob has no background-sync credentials':
				if ($setup) {
					$this->credentialStorage->deleteAppPassword('bob');
				}
				break;
			case 'astrolabe has approved file and note for semantic search':
				// Nothing disabled: files is core and the stack installs notes.
				$this->appConfig->deleteKey(Application::APP_ID, AdminSettings::SETTING_DISABLED_SEARCH_SOURCES);
				break;
			case 'astrolabe has disabled all sources for semantic search':
				if ($setup) {
					$this->appConfig->setValueString(
						Application::APP_ID,
						AdminSettings::SETTING_DISABLED_SEARCH_SOURCES,
						json_encode(array_keys(SearchSources::CATALOG), JSON_THROW_ON_ERROR),
					);
				} else {
					$this->appConfig->deleteKey(Application::APP_ID, AdminSettings::SETTING_DISABLED_SEARCH_SOURCES);
				}
				break;
			case 'astrolabe reports effective sources for a user who disabled notes':
				// The pact expects exactly ["file"], so narrow out every other
				// installed source too, not just notes. `files` is the catalog
				// key whose doc type is `file` (SearchSources::CATALOG); the
				// unit test checks the result through SearchSources.
				if ($setup) {
					$this->config->setUserValue(
						self::VERIFIER_USER,
						Application::APP_ID,
						SearchSources::USER_SETTING_DISABLED_SEARCH_SOURCES,
						json_encode(array_values(array_diff(array_keys(SearchSources::CATALOG), ['files'])), JSON_THROW_ON_ERROR),
					);
				} else {
					$this->config->deleteUserValue(
						self::VERIFIER_USER,
						Application::APP_ID,
						SearchSources::USER_SETTING_DISABLED_SEARCH_SOURCES,
					);
				}
				break;
			default:
				// A state the consumer added without a handler here: fail loudly
				// rather than verify against unseeded data.
				return new JSONResponse(['error' => "Unknown provider state: $state"], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['state' => $state, 'action' => $action]);
	}
}
