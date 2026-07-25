<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One agent conversation, addressed by an opaque token.
 *
 * @method string getToken()
 * @method void setToken(string $token)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getHistory()
 * @method void setHistory(string $history)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor — Entity populates via setters.
 */
final class AgentConversation extends Entity {
	/** @psalm-suppress PossiblyUnusedProperty — read and written by QBMapper via the magic accessors. */
	protected string $token = '';
	/** @psalm-suppress PossiblyUnusedProperty — read and written by QBMapper via the magic accessors. */
	protected string $userId = '';
	/** @psalm-suppress PossiblyUnusedProperty — read and written by QBMapper via the magic accessors. */
	protected string $history = '[]';
	/** @psalm-suppress PossiblyUnusedProperty — read and written by QBMapper via the magic accessors. */
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('token', 'string');
		$this->addType('userId', 'string');
		$this->addType('history', 'string');
		$this->addType('updatedAt', 'integer');
	}

	/**
	 * The stored turns, or an empty list if the column is unreadable.
	 *
	 * Decoding defensively rather than throwing: a corrupt row should cost the
	 * user their context, not their ability to send the next message.
	 *
	 * @return list<array{role: string, content: string}>
	 */
	public function getDecodedHistory(): array {
		try {
			/** @var mixed $decoded */
			$decoded = json_decode($this->history, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		if (!is_array($decoded)) {
			return [];
		}

		$turns = [];
		/** @var mixed $turn */
		foreach ($decoded as $turn) {
			if (!is_array($turn)) {
				continue;
			}
			/** @var mixed $role */
			$role = $turn['role'] ?? null;
			/** @var mixed $content */
			$content = $turn['content'] ?? null;
			if (is_string($role) && is_string($content)) {
				$turns[] = ['role' => $role, 'content' => $content];
			}
		}
		return $turns;
	}

	/**
	 * @param list<array{role: string, content: string}> $turns
	 */
	public function setDecodedHistory(array $turns): void {
		$this->setHistory(json_encode($turns, JSON_THROW_ON_ERROR));
	}
}
