<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Settings\Admin;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserSession;

/**
 * Catalog of content sources that can be indexed/searched semantically, plus
 * the admin-consent logic that gates them.
 *
 * Each "source" is a Nextcloud app whose content maps to one or more vector
 * ``doc_type``s. A source is only available for semantic search when it is
 * BOTH installed AND admin-approved:
 *
 *  - Installed: the app is enabled for the (current) user. ``files`` is core,
 *    so it is always considered installed. Apps that are not installed are
 *    excluded entirely — they never appear in the admin "allowable sources"
 *    list nor in the exposed capability.
 *  - Approved: the source app id is NOT in the admin disabled-set. The set
 *    stores *disabled* ids (default empty) so the default is "all allowed" and
 *    sources added to the catalog in future releases are allowed by default.
 *
 * This is the single source of truth for the app↔doc_type mapping; the MCP
 * server mirrors a minimal copy when consuming the capability.
 */
class SearchSources {
	/**
	 * Canonical source catalog, keyed by source app id.
	 *
	 * @var array<string, array{docTypes: list<string>, label: string}>
	 */
	public const CATALOG = [
		'notes' => ['docTypes' => ['note'], 'label' => 'Notes'],
		'files' => ['docTypes' => ['file'], 'label' => 'Files'],
		'deck' => ['docTypes' => ['deck_card'], 'label' => 'Deck'],
		'news' => ['docTypes' => ['news_item'], 'label' => 'News'],
		'mail' => ['docTypes' => ['mail_message'], 'label' => 'Mail'],
		'calendar' => ['docTypes' => ['calendar'], 'label' => 'Calendar'],
		'contacts' => ['docTypes' => ['contact'], 'label' => 'Contacts'],
	];

	/**
	 * Per-user config key (IConfig user value) holding the JSON list of source
	 * app ids the user has disabled for *their own* semantic search. A user may
	 * only narrow within the admin-enabled set — never re-enable an admin-
	 * disabled source — so the effective disabled set is the union of the two.
	 */
	public const USER_SETTING_DISABLED_SEARCH_SOURCES = 'user_disabled_search_sources';
	public const USER_DEFAULT_DISABLED_SEARCH_SOURCES = '[]';

