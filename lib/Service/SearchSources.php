<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Settings\Admin;
use OCP\App\IAppManager;
use OCP\IAppConfig;
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
		'calendar' => ['docTypes' => ['calendar'], 'label' => 'Calendar'],
		'contacts' => ['docTypes' => ['contact'], 'label' => 'Contacts'],
	];

	private IAppManager $appManager;
	private IAppConfig $appConfig;
	private IUserSession $userSession;

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		IAppManager $appManager,
		IAppConfig $appConfig,
		IUserSession $userSession,
	) {
		$this->appManager = $appManager;
		$this->appConfig = $appConfig;
		$this->userSession = $userSession;
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
	 * Installed sources with their current enabled state, in catalog order.
	 *
	 * Not-installed apps are omitted. ``enabled`` reflects admin consent
	 * (installed AND not in the disabled-set).
	 *
	 * @return list<array{app: string, docTypes: list<string>, label: string, enabled: bool}>
	 */
	public function installedSources(): array {
		$disabled = $this->getDisabledSources();
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
	 * Installed sources together with the flattened enabled doc-type list, in a
	 * single pass.
	 *
	 * Both outputs derive from one ``installedSources()`` sweep (which touches
	 * IAppManager/IAppConfig), so the capability endpoint can expose both
	 * without iterating twice and without duplicating the accumulation loop.
	 *
	 * @return array{
	 *   sources: list<array{app: string, docTypes: list<string>, label: string, enabled: bool}>,
	 *   enabledDocTypes: list<string>
	 * }
	 */
	public function sourcesWithEnabledDocTypes(): array {
		$sources = $this->installedSources();
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
	 * Doc types of sources that are installed AND admin-approved.
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
