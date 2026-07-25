<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Assistant;

use OCA\Astrolabe\Service\Access\CalendarAccessVerifier;
use OCA\Astrolabe\Service\Access\DeckAccessVerifier;
use OCA\Astrolabe\Service\Access\DocumentAccessService;
use OCA\Astrolabe\Service\Access\FileAccessVerifier;
use OCA\Astrolabe\Service\Access\MailAccessVerifier;
use OCA\Astrolabe\Service\Assistant\AssistantCapabilities;
use OCA\Astrolabe\Service\Assistant\SummaryException;
use OCA\Astrolabe\Service\Assistant\SummaryService;
use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\McpTokenMinter;
use OCA\Astrolabe\Service\McpTokenMintException;
use OCA\Astrolabe\Service\SearchSources;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\AnalyzeImages;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tier selection, the access guard, and the failure modes the chunk viewer has to
 * render. The multimodal tier is preferred whenever the caller supplied pages and
 * a vision provider exists; everything else falls back to extracted text.
 */
final class SummaryServiceTest extends TestCase {
	private IManager&MockObject $taskProcessing;
	private AssistantCapabilities&MockObject $capabilities;
	private SearchSources&MockObject $searchSources;
	private Folder&MockObject $userFolder;
	private McpServerClient&MockObject $client;
	private McpTokenMinter&MockObject $tokenMinter;

	protected function setUp(): void {
		parent::setUp();
		$this->taskProcessing = $this->createMock(IManager::class);
		$this->capabilities = $this->createMock(AssistantCapabilities::class);
		$this->searchSources = $this->createMock(SearchSources::class);
		$this->client = $this->createMock(McpServerClient::class);
		$this->tokenMinter = $this->createMock(McpTokenMinter::class);
		$this->userFolder = $this->createMock(Folder::class);

		$this->tokenMinter->method('mintForUser')->willReturn('jwt');
	}

	/**
	 * DocumentAccessService is final, so tests wire the real thing to mocked leaf
	 * deps and drive decisions through SearchSources::isInstalled — the same
	 * approach as AbstractApiControllerTestCase. Left uninstalled by default, every
	 * check DELEGATEs, which is the allow-and-let-the-MCP-backstop-decide path.
	 */
	private function documentAccess(): DocumentAccessService {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($this->userFolder);

		return new DocumentAccessService(
			new FileAccessVerifier($rootFolder, $this->createMock(LoggerInterface::class)),
			new DeckAccessVerifier(),
			new MailAccessVerifier(),
			new CalendarAccessVerifier(),
			$this->searchSources,
		);
	}

