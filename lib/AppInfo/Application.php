<?php

declare(strict_types=1);

namespace OCA\Astrolabe\AppInfo;

use GuzzleHttp\Psr7\HttpFactory;
use OCA\Astrolabe\Capabilities;
use OCA\Astrolabe\Http\NextcloudPsr18Client;
use OCA\Astrolabe\Listener\AstrolabeAdminSettingsListener;
use OCA\Astrolabe\Listener\SyncEventListener;
use OCA\Astrolabe\Search\SemanticSearchProvider;
use OCA\Astrolabe\Service\WebhookPresets;
use OCA\Astrolabe\Settings\Admin;
use OCA\Astrolabe\Settings\AstrolabeAdminSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\Settings\Events\DeclarativeSettingsGetValueEvent;
use OCP\Settings\Events\DeclarativeSettingsSetValueEvent;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'astrolabe';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// PSR-18 / PSR-17 plumbing for McpServerClient. Production wraps
		// Nextcloud's IClient (preserving its TLS / proxy / DNS config) behind
		// the standard ClientInterface; contract and unit tests swap in any
		// PSR-18 client (e.g. the Pact mock server).
		$context->registerService(ClientInterface::class, function (ContainerInterface $c): ClientInterface {
			/** @var IClientService $clientService */
			$clientService = $c->get(IClientService::class);
			return new NextcloudPsr18Client($clientService->newClient());
		});
		$context->registerService(HttpFactory::class, static fn (): HttpFactory => new HttpFactory());
		$context->registerServiceAlias(RequestFactoryInterface::class, HttpFactory::class);
		$context->registerServiceAlias(StreamFactoryInterface::class, HttpFactory::class);

		// Register unified search provider for semantic search
		$context->registerSearchProvider(SemanticSearchProvider::class);

		// Advertise admin-approved searchable sources to the MCP server via the
		// OCS capabilities endpoint.
		$context->registerCapability(Capabilities::class);

		// Register declarative admin settings
		$context->registerDeclarativeSettings(AstrolabeAdminSettings::class);

		// Register event listeners for declarative settings
		$context->registerEventListener(
			DeclarativeSettingsGetValueEvent::class,
			AstrolabeAdminSettingsListener::class
		);
		$context->registerEventListener(
			DeclarativeSettingsSetValueEvent::class,
			AstrolabeAdminSettingsListener::class
		);
	}

	public function boot(IBootContext $context): void {
		// Subscribe our native sync listener to exactly the event classes of the
		// presets the admin has enabled (mirrors webhook_listeners' dynamic
		// registration). Config is read fresh each request, so toggling a preset
		// takes effect on the next request with no cache to invalidate.
		// addServiceListener() takes a string class name, so subscribing to an
		// event from an app that isn't installed is harmless — it simply never fires.
		$context->injectFn(function (IEventDispatcher $dispatcher, IAppConfig $appConfig): void {
			if (!$appConfig->getValueBool(self::APP_ID, Admin::SETTING_NATIVE_SYNC_ENABLED, Admin::DEFAULT_NATIVE_SYNC_ENABLED)) {
				return;
			}

			$decoded = json_decode(
				$appConfig->getValueString(self::APP_ID, Admin::SETTING_ENABLED_SYNC_PRESETS, Admin::DEFAULT_ENABLED_SYNC_PRESETS),
				true,
			);
			if (!is_array($decoded)) {
				return;
			}

			$eventClasses = [];
			/** @var mixed $presetId */
			foreach ($decoded as $presetId) {
				if (!is_string($presetId)) {
					continue;
				}
				foreach (WebhookPresets::getPresetEvents($presetId) as $eventClass) {
					$eventClasses[$eventClass] = true;
				}
			}

			foreach (array_keys($eventClasses) as $eventClass) {
				$dispatcher->addServiceListener($eventClass, SyncEventListener::class, -1);
			}
		});
	}
}
