<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service;

use OCA\Astrolabe\Service\SearchSources;
use OCA\Astrolabe\Settings\Admin;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the searchable-sources catalog and admin-consent logic.
 *
 * A source is searchable only if it is BOTH installed AND not in the admin
 * disabled-set; the disabled-set is stored (so the default is "all allowed").
 */
final class SearchSourcesTest extends TestCase {
	private IAppManager&MockObject $appManager;
	private IAppConfig&MockObject $appConfig;
	private IUserSession&MockObject $userSession;

	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
	}

	private function withDisabled(string $json): SearchSources {
		$this->appConfig->method('getValueString')
			->with('astrolabe', Admin::SETTING_DISABLED_SEARCH_SOURCES, Admin::DEFAULT_DISABLED_SEARCH_SOURCES)
			->willReturn($json);
		return new SearchSources($this->appManager, $this->appConfig, $this->userSession);
	}

	/** Mark every non-core app installed (files is always core). */
	private function allAppsInstalled(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);
	}

	public function testFilesAlwaysInstalledEvenWhenAppManagerSaysNo(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$sources = $this->withDisabled('[]');
		$this->assertTrue($sources->isInstalled('files'));
	}

	public function testDefaultAllSourcesEnabled(): void {
		$this->allAppsInstalled();
		$sources = $this->withDisabled('[]');

		$docTypes = $sources->effectiveEnabledDocTypes();
		$this->assertEqualsCanonicalizing(
			['note', 'file', 'deck_card', 'news_item', 'calendar', 'contact'],
			$docTypes,
		);
	}

	public function testSourcesWithEnabledDocTypesReturnsBoth(): void {
		$this->allAppsInstalled();
		$sources = $this->withDisabled('["files"]');

		$result = $sources->sourcesWithEnabledDocTypes();

		$this->assertNotContains('file', $result['enabledDocTypes']);
		$this->assertContains('note', $result['enabledDocTypes']);
		// sources includes the disabled one (with enabled=false).
		$apps = array_column($result['sources'], 'app');
		$this->assertContains('files', $apps);
		$byApp = array_column($result['sources'], 'enabled', 'app');
		$this->assertFalse($byApp['files']);
	}

	public function testDisabledSourceExcludedFromDocTypes(): void {
		$this->allAppsInstalled();
		$sources = $this->withDisabled('["files"]');

		$docTypes = $sources->effectiveEnabledDocTypes();
		$this->assertNotContains('file', $docTypes);
		$this->assertContains('note', $docTypes);
	}

	public function testUninstalledSourceExcludedEntirely(): void {
		// Only notes installed; everything else (except core files) disabled.
		$this->appManager->method('isEnabledForUser')
			->willReturnCallback(fn (string $app): bool => $app === 'notes');
		$sources = $this->withDisabled('[]');

		$installed = $sources->installedSources();
		$apps = array_column($installed, 'app');
		$this->assertContains('notes', $apps);
		$this->assertContains('files', $apps); // core
		$this->assertNotContains('deck', $apps);
		$this->assertNotContains('calendar', $apps);

		// Uninstalled deck contributes no doc_type even though not disabled.
		$this->assertNotContains('deck_card', $sources->effectiveEnabledDocTypes());
	}

	public function testInstalledSourcesCarryEnabledFlag(): void {
		$this->allAppsInstalled();
		$sources = $this->withDisabled('["deck"]');

		$byApp = [];
		foreach ($sources->installedSources() as $s) {
			$byApp[$s['app']] = $s['enabled'];
		}
		$this->assertFalse($byApp['deck']);
		$this->assertTrue($byApp['notes']);
	}

	public function testMalformedDisabledConfigTreatedAsEmpty(): void {
		$this->allAppsInstalled();
		$sources = $this->withDisabled('not json');
		$this->assertSame([], $sources->getDisabledSources());
	}

	public function testGetDisabledSourcesDropsUnknownIds(): void {
		$this->allAppsInstalled();
		$sources = $this->withDisabled('["files", "bogus", 5]');
		$this->assertSame(['files'], $sources->getDisabledSources());
	}

	public function testNormalizeSourceIdsFiltersAndDedupes(): void {
		$this->assertSame(
			['notes', 'files'],
			SearchSources::normalizeSourceIds(['notes', 'files', 'notes', 'nope', 42]),
		);
	}
}
