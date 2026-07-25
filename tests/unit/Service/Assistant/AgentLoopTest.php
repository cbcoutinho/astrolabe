<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Assistant;

use Mcp\Client;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use OCA\Astrolabe\Service\Assistant\AgentException;
use OCA\Astrolabe\Service\Assistant\AgentLoop;
use OCA\Astrolabe\Service\Mcp\McpClientFactory;
use OCA\Astrolabe\Service\Mcp\McpUnavailableException;
use OCA\Astrolabe\Settings\Admin as AdminSettings;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToTextChatWithTools;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The loop between a tool-calling model and the MCP server.
 *
 * Weighted towards the wire format and the budget, because that is where the
 * bugs actually were: tool calls arrive with an `args` key rather than
 * `arguments`, and reading the wrong one silently invokes every tool with no
 * arguments — which presents as the model asking useless questions rather than
 * as a parsing bug.
 */
final class AgentLoopTest extends TestCase {
	private IManager&MockObject $taskProcessing;
	private McpClientFactory&MockObject $clientFactory;
	private Client&MockObject $client;
	private IAppConfig&MockObject $appConfig;
	private int $maxIterations = 8;
	private int $timeout = 120;

	protected function setUp(): void {
		parent::setUp();
		$this->taskProcessing = $this->createMock(IManager::class);
		$this->clientFactory = $this->createMock(McpClientFactory::class);
		$this->client = $this->createMock(Client::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->clientFactory->method('connect')->willReturn($this->client);
		$this->withTools(['nc_semantic_search']);
	}

	/**
	 * Settings are bound here rather than in setUp: a PHPUnit stub keeps its
	 * first configuration, so a per-test override has to happen before the mock
	 * is ever configured.
	 */
	private function loop(): AgentLoop {
		$this->appConfig->method('getValueString')->willReturn(AdminSettings::DEFAULT_AGENT_SCOPES);
		$maxIterations = $this->maxIterations;
		$timeout = $this->timeout;
		$this->appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => match ($key) {
				AdminSettings::SETTING_AGENT_MAX_ITERATIONS => $maxIterations,
				AdminSettings::SETTING_AGENT_TIMEOUT => $timeout,
				default => $default,
			},
		);

		return new AgentLoop(
			$this->taskProcessing,
			$this->clientFactory,
			$this->appConfig,
			$this->urlGenerator(),
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * @param list<string> $names
	 */
	/**
	 * A stub URL generator rather than a mocked collector: the loop builds its
	 * own collector per turn (it is stateful, and a worker serves many tasks), so
	 * citations are asserted through real link construction.
	 */
	private function urlGenerator(): IURLGenerator {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/apps/astrolabe/');
		$urlGenerator->method('getAbsoluteURL')
			->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);
		return $urlGenerator;
	}

	/**
	 * @param list<string> $names
	 */
	private function withTools(array $names): void {
		$tools = array_map(
			static fn (string $name): Tool => new Tool(
				$name,
				null,
				['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
				'Search things',
				null,
			),
			$names,
		);
		$this->client->method('listTools')->willReturn(new ListToolsResult($tools));
	}

	/**
	 * Queue model turns. Each entry becomes one runTask result, so a two-entry
	 * queue is "call a tool, then answer".
	 *
	 * Returns an accessor rather than the array itself: the closure appends by
	 * reference, so a returned array would be a copy taken before any turn ran.
	 *
	 * @param list<array{output: string, tool_calls: string}> $turns
	 * @return \Closure(): list<Task>
	 */
	private function withModelTurns(array $turns): \Closure {
		$captured = [];
		$i = 0;
		$this->taskProcessing->method('runTask')->willReturnCallback(
			function (Task $task) use (&$captured, &$i, $turns): Task {
				$captured[] = $task;
				$turn = $turns[$i] ?? ['output' => 'done', 'tool_calls' => ''];
				$i++;
				$task->setStatus(Task::STATUS_SUCCESSFUL);
				$task->setOutput(['output' => $turn['output'], 'tool_calls' => $turn['tool_calls']]);
				return $task;
			},
		);
		return static function () use (&$captured): array {
			return $captured;
		};
	}

	private function withToolResult(string $text): void {
		$this->client->method('callTool')->willReturn(new CallToolResult([new TextContent($text)]));
	}

	public function testAnswersWithoutCallingToolsWhenTheModelDoesNotAskTo(): void {
		$this->withModelTurns([['output' => 'Paris.', 'tool_calls' => '']]);
		$this->client->expects($this->never())->method('callTool');

		$result = $this->loop()->run('alice', 'Capital of France?', []);

		$this->assertSame('Paris.', $result['output']);
		$this->assertSame([], $result['sources']);
		$this->assertStringNotContainsString('Sources', $result['output'], 'no tools, so nothing to cite');
	}

	/**
	 * integration_openai emits `args`. Reading only `arguments` yields an empty
	 * argument list for every call, so the tool runs but answers nothing useful.
	 */
	public function testParsesArgumentsFromTheArgsKey(): void {
		$this->withModelTurns([
			['output' => '', 'tool_calls' => json_encode([[
				'name' => 'nc_semantic_search',
				'id' => 'call-1',
				'args' => ['query' => 'kubernetes networking'],
			]])],
			['output' => 'It uses Cilium.', 'tool_calls' => ''],
		]);

		$this->client->expects($this->once())
			->method('callTool')
			->with('nc_semantic_search', ['query' => 'kubernetes networking'])
			->willReturn(new CallToolResult([new TextContent('Cilium CNI with eBPF')]));

		$result = $this->loop()->run('alice', 'How is networking set up?', []);

		$this->assertStringContainsString('It uses Cilium.', $result['output']);
		// A tool returning prose yields no citations — only structured results do.
		$this->assertSame([], $result['sources']);
	}

	/**
	 * @dataProvider toolCallShapes
	 */
	public function testAcceptsTheToolCallShapesProvidersActuallyEmit(string $toolCallsJson): void {
		$this->withModelTurns([
			['output' => '', 'tool_calls' => $toolCallsJson],
			['output' => 'answered', 'tool_calls' => ''],
		]);
		$this->client->expects($this->once())
			->method('callTool')
			->with('nc_semantic_search', ['query' => 'kubernetes'])
			->willReturn(new CallToolResult([new TextContent('result')]));

		$this->assertSame('answered', $this->loop()->run('alice', 'q', [])['output']);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function toolCallShapes(): array {
		return [
			'args key (integration_openai)' => [
				json_encode([['name' => 'nc_semantic_search', 'args' => ['query' => 'kubernetes']]]),
			],
			'arguments key' => [
				json_encode([['name' => 'nc_semantic_search', 'arguments' => ['query' => 'kubernetes']]]),
			],
			'nested under function' => [
				json_encode([['function' => ['name' => 'nc_semantic_search', 'arguments' => ['query' => 'kubernetes']]]]),
			],
			'arguments as a JSON string' => [
				json_encode([['name' => 'nc_semantic_search', 'arguments' => '{"query":"kubernetes"}']]),
			],
			'wrapped in a tool_calls object' => [
				json_encode(['tool_calls' => [['name' => 'nc_semantic_search', 'args' => ['query' => 'kubernetes']]]]),
			],
		];
	}

	/**
	 * A model's tool_calls is free-form text until proven otherwise, so garbage
	 * must cost one observation rather than the whole turn.
	 *
	 * @dataProvider malformedToolCalls
	 */
	public function testIgnoresMalformedToolCalls(string $raw): void {
		$this->withModelTurns([['output' => 'answered anyway', 'tool_calls' => $raw]]);
		$this->client->expects($this->never())->method('callTool');

		$this->assertSame('answered anyway', $this->loop()->run('alice', 'q', [])['output']);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function malformedToolCalls(): array {
		return [
			'not json' => ['<tool>search</tool>'],
			'json scalar' => ['"nope"'],
			'entry without a name' => ['[{"args":{"query":"x"}}]'],
			'entry with an empty name' => ['[{"name":"","args":{}}]'],
			'entry that is not an object' => ['["nc_semantic_search"]'],
			'empty string' => [''],
		];
	}

	public function testStopsAtTheIterationCapAndSaysSo(): void {
		$this->maxIterations = 2;
		$this->timeout = 600;
		// Always asks for another tool: the model never converges.
		$this->withModelTurns(array_fill(0, 5, [
			'output' => '',
			'tool_calls' => json_encode([['name' => 'nc_semantic_search', 'args' => ['query' => 'x']]]),
		]));
		$this->withToolResult('some result');

		$result = $this->loop()->run('alice', 'q', []);

		// Distinct from the timeout wording: this one is the admin's cue to raise
		// a setting, not the user's cue to rephrase.
		$this->assertStringContainsString('without reaching an answer', $result['output']);
		$this->assertStringNotContainsString('ran out of time', $result['output']);
	}

	public function testAnnotatesAPartialAnswerRatherThanPresentingItAsComplete(): void {
		$this->maxIterations = 1;
		$this->timeout = 600;
		$this->withModelTurns([[
			'output' => 'Here is what I found so far',
			'tool_calls' => json_encode([['name' => 'nc_semantic_search', 'args' => ['query' => 'x']]]),
		]]);
		$this->withToolResult('result');

		$result = $this->loop()->run('alice', 'q', []);

		$this->assertStringContainsString('Here is what I found so far', $result['output']);
		$this->assertStringContainsString('stopped before finishing', $result['output']);
	}

	/**
	 * A tool that blows up is an observation the model can recover from, not a
	 * reason to fail the conversation.
	 */
	public function testAToolFailureIsReportedToTheModelRatherThanThrown(): void {
		$tasks = $this->withModelTurns([
			['output' => '', 'tool_calls' => json_encode([['name' => 'nc_semantic_search', 'args' => ['query' => 'x']]])],
			['output' => 'I could not search, sorry.', 'tool_calls' => ''],
		]);
		$this->client->method('callTool')->willThrowException(new \RuntimeException('qdrant is down'));

		$result = $this->loop()->run('alice', 'q', []);

		$this->assertSame('I could not search, sorry.', $result['output']);
		$this->assertStringContainsString('qdrant is down', (string)($tasks())[1]->getInput()['input']);
	}

	/**
	 * Results are folded into the prompt because the task type's tool_message
	 * slot cannot be used with this provider — see AgentLoop for the detail.
	 */
	public function testFoldsToolOutputIntoTheNextPrompt(): void {
		$tasks = $this->withModelTurns([
			['output' => '', 'tool_calls' => json_encode([['name' => 'nc_semantic_search', 'args' => ['query' => 'x']]])],
			['output' => 'done', 'tool_calls' => ''],
		]);
		$this->withToolResult('Cilium CNI with eBPF datapath');

		$this->loop()->run('alice', 'How is networking set up?', []);

		$secondPrompt = (string)($tasks())[1]->getInput()['input'];
		$this->assertStringContainsString('How is networking set up?', $secondPrompt);
		$this->assertStringContainsString('Cilium CNI with eBPF datapath', $secondPrompt);
		// The unusable slot stays empty rather than carrying prose.
		$this->assertSame('', ($tasks())[1]->getInput()['tool_message']);
	}

	public function testTruncatesOversizedToolOutput(): void {
		$tasks = $this->withModelTurns([
			['output' => '', 'tool_calls' => json_encode([['name' => 'nc_semantic_search', 'args' => ['query' => 'x']]])],
			['output' => 'done', 'tool_calls' => ''],
		]);
		$this->withToolResult(str_repeat('a', 40000));

		$this->loop()->run('alice', 'q', []);

		$secondPrompt = (string)($tasks())[1]->getInput()['input'];
		$this->assertLessThan(20000, strlen($secondPrompt), 'a single tool result must not fill the context window');
		$this->assertStringContainsString('truncated', $secondPrompt);
	}

	public function testCarriesPriorTurnsIntoTheModelCallAndReturnsThemExtended(): void {
		$tasks = $tasks = $this->withModelTurns([['output' => 'Cilium.', 'tool_calls' => '']]);
		$history = [
			['role' => 'human', 'content' => 'What is in my cluster note?'],
			['role' => 'assistant', 'content' => 'It describes the homelab cluster.'],
		];

		$result = $this->loop()->run('alice', 'Which CNI?', $history);

		/** @var list<string> $sent */
		$sent = ($tasks())[0]->getInput()['history'];
		$this->assertCount(2, $sent);
		$this->assertStringContainsString('What is in my cluster note?', $sent[0]);

		// The turn is appended so the next call sees it.
		$this->assertCount(4, $result['history']);
		$this->assertSame(['role' => 'human', 'content' => 'Which CNI?'], $result['history'][2]);
		$this->assertSame(['role' => 'assistant', 'content' => 'Cilium.'], $result['history'][3]);
	}

	/**
	 * Assistant only sends memories when the provider declares the input, and it
	 * is context about the user — appended to our tool instructions, not
	 * replacing them.
	 */
	public function testAppendsAssistantMemoriesToTheSystemPrompt(): void {
		$tasks = $this->withModelTurns([['output' => 'ok', 'tool_calls' => '']]);

		$this->loop()->run('alice', 'q', [], 'The user prefers concise answers.');

		$systemPrompt = (string)($tasks())[0]->getInput()['system_prompt'];
		$this->assertStringContainsString('The user prefers concise answers.', $systemPrompt);
		$this->assertStringContainsString('read-only', $systemPrompt, 'the tool constraints must survive');
	}

	public function testDeduplicatesCitationsAcrossRounds(): void {
		$this->maxIterations = 3;
		$this->timeout = 600;
		$this->withModelTurns([
			['output' => '', 'tool_calls' => json_encode([['name' => 'nc_semantic_search', 'args' => ['query' => 'a']]])],
			['output' => '', 'tool_calls' => json_encode([['name' => 'nc_semantic_search', 'args' => ['query' => 'b']]])],
			['output' => 'done', 'tool_calls' => ''],
		]);
		// The same document surfaces in both rounds; it must be cited once.
		$this->withToolResult(json_encode(['results' => [[
			'id' => 78, 'doc_type' => 'note', 'title' => 'Kubernetes Cluster Architecture',
		]]]));

		$this->assertSame(['Kubernetes Cluster Architecture'], $this->loop()->run('alice', 'q', [])['sources']);
	}

	public function testAnUnreachableMcpServerFailsTheTurnWithItsOwnMessage(): void {
		$clientFactory = $this->createMock(McpClientFactory::class);
		$clientFactory->method('connect')
			->willThrowException(new McpUnavailableException('The MCP server rejected Astrolabe\'s token.'));

		$loop = new AgentLoop(
			$this->taskProcessing,
			$clientFactory,
			$this->appConfig,
			$this->urlGenerator(),
			$this->createMock(LoggerInterface::class),
		);

		$this->expectException(AgentException::class);
		$this->expectExceptionMessage('rejected Astrolabe');
		$loop->run('alice', 'q', []);
	}

	/**
	 * An empty catalogue means the user is unprovisioned or the scopes grant
	 * nothing — worth saying, because the model would otherwise just answer from
	 * general knowledge and look confidently wrong.
	 */
	public function testNoVisibleToolsFailsWithAnActionableMessage(): void {
		$client = $this->createMock(Client::class);
		$client->method('listTools')->willReturn(new ListToolsResult([]));
		$clientFactory = $this->createMock(McpClientFactory::class);
		$clientFactory->method('connect')->willReturn($client);

		$loop = new AgentLoop(
			$this->taskProcessing,
			$clientFactory,
			$this->appConfig,
			$this->urlGenerator(),
			$this->createMock(LoggerInterface::class),
		);

		$this->expectException(AgentException::class);
		$this->expectExceptionMessage('provisioned');
		$loop->run('alice', 'q', []);
	}

	public function testAFailedModelTaskFailsTheTurn(): void {
		$this->taskProcessing->method('runTask')->willReturnCallback(
			static function (Task $task): Task {
				$task->setStatus(Task::STATUS_FAILED);
				$task->setErrorMessage('provider quota exceeded');
				return $task;
			},
		);

		$this->expectException(AgentException::class);
		$this->expectExceptionMessage('provider quota exceeded');
		$this->loop()->run('alice', 'q', []);
	}

	public function testDisconnectsEvenWhenTheTurnFails(): void {
		$this->taskProcessing->method('runTask')
			->willThrowException(new \RuntimeException('model unreachable'));
		// The server holds a session per connection; a failed turn must not leak one.
		$this->client->expects($this->once())->method('disconnect');

		$this->expectException(AgentException::class);
		$this->loop()->run('alice', 'q', []);
	}

	public function testDrivesTheToolCallingTaskType(): void {
		$tasks = $this->withModelTurns([['output' => 'ok', 'tool_calls' => '']]);

		$this->loop()->run('alice', 'q', []);

		$this->assertSame(TextToTextChatWithTools::ID, ($tasks())[0]->getTaskTypeId());
		$this->assertSame('alice', ($tasks())[0]->getUserId());
		// The catalogue reaches the model as function definitions.
		$this->assertStringContainsString('nc_semantic_search', (string)($tasks())[0]->getInput()['tools']);
	}
}
