<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\TaskProcessing;

use OCA\Astrolabe\Db\AgentConversation;
use OCA\Astrolabe\Db\AgentConversationMapper;
use OCA\Astrolabe\Service\Assistant\AgentException;
use OCA\Astrolabe\Service\Assistant\AgentLoop;
use OCA\Astrolabe\TaskProcessing\ContextAgentProvider;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\TaskTypes\ContextAgentInteraction;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The seam between Nextcloud's Assistant and the agent loop.
 *
 * `core:contextagent:interaction` is stateless on the wire — each turn carries
 * an opaque token and nothing else — so what this class gets right or wrong is
 * whose conversation it resumes, and what it hands back when it cannot.
 */
final class ContextAgentProviderTest extends TestCase {
	private AgentLoop&MockObject $agentLoop;
	private AgentConversationMapper&MockObject $conversations;

	protected function setUp(): void {
		parent::setUp();
		$this->agentLoop = $this->createMock(AgentLoop::class);
		$this->conversations = $this->createMock(AgentConversationMapper::class);
	}

	private function provider(): ContextAgentProvider {
		return new ContextAgentProvider(
			$this->agentLoop,
			$this->conversations,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function conversation(string $token): AgentConversation {
		$conversation = new AgentConversation();
		$conversation->setId(1);
		$conversation->setToken($token);
		$conversation->setUserId('alice');
		return $conversation;
	}

	/**
	 * @param list<array{role: string, content: string}> $turns
	 */
	private function loopReturns(string $output, array $sources = [], array $turns = []): void {
		$this->agentLoop->method('run')->willReturn([
			'output' => $output,
			'sources' => $sources,
			'turns' => $turns,
		]);
	}

	public function testServesTheCoreContextAgentTaskType(): void {
		$this->assertSame(ContextAgentInteraction::ID, $this->provider()->getTaskTypeId());
	}

	public function testAnswersAndHandsBackTheTokenThatFindsTheHistory(): void {
		$this->conversations->method('findForUser')->willReturn($this->conversation('tok-123'));
		$this->loopReturns('Cilium.', ['Kubernetes Cluster Architecture']);
		$this->conversations->method('appendTurns')->willReturn(true);

		$output = $this->provider()->process('alice', [
			'input' => 'Which CNI?',
			'conversation_token' => 'tok-123',
		], static function (float $progress): void {
		});

		$this->assertSame('Cilium.', $output['output']);
		$this->assertSame('tok-123', $output['conversation_token']);
		$this->assertSame(['Kubernetes Cluster Architecture'], $output['sources']);
		// Read-only by construction: there is never anything to confirm.
		$this->assertSame('', $output['actions']);
	}

	/**
	 * An unknown or foreign token starts a fresh conversation rather than
	 * erroring — the user loses context, which beats a dead chat.
	 */
	public function testAnUnknownTokenStartsAFreshConversation(): void {
		$this->conversations->method('findForUser')->willReturn(null);
		$this->conversations->expects($this->once())->method('start')->with('alice')
			->willReturn($this->conversation('tok-new'));
		$this->loopReturns('Hello.');
		$this->conversations->method('appendTurns')->willReturn(true);

		$output = $this->provider()->process('alice', ['input' => 'hi'], static function (float $p): void {
		});

		$this->assertSame('tok-new', $output['conversation_token']);
	}

	/**
	 * Every tool call is scoped to a user's token, so there is no sensible
	 * "nobody" to run as — better to refuse than to run unscoped.
	 */
	public function testRefusesToRunWithoutAUser(): void {
		$this->expectException(ProcessingException::class);
		$this->provider()->process(null, ['input' => 'hi'], static function (float $p): void {
		});
	}

	public function testRejectsAnEmptyMessage(): void {
		$this->expectException(ProcessingException::class);
		$this->provider()->process('alice', ['input' => '   '], static function (float $p): void {
		});
	}

	/**
	 * The turn is what failed, not the conversation: the loop's message is
	 * already written for whoever is reading the chat.
	 */
	public function testAFailedTurnBecomesAProcessingException(): void {
		$this->conversations->method('findForUser')->willReturn($this->conversation('tok-123'));
		$this->agentLoop->method('run')->willThrowException(
			new AgentException('An administrator needs to check the MCP server connection.'),
		);

		$this->expectException(ProcessingException::class);
		$this->expectExceptionMessage('administrator');
		$this->provider()->process('alice', ['input' => 'hi', 'conversation_token' => 'tok-123'], static function (float $p): void {
		});
	}

	/**
	 * A history that could not be stored costs context on the *next* message.
	 * The answer for *this* one has already been produced and must still reach
	 * the user.
	 */
	public function testStillAnswersWhenTheHistoryCannotBeStored(): void {
		$this->conversations->method('findForUser')->willReturn($this->conversation('tok-123'));
		$this->loopReturns('Cilium.');
		$this->conversations->method('appendTurns')->willReturn(false);

		$output = $this->provider()->process('alice', [
			'input' => 'Which CNI?',
			'conversation_token' => 'tok-123',
		], static function (float $p): void {
		});

		$this->assertSame('Cilium.', $output['output']);
	}

	/**
	 * Assistant's recollection of earlier sessions is context about the user, so
	 * it is passed through — but it arrives as an arbitrary shape and must not be
	 * able to break the turn.
	 *
	 * @dataProvider memoryShapes
	 */
	public function testPassesAssistantMemoriesThroughDefensively(mixed $memories, string $expected): void {
		$this->conversations->method('findForUser')->willReturn($this->conversation('tok-123'));
		$this->conversations->method('appendTurns')->willReturn(true);

		$seen = null;
		$this->agentLoop->method('run')->willReturnCallback(
			function (string $userId, string $prompt, array $history, string $memories) use (&$seen): array {
				$seen = $memories;
				return ['output' => 'ok', 'sources' => [], 'turns' => []];
			},
		);

		$this->provider()->process('alice', [
			'input' => 'hi',
			'conversation_token' => 'tok-123',
			'memories' => $memories,
		], static function (float $p): void {
		});

		$this->assertSame($expected, $seen);
	}

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public static function memoryShapes(): array {
		return [
			'a list' => [['prefers metric units', 'works in CET'], "prefers metric units\n\nworks in CET"],
			'not sent at all' => [null, ''],
			'not a list' => ['just a string', ''],
			'empty entries dropped' => [['', '   ', 'kept'], 'kept'],
			'non-strings dropped' => [[42, ['nested'], 'kept'], 'kept'],
		];
	}
}
