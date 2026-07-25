<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * @template-extends QBMapper<AgentConversation>
 *
 * @psalm-suppress ClassMustBeFinal — kept non-final so it can be mocked in the
 *   unit tests, mirroring the other Service classes.
 */
class AgentConversationMapper extends QBMapper {
	private const TOKEN_LENGTH = 43;

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		IDBConnection $db,
		private ISecureRandom $random,
	) {
		parent::__construct($db, 'astrolabe_agent_conv', AgentConversation::class);
	}

	/**
	 * Find a conversation by token, scoped to its owner.
	 *
	 * The user filter is the authorisation check, not an optimisation: the token
	 * arrives from the client, so matching on it alone would let a guessed or
	 * replayed token read another user's history.
	 */
	public function findForUser(string $token, string $userId): ?AgentConversation {
		if ($token === '') {
			return null;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			// An unknown or foreign token starts a fresh conversation rather than
			// erroring: the user just loses context, which beats a dead chat.
			return null;
		}
	}

	public function start(string $userId): AgentConversation {
		$conversation = new AgentConversation();
		$conversation->setToken($this->random->generate(
			self::TOKEN_LENGTH,
			ISecureRandom::CHAR_ALPHANUMERIC,
		));
		$conversation->setUserId($userId);
		$conversation->setDecodedHistory([]);
		$conversation->setUpdatedAt(time());

		return $this->insert($conversation);
	}

	/**
	 * Append a turn to a conversation without losing one written concurrently.
	 *
	 * A turn takes as long as the model does, so two turns on the same token
	 * overlap easily — a double submit, a retry, a second tab. Both would read
	 * the same history and the later write would replace a history it never saw,
	 * silently dropping the other turn. The whole point of this table is not
	 * losing context, so the update is guarded on the revision it read: if
	 * someone else wrote first, this re-reads their history and appends on top
	 * of it, keeping both turns.
	 *
	 * Gives up after a few attempts rather than looping: the answer has already
	 * been produced and is being returned regardless, so a lost history costs
	 * the user context on their next message, not this one.
	 *
	 * @param list<array{role: string, content: string}> $turns
	 */
	public function appendTurns(AgentConversation $conversation, array $turns, int $keep): bool {
		for ($attempt = 0; $attempt < 3; $attempt++) {
			$conversation->appendTurns($turns, $keep);
			$conversation->setUpdatedAt(time());

			if ($this->writeIfUnchanged($conversation)) {
				return true;
			}

			$fresh = $this->findById($conversation->getId());
			if ($fresh === null) {
				// Expired or deleted mid-turn; there is nothing left to append to.
				return false;
			}
			$conversation = $fresh;
		}

		return false;
	}

	/**
	 * Protected as a seam: the retry policy above is the part worth testing, and
	 * it can only be exercised without a database if the two statements it drives
	 * can be substituted. There is no DB-backed test tier in this repo.
	 */
	protected function findById(?int $id): ?AgentConversation {
		if ($id === null) {
			return null;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Write the row only if nobody has written it since it was read.
	 *
	 * @return bool false when the revision moved on, i.e. this write lost a race
	 */
	protected function writeIfUnchanged(AgentConversation $conversation): bool {
		$revision = $conversation->getRevision();

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('history', $qb->createNamedParameter($conversation->getHistory(), IQueryBuilder::PARAM_STR))
			->set('updated_at', $qb->createNamedParameter($conversation->getUpdatedAt(), IQueryBuilder::PARAM_INT))
			->set('revision', $qb->createNamedParameter($revision + 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($conversation->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($revision, IQueryBuilder::PARAM_INT)));

		if ($qb->executeStatement() === 0) {
			return false;
		}

		$conversation->setRevision($revision + 1);
		return true;
	}

	/**
	 * Delete conversations untouched for longer than $maxAge seconds.
	 *
	 * @return int rows removed
	 */
	public function deleteStale(int $maxAge): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt(
				'updated_at',
				$qb->createNamedParameter(time() - $maxAge, IQueryBuilder::PARAM_INT),
			));

		return $qb->executeStatement();
	}
}
