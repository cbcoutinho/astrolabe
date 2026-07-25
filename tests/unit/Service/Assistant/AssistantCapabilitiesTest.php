<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Assistant;

use OCA\Astrolabe\Service\Assistant\AssistantCapabilities;
use OCP\IAppConfig;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\TaskTypes\AnalyzeImages;
use OCP\TaskProcessing\TaskTypes\TextToTextChatWithTools;
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
	private IAppConfig&MockObject $appConfig;

	protected function setUp(): void {
		parent::setUp();
		$this->taskProcessing = $this->createMock(IManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
	}

	/**
	 * @param list<string> $availableTaskTypes
	 */
	private function service(array $availableTaskTypes, bool $agentEnabled = false): AssistantCapabilities {
		$this->taskProcessing->method('getAvailableTaskTypeIds')->willReturn($availableTaskTypes);
		$this->appConfig->method('getValueBool')->willReturn($agentEnabled);

		return new AssistantCapabilities(
			$this->taskProcessing,
			$this->appConfig,
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
	 * The agent needs both halves: admin consent and a tool-calling model. Consent
	 * alone would register a provider that fails on its first tool call, and a
	 * model without consent would silently take over the Assistant's chat.
	 */
	public function testAgentNeedsBothOptInAndAToolCallingModel(): void {
		$this->assertFalse(
			$this->service([TextToTextChatWithTools::ID], agentEnabled: false)->isAgentAvailable(),
			'opt-in is required',
		);
	}

	public function testAgentUnavailableWithoutAToolCallingModel(): void {
		$this->assertFalse(
			$this->service([TextToTextSummary::ID], agentEnabled: true)->isAgentAvailable(),
			'a summary-only provider cannot drive the agent loop',
		);
	}

	public function testAgentAvailableWhenOptedInWithAToolCallingModel(): void {
		$this->assertTrue(
			$this->service([TextToTextChatWithTools::ID], agentEnabled: true)->isAgentAvailable(),
		);
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
			$this->appConfig,
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame([], $service->getSummaryModes());
	}
}
