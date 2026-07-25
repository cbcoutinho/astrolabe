<?php

declare(strict_types=1);

namespace OCA\Astrolabe;

use OCA\Astrolabe\Service\Assistant\AssistantCapabilities;
use OCA\Astrolabe\Service\SearchSources;
use OCP\Capabilities\ICapability;

/**
 * Advertises which content sources are available for semantic search, and which
 * Assistant features this instance can actually serve.
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
	private AssistantCapabilities $assistant;

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		SearchSources $searchSources,
		AssistantCapabilities $assistant,
	) {
		$this->searchSources = $searchSources;
		$this->assistant = $assistant;
	}

	/**
	 * @return array{astrolabe: array{
	 *   semantic_search: array{
	 *     enabled_doc_types: list<string>,
	 *     sources: list<array{app: string, doc_types: list<string>, enabled: bool}>
	 *   },
	 *   assistant: array{summary_modes: list<string>}
	 * }}
	 */
	#[\Override]
	public function getCapabilities(): array {
		// Single pass for both outputs (the accumulation lives in SearchSources
		// so it isn't duplicated here).
		['sources' => $installed, 'enabledDocTypes' => $enabledDocTypes]
			= $this->searchSources->sourcesWithEnabledDocTypes();

		$sources = [];
		foreach ($installed as $source) {
			// ``label`` is intentionally omitted — it's a UI concern for the
			// admin page; the MCP server keys off ``app``/``doc_types`` and
			// renders no labels. Add it here if a consumer ever needs it.
			$sources[] = [
				'app' => $source['app'],
				'doc_types' => $source['docTypes'],
				'enabled' => $source['enabled'],
			];
		}

		return [
			'astrolabe' => [
				'semantic_search' => [
					'enabled_doc_types' => $enabledDocTypes,
					'sources' => $sources,
				],
				// Which AI features this instance can serve. Astrolabe supplies
				// retrieval only, so this depends on TaskProcessing providers the
				// admin installed separately — clients must gate on it rather than
				// assume the feature exists.
				'assistant' => [
					'summary_modes' => $this->assistant->getSummaryModes(),
				],
			],
		];
	}
}