	private function service(): SummaryService {
		return new SummaryService(
			$this->taskProcessing,
			$this->capabilities,
			$this->documentAccess(),
			$this->client,
			$this->tokenMinter,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * Tasks get their id assigned by the manager on schedule, so the fake has to
	 * do the same or the service cannot return one.
	 */
	private function expectScheduled(int $taskId = 42): void {
		$this->taskProcessing->method('scheduleTask')
			->willReturnCallback(static function (Task $task) use ($taskId): void {
				$task->setId($taskId);
			});
	}

	/**
	 * @param list<int> $images
	 * @return array{task_id: int, mode: string}
	 */
	private function schedule(array $images = []): array {
		return $this->service()->schedule(
			'alice', 'file', '123', 0, 900, 0, 4, ['path' => '/Reports/q3.pdf'], $images,
		);
	}

	public function testPrefersImagesWhenPagesSuppliedAndVisionAvailable(): void {
		$this->capabilities->method('getSummaryModes')->willReturn(['analyze-images', 'text2text']);
		$this->expectScheduled();

		$captured = null;
		$this->taskProcessing->method('scheduleTask')
			->willReturnCallback(static function (Task $task) use (&$captured): void {
				$task->setId(42);
				$captured = $task;
			});

		$result = $this->schedule([11, 12]);

		$this->assertSame(['task_id' => 42, 'mode' => 'analyze-images'], $result);
		$this->assertInstanceOf(Task::class, $captured);
		$this->assertSame(AnalyzeImages::ID, $captured->getTaskTypeId());
		$this->assertSame([11, 12], $captured->getInput()['images']);
		// The text path must not have been consulted at all.
		$this->client->expects($this->never())->method('getChunkContext');
	}

	/**
	 * The common instance shape: a text LLM but no multimodal model. Pages were
	 * rendered and offered, and are simply ignored rather than failing the request.
	 */
	public function testFallsBackToTextWhenVisionUnavailable(): void {
		$this->capabilities->method('getSummaryModes')->willReturn(['text2text']);
		$this->client->method('getChunkContext')->willReturn([
			'before_context' => 'before ',
			'chunk_text' => 'chunk',
			'after_context' => ' after',
		]);
		$this->expectScheduled(7);

		$result = $this->schedule([11, 12]);

		$this->assertSame('text2text', $result['mode']);
		$this->assertSame(7, $result['task_id']);
	}

	public function testTextTierAsksForTheWidestWindowTheServerAllows(): void {
		$this->capabilities->method('getSummaryModes')->willReturn(['text2text']);
		$this->expectScheduled();
		$this->client->expects($this->once())
			->method('getChunkContext')
			->with('file', '123', 0, 900, 'jwt', 0, 4, McpServerClient::MAX_CHUNK_CONTEXT)
			->willReturn(['chunk_text' => 'body']);

		$this->schedule();
	}

	public function testStitchesContextAroundTheChunk(): void {
		$this->capabilities->method('getSummaryModes')->willReturn(['text2text']);
		$this->client->method('getChunkContext')->willReturn([
			'before_context' => 'lead-in. ',
			'chunk_text' => 'the match.',
			'after_context' => ' trailing.',
		]);

		$captured = null;
		$this->taskProcessing->method('scheduleTask')
			->willReturnCallback(static function (Task $task) use (&$captured): void {
				$task->setId(1);
				$captured = $task;
			});

		$this->schedule();

		$this->assertInstanceOf(Task::class, $captured);
		$this->assertSame(TextToTextSummary::ID, $captured->getTaskTypeId());
		$this->assertStringStartsWith('lead-in. the match. trailing.', (string)$captured->getInput()['input']);
	}

	/**
	 * Vision tokens dominate the cost of these calls, so the page count is a
	 * deliberate spend cap — a caller offering more must not be able to spend past it.
	 */
	public function testCapsThePageCount(): void {
		$this->capabilities->method('getSummaryModes')->willReturn(['analyze-images']);

		$captured = null;
		$this->taskProcessing->method('scheduleTask')
			->willReturnCallback(static function (Task $task) use (&$captured): void {
				$task->setId(1);
				$captured = $task;
			});

		$this->schedule(range(1, 50));

		$this->assertInstanceOf(Task::class, $captured);
		$this->assertCount(SummaryService::MAX_IMAGES, $captured->getInput()['images']);
	}

	public function testDropsDuplicateAndInvalidFileIds(): void {
		$this->capabilities->method('getSummaryModes')->willReturn(['analyze-images']);

		$captured = null;
		$this->taskProcessing->method('scheduleTask')
			->willReturnCallback(static function (Task $task) use (&$captured): void {
				$task->setId(1);
				$captured = $task;
			});

		$this->schedule([5, 5, 0, -3, 6]);

		$this->assertInstanceOf(Task::class, $captured);
		$this->assertSame([5, 6], $captured->getInput()['images']);
	}

	/**
	 * The access check runs before any token is minted or any call leaves the
	 * instance, so a revoked share cannot be laundered into an LLM prompt.
	 */
	public function testDeniedAccessSchedulesNothing(): void {
		// files installed + the id resolving to nothing in the user's folder is the
		// revoked-share shape: indexed once, unreachable now.
		$this->searchSources->method('isInstalled')->willReturn(true);
		$this->userFolder->method('getById')->willReturn([]);
		$this->capabilities->method('getSummaryModes')->willReturn(['analyze-images', 'text2text']);

		$this->taskProcessing->expects($this->never())->method('scheduleTask');
		$this->tokenMinter->expects($this->never())->method('mintForUser');
		$this->client->expects($this->never())->method('getChunkContext');

		try {
			$this->schedule([11]);
			$this->fail('expected a SummaryException');
		} catch (SummaryException $e) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $e->getStatusCode());
		}
	}

	public function testNoProviderIsServiceUnavailable(): void {
		$this->capabilities->method('getSummaryModes')->willReturn([]);
		$this->taskProcessing->expects($this->never())->method('scheduleTask');

		try {
			$this->schedule([1, 2]);
			$this->fail('expected a SummaryException');
		} catch (SummaryException $e) {
			$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $e->getStatusCode());
		}
	}

	public function testEmptyDocumentTextIsRejectedRatherThanSummarized(): void {
		$this->capabilities->method('getSummaryModes')->willReturn(['text2text']);
		$this->client->method('getChunkContext')->willReturn(['chunk_text' => '   ']);
		$this->taskProcessing->expects($this->never())->method('scheduleTask');

		try {
			$this->schedule();
			$this->fail('expected a SummaryException');
		} catch (SummaryException $e) {
			$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $e->getStatusCode());
		}
	}

	public function testMcpErrorSurfacesAsBadGateway(): void {
		$this->capabilities->method('getSummaryModes')->willReturn(['text2text']);
		$this->client->method('getChunkContext')->willReturn(['error' => 'connection refused']);

		try {
			$this->schedule();
			$this->fail('expected a SummaryException');
		} catch (SummaryException $e) {
			$this->assertSame(Http::STATUS_BAD_GATEWAY, $e->getStatusCode());
		}
	}

	public function testTokenMintFailureIsServiceUnavailable(): void {
		$this->capabilities->method('getSummaryModes')->willReturn(['text2text']);
		$this->tokenMinter = $this->createMock(McpTokenMinter::class);
		$this->tokenMinter->method('mintForUser')
			->willThrowException(new McpTokenMintException('no client id'));

		try {
			$this->schedule();
			$this->fail('expected a SummaryException');
		} catch (SummaryException $e) {
			$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $e->getStatusCode());
		}
	}
}
