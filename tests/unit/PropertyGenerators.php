<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit;

use Eris\Generator;
use Eris\Generators;

/**
 * Generators shared by the Eris property tests.
 *
 * Eris's own `string()` only draws printable ASCII 33–126: no whitespace, no
 * newline, no multibyte UTF-8. Those are the characters that break escaping and
 * parsing, so properties build strings from an explicit alphabet instead.
 */
final class PropertyGenerators {
	/** Characters that are special somewhere in markdown, paths, JSON or regex. */
	public const AWKWARD = [
		'a', 'Z', '0', ' ', "\t", "\n", "\r", '\\', '/', '.', '[', ']', '(', ')',
		'`', '*', '_', '"', "'", '{', '}', ':', ',', '-', 'é', '漢', '🙂',
	];

	/**
	 * Strings over the given alphabet; each element may be several characters.
	 *
	 * @param list<string> $alphabet
	 */
	public static function text(array $alphabet = self::AWKWARD): Generator {
		return Generators::map(
			static fn (array $chars): string => implode('', $chars),
			Generators::seq(Generators::elements($alphabet)),
		);
	}
}
