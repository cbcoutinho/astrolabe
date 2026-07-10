<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCA\Astrolabe\Controller\ApiController;
use OCA\Astrolabe\Service\Access\CalendarAccessVerifier;
use OCA\Astrolabe\Service\Access\DeckAccessVerifier;
use OCA\Astrolabe\Service\Access\DocumentAccessService;
use OCA\Astrolabe\Service\Access\FileAccessVerifier;
use OCA\Astrolabe\Service\Access\MailAccessVerifier;
use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\McpTokenMinter;
use OCA\Astrolabe\Service\SearchCapabilities;
use OCA\Astrolabe\Service\SearchSources;
use OCP\App\IAppManager;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Common scaffolding for ApiController tests: the controller's
 * dependency mocks and a constructed instance.
 *
 * After the auth refactor, the controller mints session-derived JWTs
 * via McpTokenMinter on every request — there is no token storage and
 * no refresher to mock.
 */
abstract class AbstractApiControllerTestCase extends TestCase {
	protected McpServerClient&MockObject $client;
	protected IUserSession&MockObject $userSession;
	protected LoggerInterface&MockObject $logger;
	protected McpTokenMinter&MockObject $tokenMinter;
	protected IConfig&MockObject $config;
	protected IAppConfig&MockObject $appConfig;
	protected SearchSources&MockObject $searchSources;
	protected SearchCapabilities&MockObject $searchCapabilities;
	protected IAppManager&MockObject $appManager;
	protected IRootFolder&MockObject $rootFolder;
	protected Folder&MockObject $userFolder;
	protected DocumentAccessService $documentAccess;
	protected ApiController $controller;

	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$this->client = $this->createMock(McpServerClient::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->tokenMinter = $this->createMock(McpTokenMinter::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->searchSources = $this->createMock(SearchSources::class);
		// Default: every catalog source is installed and approved, so search
		// tests see the pre-feature behaviour (no doc_type narrowing). Tests
		// that exercise the consent gate override this per case.
		$this->searchSources->method('effectiveEnabledDocTypes')
			->willReturn(['note', 'file', 'deck_card', 'calendar', 'contact', 'news_item', 'mail_message']);
		$this->searchCapabilities = $this->createMock(SearchCapabilities::class);
		// Default: the MCP server supports every algorithm, so search tests see
		// the pre-feature behaviour. The default void mock of assertSupported()
		// never throws; tests exercising the unsupported-type gate override it.
		$this->searchCapabilities->method('getSupportedSearchTypes')
			->willReturn(['semantic', 'bm25', 'hybrid']);
		$this->appManager = $this->createMock(IAppManager::class);
		// Default: the core "files" app is enabled for the user, so the
		// always-available files/notes presets surface. Tests that assert on other
		// apps override. getWebhookPresets() uses the per-user (non-deprecated)
		// accessor; getInstalledApps is stubbed too for any other caller.
		$this->appManager->method('getEnabledAppsForUser')->willReturn(['files']);
		$this->appManager->method('getInstalledApps')->willReturn(['files']);

		// Real DocumentAccessService wired to mocked leaf deps (it's final, so it
		// can't be mocked). By default SearchSources::isInstalled() returns false
		// (the createMock bool default), so every access check DELEGATEs and
		// nothing is dropped — existing tests see the pre-feature behaviour.
		// Access tests drive decisions via $this->searchSources->isInstalled and
		// $this->userFolder->getById.
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userFolder = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->willReturn($this->userFolder);
		$this->documentAccess = new DocumentAccessService(
			new FileAccessVerifier($this->rootFolder, $this->logger),
			new DeckAccessVerifier(),
			new MailAccessVerifier(),
			new CalendarAccessVerifier(),
			$this->searchSources,
		);

		$this->controller = new ApiController(
			'astrolabe',
			$request,
			$this->client,
			$this->userSession,
			$this->logger,
			$this->tokenMinter,
			$this->config,
			$this->appConfig,
			$this->searchSources,
			$this->searchCapabilities,
			$this->documentAccess,
			$this->appManager,
		);
	}

	/**
	 * Wire an authenticated user (defaults to UID 'admin') and configure
	 * the minter to issue a fixed token for them.
	 */
	protected function authenticateUserWithToken(string $uid = 'admin', string $token = 'mint-token'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->tokenMinter->method('mintForUser')->with($uid)->willReturn($token);
	}
}