	private IAppManager $appManager;
	private IAppConfig $appConfig;
	private IConfig $config;
	private IUserSession $userSession;

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		IAppManager $appManager,
		IAppConfig $appConfig,
		IConfig $config,
		IUserSession $userSession,
	) {
		$this->appManager = $appManager;
		$this->appConfig = $appConfig;
		$this->config = $config;
		$this->userSession = $userSession;
	}

	/**
	 * Current session user id, or null when there is no session user.
	 */
	private function currentUserId(): ?string {
		return $this->userSession->getUser()?->getUID();
	}

	/**
	 * The current user's own disabled-source set (their personal narrowing).
	 *
	 * Empty when there is no session user (e.g. a system context), so per-user
	 * narrowing never applies outside an authenticated request. Unknown ids are
	 * dropped, like the admin set.
	 *
	 * @return list<string>
	 */
	public function getUserDisabledSources(): array {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return [];
		}
		$raw = $this->config->getUserValue(
			$userId,
			Application::APP_ID,
			self::USER_SETTING_DISABLED_SEARCH_SOURCES,
			self::USER_DEFAULT_DISABLED_SEARCH_SOURCES,
		);
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		return self::normalizeSourceIds($decoded);
	}

	/**
	 * Effective disabled set for the current user: admin-disabled ∪ user-disabled.
	 *
	 * This is what gates the capability, the search filter, and (via the MCP
	 * server) indexing for the requesting user.
	 *
	 * @return list<string>
	 */
	private function effectiveDisabledSources(): array {
		$combined = array_merge($this->getDisabledSources(), $this->getUserDisabledSources());
		return array_values(array_unique($combined));
	}

	/**
	 * Whether a source app is installed/enabled for the current session user.
	 *
	 * ``files`` is core functionality and always available. The current user is
	 * threaded explicitly so that group-restricted apps resolve per-user (the
	 * capability endpoint runs in the authenticated user's context); a null
	 * session user falls back to a global check inside ``isEnabledForUser``.
	 */
	public function isInstalled(string $appId): bool {
		if ($appId === 'files') {
			return true;
		}
		return $this->appManager->isEnabledForUser($appId, $this->userSession->getUser());
	}

	/**
	 * The admin disabled-set: source app ids excluded from semantic search.
	 *
	 * Unknown ids (e.g. a source removed from the catalog) are dropped so a
	 * stale config value can never disable something that no longer exists.
	 *
	 * @return list<string>
	 */
	public function getDisabledSources(): array {
		$raw = $this->appConfig->getValueString(
			Application::APP_ID,
			Admin::SETTING_DISABLED_SEARCH_SOURCES,
			Admin::DEFAULT_DISABLED_SEARCH_SOURCES,
		);
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		return self::normalizeSourceIds($decoded);
	}

	/**
	 * Build the installed sources with an ``enabled`` flag against a given
	 * disabled-set, in catalog order. Not-installed apps are omitted.
	 *
	 * @param list<string> $disabled
	 * @return list<array{app: string, docTypes: list<string>, label: string, enabled: bool}>
	 */
	private function buildSources(array $disabled): array {
		$sources = [];
		foreach (self::CATALOG as $appId => $meta) {
			if (!$this->isInstalled($appId)) {
				continue;
			}
			$sources[] = [
				'app' => $appId,
				'docTypes' => $meta['docTypes'],
				'label' => $meta['label'],
				'enabled' => !in_array($appId, $disabled, true),
			];
		}
		return $sources;
	}

	/**
	 * Installed sources with the TENANT enabled state, in catalog order.
	 *
	 * ``enabled`` reflects admin consent only (installed AND not in the admin
	 * disabled-set) — this is the admin settings view, deliberately NOT narrowed
	 * by the admin's own per-user choices.
	 *
	 * @return list<array{app: string, docTypes: list<string>, label: string, enabled: bool}>
	 */
	public function installedSources(): array {
		return $this->buildSources($this->getDisabledSources());
	}

	/**
	 * Installed sources with the EFFECTIVE (per-user) enabled state plus the
	 * flattened enabled doc-type list.
	 *
	 * ``enabled`` here reflects admin consent AND the current user's own
	 * narrowing (admin-disabled ∪ user-disabled). The OCS capability endpoint
	 * runs in the authenticated user's context, so exposing this makes
	 * ``enabled_doc_types`` per-user without any wire-shape change — the MCP
	 * server consumes it unchanged.
	 *
	 * @return array{
	 *   sources: list<array{app: string, docTypes: list<string>, label: string, enabled: bool}>,
	 *   enabledDocTypes: list<string>
	 * }
	 */
	public function sourcesWithEnabledDocTypes(): array {
		$sources = $this->buildSources($this->effectiveDisabledSources());
		$types = [];
		foreach ($sources as $source) {
			if ($source['enabled']) {
				foreach ($source['docTypes'] as $docType) {
					$types[] = $docType;
				}
			}
		}
		return [
			'sources' => $sources,
			'enabledDocTypes' => array_values(array_unique($types)),
		];
	}

	/**
	 * Doc types of sources that are installed AND enabled for the current user
	 * (admin-approved AND not narrowed away by the user).
	 *
	 * This is the authoritative allow-list applied to search and exposed via
	 * the capability.
	 *
	 * @return list<string>
	 */
	public function effectiveEnabledDocTypes(): array {
		return $this->sourcesWithEnabledDocTypes()['enabledDocTypes'];
	}

	/**
	 * Installed sources annotated for the personal settings UI: the admin
	 * ceiling (``tenantEnabled``) plus the user's own choice (``userEnabled``).
	 *
	 * A user can only toggle sources the admin has enabled; an admin-disabled
	 * source is reported with ``tenantEnabled=false`` and ``userEnabled=false``
	 * so the UI can render it locked.
	 *
	 * @return list<array{app: string, docTypes: list<string>, label: string, tenantEnabled: bool, userEnabled: bool}>
	 */
	public function userConfigurableSources(): array {
		$tenantDisabled = $this->getDisabledSources();
		$userDisabled = $this->getUserDisabledSources();
		$sources = [];
		foreach (self::CATALOG as $appId => $meta) {
			if (!$this->isInstalled($appId)) {
				continue;
			}
			$tenantEnabled = !in_array($appId, $tenantDisabled, true);
			$sources[] = [
				'app' => $appId,
				'docTypes' => $meta['docTypes'],
				'label' => $meta['label'],
				'tenantEnabled' => $tenantEnabled,
				// A user can't enable beyond the admin ceiling.
				'userEnabled' => $tenantEnabled && !in_array($appId, $userDisabled, true),
			];
		}
		return $sources;
	}

	/**
	 * The source app id that owns a given doc type, or null if unknown.
	 *
	 * Reverse of {@see self::CATALOG}. Lets callers (e.g. the access-check
	 * registry) map a result's doc type back to its source app so the same
	 * installed-apps gate used for indexing/search applies uniformly.
	 */
	public static function sourceForDocType(string $docType): ?string {
		foreach (self::CATALOG as $appId => $meta) {
			if (in_array($docType, $meta['docTypes'], true)) {
				return $appId;
			}
		}
		return null;
	}

	/**
	 * Flatten the catalog doc types for a list of (valid) source app ids.
	 *
	 * @param list<string> $sourceIds
	 * @return list<string>
	 */
	public static function docTypesForSources(array $sourceIds): array {
		$types = [];
		foreach ($sourceIds as $sourceId) {
			foreach (self::CATALOG[$sourceId]['docTypes'] as $docType) {
				$types[] = $docType;
			}
		}
		return array_values(array_unique($types));
	}

	/**
	 * Filter an arbitrary list down to valid, unique source app ids.
	 *
	 * @param array<mixed> $ids
	 * @return list<string>
	 */
	public static function normalizeSourceIds(array $ids): array {
		$valid = [];
		/** @var mixed $id */
		foreach ($ids as $id) {
			if (is_string($id) && isset(self::CATALOG[$id]) && !in_array($id, $valid, true)) {
				$valid[] = $id;
			}
		}
		return $valid;
	}
}
