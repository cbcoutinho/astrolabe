<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service;

/**
 * Minimal, self-contained evaluator for webhook preset event filters.
 *
 * The preset filters (see {@see WebhookPresets}) use the same shape Nextcloud's
 * `webhook_listeners` app feeds to its bundled `PHPMongoQuery`: a map of dotted
 * field path → expected value, where a value of the form ``/pattern/flags`` is a
 * regular expression and any other value is an exact match. Every entry must
 * match (implicit ``$and``).
 *
 * This reimplements only that subset — the `webhook_listeners` `PHPMongoQuery`
 * class lives in another app's namespace and is not on Astrolabe's classpath —
 * so native listener delivery filters events identically to the previous
 * MCP-registered webhook path. The regex-vs-literal rule below mirrors
 * `PHPMongoQuery::_isEqual()` verbatim (`/^\/(.*?)\/([a-z]*)$/i`).
 */
final class WebhookEventFilter {
	/**
	 * @param array<array-key, mixed> $filter Field-path ⇒ expected value (regex or literal)
	 * @param array<array-key, mixed> $data The event envelope to match against
	 * @psalm-suppress MixedAssignment iterating a filter whose values are mixed by design
	 */
	public static function matches(array $filter, array $data): bool {
		foreach ($filter as $path => $expected) {
			if (!self::isEqual(self::valueAtPath($data, (string)$path), $expected)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolve a dotted path (e.g. ``event.node.path``) against a nested array.
	 * Returns null when any segment is missing — a filter referencing an absent
	 * field never matches, so we err on the side of NOT delivering.
	 *
	 * @param array<array-key, mixed> $data
	 * @psalm-suppress MixedAssignment walking into an untyped nested structure
	 */
	private static function valueAtPath(array $data, string $path): mixed {
		/** @var mixed $cursor */
		$cursor = $data;
		foreach (explode('.', $path) as $segment) {
			if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
				return null;
			}
			$cursor = $cursor[$segment];
		}
		return $cursor;
	}

	/**
	 * Mirror `PHPMongoQuery::_isEqual()` for the scalar case: a ``/pattern/flags``
	 * expected value is a regex tested against the actual value; otherwise it is a
	 * strict comparison.
	 */
	private static function isEqual(mixed $actual, mixed $expected): bool {
		if (is_string($expected) && preg_match('/^\/(.*?)\/([a-z]*)$/i', $expected, $matches) === 1) {
			if (!is_string($actual) && !is_int($actual) && !is_float($actual)) {
				return false;
			}
			return preg_match('/' . $matches[1] . '/' . $matches[2], (string)$actual) === 1;
		}
		return $actual === $expected;
	}
}
