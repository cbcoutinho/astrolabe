<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Db;

use OCA\Astrolabe\Db\AgentConversation;
use PHPUnit\Framework\TestCase;

/**
 * The stored half of the history bound, and the append that makes a write safe
 * to retry against a row someone else changed in the meantime.
 */
final class AgentConversationTest extends TestCase {
	private function conversation(array $history = []): AgentConversation {
		$conversation = new AgentConversation();
		$conversation->setDecodedHistory($history);
		return $conversation;
	}

	public function testAppendsToWhatIsAlreadyStored(): void {
		$conversation = $this->conversation([
			['role' => 'human', 'content' => 'What is in my cluster note?'],
			['role' => 'assistant', 'content' => 'It describes the homelab cluster.'],
		]);

		$conversation->appendTurns([
			['role' => 'human', 'content' => 'Which CNI?'],
			['role' => 'assistant', 'content' => 'Cilium.'],
		], 20);

		$this->assertCount(4, $conversation->getDecodedHistory());
		$this->assertSame(
			['role' => 'assistant', 'content' => 'Cilium.'],
			$conversation->getDecodedHistory()[3],
		);
	}

	/**
	 * The retry path: the row was re-read after losing a race, so the turn is
	 * appended onto the *other* turn's history rather than replacing it. Both
	 * survive — which is the entire reason the write is guarded.
	 */
	public function testAppendingOntoAConcurrentTurnKeepsBoth(): void {
		$conversation = $this->conversation([
			['role' => 'human', 'content' => 'first question'],
			['role' => 'assistant', 'content' => 'first answer'],
		]);

		$conversation->appendTurns([
			['role' => 'human', 'content' => 'second question'],
			['role' => 'assistant', 'content' => 'second answer'],
		], 20);

		$contents = array_column($conversation->getDecodedHistory(), 'content');
		$this->assertContains('first answer', $contents);
		$this->assertContains('second answer', $contents);
	}

	/**
	 * Storage is bounded as well as what is sent, or the row grows forever even
	 * though only the tail is ever read.
	 */
	public function testKeepsOnlyTheMostRecentTurns(): void {
		$history = [];
		for ($i = 0; $i < 60; $i++) {
			$history[] = ['role' => $i % 2 === 0 ? 'human' : 'assistant', 'content' => 'turn ' . $i];
		}
		$conversation = $this->conversation($history);

		$conversation->appendTurns([['role' => 'assistant', 'content' => 'newest']], 20);

		$stored = $conversation->getDecodedHistory();
		$this->assertCount(20, $stored);
		$this->assertSame(['role' => 'assistant', 'content' => 'newest'], $stored[19]);
	}

	/**
	 * A corrupt row should cost the user their context, not their next message.
	 */
	public function testUnreadableHistoryIsTreatedAsEmpty(): void {
		$conversation = new AgentConversation();
		$conversation->setHistory('not json at all');

		$conversation->appendTurns([['role' => 'human', 'content' => 'hello']], 20);

		$this->assertSame([['role' => 'human', 'content' => 'hello']], $conversation->getDecodedHistory());
	}

	/**
	 * An unwritten row reads as no turns, not as a decode failure the caller has
	 * to reason about — the initial value is deliberately not valid JSON.
	 */
	public function testAFreshConversationHasNoTurns(): void {
		$this->assertSame([], (new AgentConversation())->getDecodedHistory());
	}

	/**
	 * `QBMapper::insert()` writes only the fields `Entity::setter()` marked as
	 * updated, and the setter skips a value that already equals the property. An
	 * initial value of '[]' therefore dropped `history` from the INSERT of an
	 * empty conversation, which MySQL and MariaDB reject outright: Doctrine
	 * strips the declared default from every TEXT column before creating the
	 * table, so it ends up NOT NULL with nothing to fall back on. Postgres and
	 * SQLite kept the default and hid it, which is why it only ever failed in
	 * production.
	 */
	public function testStoringAnEmptyHistoryStillWritesTheColumn(): void {
		$conversation = new AgentConversation();

		$conversation->setDecodedHistory([]);

		$this->assertArrayHasKey(
			'history',
			$conversation->getUpdatedFields(),
			'an empty history must still be written, or MySQL rejects the insert',
		);
		$this->assertSame('[]', $conversation->getHistory());
	}
}
