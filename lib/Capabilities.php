<?php

declare(strict_types=1);

namespace OCA\Astrolabe;

use OCA\Astrolabe\Service\SearchSources;
use OCP\Capabilities\ICapability;

/**
 * Advertises which content sources are available for semantic search.
 *
 * Exposed under ``astrolabe.semantic_search`` on the OCS capabilities endpoint
 * (``/ocs/v2.php/cloud/capabilities``). The MCP server reads this as the single
 * source of truth for admin consent: it filters search results and gates
 * indexing/purging by ``enabled_doc_types``.
 *
 * Capabilities are resolved in the authenticated user's context, so the values
 * reflect that user's installed apps intersected with the admin's global
 * approval. ``sources`` lists installed sources only.
 */
final class Capabilities implements ICapability {
	private SearchSources $searchSources;

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(SearchSources $searchSources) {
		$this->searchSources = $searchSources;
	}

	/**
	 * @return array{astrolabe: array{semantic_search: array{
	 *   enabled_doc_types: list<string>,
	 *   sources: list<array{app: string, doc_types: list<string>, enabled: bool}>
	 * }}}
	 */
	#[\Override]
	public function getCapabilities(): array {
		// Compute both outputs from a single installedSources() pass (it touches
		// IAppManager/IAppConfig), rather than also calling
		// effectiveEnabledDocTypes() which would iterate it again.
		$sources = [];
		$enabledDocTypes = [];
		foreach ($this->searchSources->installedSources() as $source) {
			// ``label`` is intentionally omitted — it's a UI concern for the
			// admin page; the MCP server keys off ``app``/``doc_types`` and
			// renders no labels. Add it here if a consumer ever needs it.
			$sources[] = [
				'app' => $source['app'],
				'doc_types' => $source['docTypes'],
				'enabled' => $source['enabled'],
			];
			if ($source['enabled']) {
				foreach ($source['docTypes'] as $docType) {
					$enabledDocTypes[] = $docType;
				}
			}
		}

		return [
			'astrolabe' => [
				'semantic_search' => [
					'enabled_doc_types' => array_values(array_unique($enabledDocTypes)),
					'sources' => $sources,
				],
			],
		];
	}
}
