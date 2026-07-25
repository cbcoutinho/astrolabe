<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Assistant;

use OCA\Astrolabe\Service\Assistant\AssistantCapabilities;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\TaskTypes\AnalyzeImages;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Astrolabe never generates text itself, so every Assistant feature is gated on a
 * TaskProcessing provider the admin installed separately. These tests pin the
 * degradation behaviour: missing providers must remove features, not break.
 */
final class AssistantCapabilitiesTest extends TestCase {
	private IManager&MockObject $taskProcessing;

	protected function setUp(): void {
		parent::setUp();
		$this->taskProcessing = $this->createMock(IManager::class);
	}

	/**
	 * @param list<string> $availableTaskTypes
	 */
	private function service(array $availableTaskTypes): AssistantCapabilities {
		$this->taskProcessing->method('getAvailableTaskTypeIds')->willReturn($availableTaskTypes);

		return new AssistantCapabilities(
			$this->taskProcessing,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testPrefersImageSummariesWhenAVisionProviderExists(): void {
		$modes = $this->service([AnalyzeImages::ID, TextToTextSummary::ID])->getSummaryModes();

		// Order matters: the richer tier is offered first.
		$this->assertSame(
			[AssistantCapabilities::SUMMARY_MODE_IMAGES, AssistantCapabilities::SUMMARY_MODE_TEXT],
			$modes,
		);
	}

	/**
	 * The common case: a text LLM is installed but no multimodal model. The rich
	 * tier disappears and the text tier carries the feature.
	 */
	public function testFallsBackToTextWithoutAVisionProvider(): void {
		$this->assertSame(
			[AssistantCapabilities::SUMMARY_MODE_TEXT],
			$this->service([TextToTextSummary::ID])->getSummaryModes(),
		);
	}

	public function testNoSummaryModesWithoutAnyProvider(): void {
		$this->assertSame([], $this->service([])->getSummaryModes());
	}

	/**
	 * Capabilities are served on every OCS capabilities request and page render, so
	 * a TaskProcessing failure must degrade to "no AI features" rather than break
	 * the app shell.
	 */
	public function testTaskProcessingFailureDegradesToNoFeatures(): void {
		$this->taskProcessing->method('getAvailableTaskTypeIds')
			->willThrowException(new \RuntimeException('task processing is down'));

		$service = new AssistantCapabilities(
			$this->taskProcessing,
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame([], $service->getSummaryModes());
	}
}
