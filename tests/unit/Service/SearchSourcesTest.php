<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service;

use OCA\Astrolabe\Service\SearchSources;
use OCA\Astrolabe\Settings\Admin;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
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
	private IConfig&MockObject $config;
	private IUserSession&MockObject $userSession;

	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->config = $this->createMock(IConfig::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * Build a SearchSources with the given admin (tenant) disabled-set and,
	 * optionally, the current user's personal disabled-set.
	 */
	private function withDisabled(string $json, string $userJson = '[]'): SearchSources {
		$this->appConfig->method('getValueString')
			->with('astrolabe', Admin::SETTING_DISABLED_SEARCH_SOURCES, Admin::DEFAULT_DISABLED_SEARCH_SOURCES)
			->willReturn($json);
		$this->config->method('getUserValue')
			->with(
				'alice',
				'astrolabe',
				SearchSources::USER_SETTING_DISABLED_SEARCH_SOURCES,
				SearchSources::USER_DEFAULT_DISABLED_SEARCH_SOURCES,
			)
			->willReturn($userJson);
		return new SearchSources($this->appManager, $this->appConfig, $this->config, $this->userSession);
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
			['note', 'file', 'deck_card', 'news_item', 'mail_message', 'calendar', 'contact'],
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
		$this->assertNotContains('mail', $apps);

		// Uninstalled deck/mail contribute no doc_type even though not disabled.
		$docTypes = $sources->effectiveEnabledDocTypes();
		$this->assertNotContains('deck_card', $docTypes);
		$this->assertNotContains('mail_message', $docTypes);
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

	public function testDocTypesForSourcesFlattensAndDedupes(): void {
		$this->assertSame(
			['note', 'file', 'deck_card'],
			SearchSources::docTypesForSources(['notes', 'files', 'deck']),
		);
		$this->assertSame([], SearchSources::docTypesForSources([]));
	}

	public function testNormalizeSourceIdsFiltersAndDedupes(): void {
		$this->assertSame(
			['notes', 'files'],
			SearchSources::normalizeSourceIds(['notes', 'files', 'notes', 'nope', 42]),
		);
	}

	// --- Per-user narrowing -------------------------------------------------

	public function testUserDisabledNarrowsEffectiveDocTypes(): void {
		$this->allAppsInstalled();
		// Admin allows everything; the user turns off notes for themselves.
		$sources = $this->withDisabled('[]', '["notes"]');

		$docTypes = $sources->effectiveEnabledDocTypes();
		$this->assertNotContains('note', $docTypes);
		$this->assertContains('file', $docTypes);
	}

	public function testUserCannotExceedAdminCeiling(): void {
		$this->allAppsInstalled();
		// Admin disabled files; the user "enabling" it (absent from user set)
		// must NOT bring it back — effective excludes file.
		$sources = $this->withDisabled('["files"]', '[]');
		$this->assertNotContains('file', $sources->effectiveEnabledDocTypes());
	}

	public function testInstalledSourcesIsTenantOnlyIgnoringUserNarrowing(): void {
		$this->allAppsInstalled();
		// User disabled notes, but the admin (tenant) view is unaffected.
		$sources = $this->withDisabled('[]', '["notes"]');

		$byApp = [];
		foreach ($sources->installedSources() as $s) {
			$byApp[$s['app']] = $s['enabled'];
		}
		$this->assertTrue($byApp['notes']);
	}

	public function testUserConfigurableSourcesAnnotatesTenantAndUser(): void {
		$this->allAppsInstalled();
		// Admin disabled deck; user disabled notes.
		$sources = $this->withDisabled('["deck"]', '["notes"]');

		$byApp = [];
		foreach ($sources->userConfigurableSources() as $s) {
			$byApp[$s['app']] = $s;
		}
		// Admin-disabled: locked off for the user.
		$this->assertFalse($byApp['deck']['tenantEnabled']);
		$this->assertFalse($byApp['deck']['userEnabled']);
		// User-disabled within an admin-enabled source.
		$this->assertTrue($byApp['notes']['tenantEnabled']);
		$this->assertFalse($byApp['notes']['userEnabled']);
		// Untouched: enabled for both.
		$this->assertTrue($byApp['files']['tenantEnabled']);
		$this->assertTrue($byApp['files']['userEnabled']);
	}

	public function testMalformedUserDisabledConfigTreatedAsEmpty(): void {
		$this->allAppsInstalled();
		$sources = $this->withDisabled('[]', 'not json');
		$this->assertSame([], $sources->getUserDisabledSources());
	}

	public function testNoSessionUserYieldsAdminCeilingOnly(): void {
		// A system context (no session user) gets no per-user narrowing — only
		// the admin ceiling applies. Guards against silently exposing all
		// sources, or crashing on a null user.
		$this->allAppsInstalled();
		$this->appConfig->method('getValueString')->willReturn('["files"]');
		$noUserSession = $this->createMock(IUserSession::class);
		$noUserSession->method('getUser')->willReturn(null);
		$sources = new SearchSources(
			$this->appManager,
			$this->appConfig,
			$this->config,
			$noUserSession,
		);

		$this->assertSame([], $sources->getUserDisabledSources());
		$docTypes = $sources->effectiveEnabledDocTypes();
		$this->assertNotContains('file', $docTypes); // admin-disabled
		$this->assertContains('note', $docTypes); // admin-enabled, no user narrowing
	}

	/**
	 * @dataProvider provideDocTypeSources
	 */
	public function testSourceForDocType(string $docType, ?string $expectedApp): void {
		$this->assertSame($expectedApp, SearchSources::sourceForDocType($docType));
	}

	/**
	 * @return array<string, array{string, string|null}>
	 */
	public static function provideDocTypeSources(): array {
		return [
			'file' => ['file', 'files'],
			'note' => ['note', 'notes'],
			'deck_card' => ['deck_card', 'deck'],
			'mail_message' => ['mail_message', 'mail'],
			'calendar' => ['calendar', 'calendar'],
			'contact' => ['contact', 'contacts'],
			'news_item' => ['news_item', 'news'],
			'unknown' => ['nope', null],
		];
	}
}
