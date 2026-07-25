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
 */
final class AgentConversationMapper extends QBMapper {
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
