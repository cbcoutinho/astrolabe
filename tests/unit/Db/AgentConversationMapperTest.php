<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Db;

use OCA\Astrolabe\Db\AgentConversation;
use OCA\Astrolabe\Db\AgentConversationMapper;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The retry policy behind {@see AgentConversationMapper::appendTurns()}.
 *
 * A turn takes as long as the model does, so two turns on the same token
 * overlap easily — this was not theoretical: with several TaskProcessing
 * workers running, a follow-up was observed being claimed and answered twice.
 * Whichever wrote second used to replace a history it had never read, silently
 * dropping the other turn.
 *
 * The SQL itself needs a database and this repo has no DB-backed test tier, so
 * the two statements are substituted here and what is pinned is the policy:
 * how a lost race is detected, what happens next, and when it gives up.
 */
final class AgentConversationMapperTest extends TestCase {
	private function conversation(int $id, int $revision, array $history = []): AgentConversation {
		$conversation = new AgentConversation();
		$conversation->setId($id);
		$conversation->setRevision($revision);
		$conversation->setDecodedHistory($history);
		return $conversation;
	}

	/**
	 * @param list<bool> $writeOutcomes true = the write won, false = it lost
	 */
	private function mapper(array $writeOutcomes, ?AgentConversation $reloaded = null): AgentConversationMapper {
		return new class($this->createMock(IDBConnection::class), $this->createMock(ISecureRandom::class), $writeOutcomes, $reloaded, ) extends AgentConversationMapper {
			/** @var list<string> the history each attempted write carried */
			public array $attempted = [];

			public function __construct(
				IDBConnection $db,
				ISecureRandom $random,
				private array $writeOutcomes,
				private ?AgentConversation $reloaded,
			) {
				parent::__construct($db, $random);
			}

			#[\Override]
			protected function writeIfUnchanged(AgentConversation $conversation): bool {
				$this->attempted[] = $conversation->getHistory();
				$won = array_shift($this->writeOutcomes) ?? true;
				if ($won) {
					$conversation->setRevision($conversation->getRevision() + 1);
				}
				return $won;
			}

			#[\Override]
			protected function findById(?int $id): ?AgentConversation {
				return $this->reloaded;
			}
		};
	}

	public function testWritesOnceWhenNothingElseTouchedTheRow(): void {
		$mapper = $this->mapper([true]);
		$conversation = $this->conversation(1, 0, [['role' => 'human', 'content' => 'earlier']]);

		$stored = $mapper->appendTurns($conversation, [['role' => 'assistant', 'content' => 'answer']], 20);

		$this->assertTrue($stored);
		$this->assertCount(1, $mapper->attempted);
	}

	/**
	 * The headline case: losing the race must not cost the other turn. The retry
	 * re-reads the winner's history and appends onto it, so both survive.
	 */
	public function testALostRaceReappliesOnTopOfTheWinner(): void {
		$winner = $this->conversation(1, 1, [
			['role' => 'human', 'content' => 'their question'],
			['role' => 'assistant', 'content' => 'their answer'],
		]);
		$mapper = $this->mapper([false, true], $winner);
		$conversation = $this->conversation(1, 0, []);

		$stored = $mapper->appendTurns($conversation, [
			['role' => 'human', 'content' => 'my question'],
			['role' => 'assistant', 'content' => 'my answer'],
		], 20);

		$this->assertTrue($stored);
		$this->assertCount(2, $mapper->attempted, 'the losing write must be retried');

		/** @var list<array{role: string, content: string}> $final */
		$final = json_decode($mapper->attempted[1], true);
		$contents = array_column($final, 'content');
		$this->assertContains('their answer', $contents, 'the concurrent turn must survive');
		$this->assertContains('my answer', $contents);
	}

	/**
	 * The retry appends *this* turn once, not once per attempt — a re-applied
	 * write that duplicated its own turn would corrupt the history it is trying
	 * to protect.
	 */
	public function testTheRetriedTurnIsNotDuplicated(): void {
		$winner = $this->conversation(1, 1, [['role' => 'assistant', 'content' => 'theirs']]);
		$mapper = $this->mapper([false, true], $winner);

		$mapper->appendTurns($this->conversation(1, 0, []), [['role' => 'human', 'content' => 'mine']], 20);

		/** @var list<array{role: string, content: string}> $final */
		$final = json_decode($mapper->attempted[1], true);
		$this->assertSame(['theirs', 'mine'], array_column($final, 'content'));
	}

	/**
	 * The answer has already been produced and is returned regardless, so losing
	 * repeatedly costs the user context on their *next* message — not this one.
	 * Reporting it lets the caller log rather than fail the turn.
	 */
	public function testGivesUpRatherThanRetryingForever(): void {
		$mapper = $this->mapper([false, false, false], $this->conversation(1, 9));

		$stored = $mapper->appendTurns($this->conversation(1, 0, []), [['role' => 'human', 'content' => 'mine']], 20);

		$this->assertFalse($stored);
		$this->assertLessThanOrEqual(3, count($mapper->attempted));
	}

	/**
	 * A conversation swept away by the expiry job mid-turn has nothing left to
	 * append to; retrying would loop against a row that will never return.
	 */
	public function testStopsWhenTheConversationDisappearedMidTurn(): void {
		$mapper = $this->mapper([false], null);

		$stored = $mapper->appendTurns($this->conversation(1, 0, []), [['role' => 'human', 'content' => 'mine']], 20);

		$this->assertFalse($stored);
		$this->assertCount(1, $mapper->attempted);
	}

	public function testTrimsWhatItStoresToTheGivenBound(): void {
		$mapper = $this->mapper([true]);
		$history = [];
		for ($i = 0; $i < 30; $i++) {
			$history[] = ['role' => 'human', 'content' => 'turn ' . $i];
		}

		$mapper->appendTurns($this->conversation(1, 0, $history), [['role' => 'assistant', 'content' => 'newest']], 20);

		/** @var list<array{role: string, content: string}> $final */
		$final = json_decode($mapper->attempted[0], true);
		$this->assertCount(20, $final);
		$this->assertSame('newest', $final[19]['content']);
	}
}
