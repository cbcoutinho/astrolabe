<?php

declare(strict_types=1);

namespace OCA\Astrolabe\AppInfo;

use GuzzleHttp\Psr7\HttpFactory;
use OCA\Astrolabe\Capabilities;
use OCA\Astrolabe\Http\NextcloudPsr18Client;
use OCA\Astrolabe\Listener\AstrolabeAdminSettingsListener;
use OCA\Astrolabe\Search\SemanticSearchProvider;
use OCA\Astrolabe\Settings\AstrolabeAdminSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Http\Client\IClientService;
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
	}
}
