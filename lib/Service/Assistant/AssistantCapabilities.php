<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Assistant;

use OCA\Astrolabe\AppInfo\Application;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\TaskTypes\AnalyzeImages;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use Psr\Log\LoggerInterface;

/**
 * What Astrolabe's Assistant features can actually do on this instance.
 *
 * Astrolabe supplies retrieval, never generation: the MCP server has no LLM on
 * its request path (`nc_semantic_search_answer` delegates generation to the MCP
 * *client* via sampling, and there is no `/api/v1/answer`). Every generated word
 * therefore comes from whatever TaskProcessing provider the admin installed —
 * llm2, integration_openai, integration_ollama, and so on.
 *
 * That makes availability a per-instance question rather than a per-release one,
 * which is why this is advertised as a capability instead of assumed: an admin
 * running no text provider must get a hidden button, and one running a text
 * provider but no vision model must silently get the text tier.
 *
 * A task type is "available" iff some provider registered for it, so these checks
 * read directly off {@see IManager::getAvailableTaskTypeIds()}.
 *
 * @psalm-suppress ClassMustBeFinal — kept non-final so it can be mocked in the
 *   controller unit tests, mirroring the other Service classes.
 */
class AssistantCapabilities {
	/**
	 * Rich, layout-aware summaries: the document's pages go to a multimodal model
	 * as images, so tables, figures and stamps survive. Backed by
	 * `core:analyze-images`.
	 */
	public const SUMMARY_MODE_IMAGES = 'analyze-images';

	/**
	 * Plain-text summaries built from already-extracted text. Backed by
	 * `core:text2text:summary`. The fallback when no vision model is installed.
	 */
	public const SUMMARY_MODE_TEXT = 'text2text';

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private IManager $taskProcessing,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Summary tiers this instance can serve, best-first.
	 *
	 * Empty means no provider can summarize anything, and the UI must hide the
	 * action outright rather than offering a button that always fails.
	 *
	 * @return list<string>
	 */
	public function getSummaryModes(): array {
		$available = $this->availableTaskTypeIds();

		$modes = [];
		if (in_array(AnalyzeImages::ID, $available, true)) {
			$modes[] = self::SUMMARY_MODE_IMAGES;
		}
		if (in_array(TextToTextSummary::ID, $available, true)) {
			$modes[] = self::SUMMARY_MODE_TEXT;
		}
		return $modes;
	}

	/**
	 * @return list<string>
	 */
	private function availableTaskTypeIds(): array {
		try {
			return $this->taskProcessing->getAvailableTaskTypeIds();
		} catch (\Throwable $e) {
			// Capabilities are served on every OCS capabilities request and the
			// page render, so a TaskProcessing hiccup must degrade to "no AI
			// features" rather than break the app shell.
			$this->logger->warning(
				'Could not read available TaskProcessing task types',
				['exception' => $e, 'app' => Application::APP_ID],
			);
			return [];
		}
	}
}
