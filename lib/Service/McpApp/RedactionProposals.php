<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\McpApp;

use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;

/**
 * Model-proposed redaction strings, held server-side so a viewer link carries
 * only an opaque id (POC, Deck #1578).
 *
 * The strings are exactly the sensitive text being redacted, so they must not
 * ride the link: it lands in chat history, the reference cache, access logs and
 * the browser. A long link also wraps over several lines, which pushed the
 * widget slot off-screen and NcReferenceWidget never lazily mounted it.
 *
 * Keyed per user, so an id is useless to anyone else. An expired or evicted
 * proposal degrades to opening the document without staged regions.
 */
final class RedactionProposals {
	/** ponytail: cache-backed; move to a table if chats must outlive eviction. */
	private const TTL = 7 * 24 * 3600;
	public const ID_PATTERN = '/^[A-Za-z0-9]{24}\z/';

	private ICache $cache;

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		ICacheFactory $cacheFactory,
		private ISecureRandom $random,
	) {
		$this->cache = $cacheFactory->createDistributed('astrolabe-redaction-proposals');
	}

	/**
	 * open_document redact_text items: a plain string, or {text, group?} where
	 * group names the person the text belongs to. Anything else is dropped.
	 *
	 * @return list<string|array{text: string, group?: string}>
	 */
	public static function normalize(mixed $items): array {
		$out = [];
		/** @var mixed $item */
		foreach ((array)$items as $item) {
			if (is_string($item)) {
				$out[] = $item;
			} elseif (is_array($item) && isset($item['text']) && is_string($item['text'])) {
				$entry = ['text' => $item['text']];
				if (isset($item['group']) && is_string($item['group']) && $item['group'] !== '') {
					$entry['group'] = $item['group'];
				}
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * @param list<string|array{text: string, group?: string}> $items
	 * @param ?array<array-key, mixed> $review open_document `review`, passed
	 *                                         through as-is; the MCP server validates it
	 */
	public function store(string $userId, int $fileId, array $items, ?array $review = null): string {
		$id = $this->random->generate(24, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->cache->set($this->key($userId, $id), ['file_id' => $fileId, 'texts' => $items, 'review' => $review], self::TTL);
		return $id;
	}

	/** @return ?array<array-key, mixed> the proposal's review controls, if any */
	public function review(string $userId, string $id, int $fileId): ?array {
		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return null;
		}
		/** @var mixed $row */
		$row = $this->cache->get($this->key($userId, $id));
		return is_array($row) && ($row['file_id'] ?? null) === $fileId && isset($row['review']) && is_array($row['review'])
			? $row['review']
			: null;
	}

	/** @return list<string|array{text: string, group?: string}> empty when unknown, expired, another user's, or another file's */
	public function texts(string $userId, string $id, int $fileId): array {
		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return [];
		}
		$row = $this->cache->get($this->key($userId, $id));
		if (!is_array($row) || ($row['file_id'] ?? null) !== $fileId) {
			return [];
		}
		return self::normalize($row['texts'] ?? []);
	}

	/** ponytail: one viewer state per user; key by conversation if users work several files at once. */
	private const CONTEXT_MAX_CHARS = 4000;

	/**
	 * The viewer's latest ui/update-model-context text (its current staged
	 * state, including the user's manual edits), so the next chat turn can see
	 * it ({@see \OCA\Astrolabe\Service\Assistant\AgentLoop}).
	 */
	public function saveViewerContext(string $userId, int $fileId, string $text): void {
		$this->cache->set(hash('sha256', $userId) . '/viewer-context', [
			'file_id' => $fileId,
			'text' => mb_substr($text, 0, self::CONTEXT_MAX_CHARS),
		], self::TTL);
	}

	/** @return array{file_id: int, text: string}|null */
	public function viewerContext(string $userId): ?array {
		/** @var mixed $row */
		$row = $this->cache->get(hash('sha256', $userId) . '/viewer-context');
		return is_array($row) && isset($row['file_id'], $row['text']) && is_int($row['file_id']) && is_string($row['text']) && $row['text'] !== ''
			? ['file_id' => $row['file_id'], 'text' => $row['text']]
			: null;
	}

	private function key(string $userId, string $id): string {
		return hash('sha256', $userId) . '/' . $id;
	}
}
