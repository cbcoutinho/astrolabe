<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Assistant;

use Eris\Generator;
use Eris\Generators;
use Eris\TestTrait;
use OCA\Astrolabe\Service\Assistant\ToolCallParser;
use OCA\Astrolabe\Tests\Unit\PropertyGenerators;
use PHPUnit\Framework\TestCase;

/**
 * A model's `tool_calls` is free-form text until proven otherwise. These
 * properties pin the two halves of the parser's contract: every shape a
 * provider emits reads back as the calls it encodes, and nothing a model can
 * send makes it fail or yield a malformed call.
 */
final class ToolCallParserTest extends TestCase {
	use TestTrait;

	/** Keys a provider's entry might carry, so junk lands on the paths that matter. */
	private const ENTRY_KEYS = ['name', 'id', 'args', 'arguments', 'function', 'tool_calls'];

	/**
	 * Eris sizes every nested `seq` independently (default 200), so three levels
	 * reach ~200³ values and exhaust memory. Shapes, not volume, are the point.
	 */
	private const NESTED_MAX_SIZE = 12;

	/** @param list<array{0: array-key, 1: mixed}> $pairs */
	private static function assoc(array $pairs): array {
		$out = [];
		foreach ($pairs as [$key, $value]) {
			$out[$key] = $value;
		}
		return $out;
	}

	private static function scalar(): Generator {
		return Generators::oneOf(
			PropertyGenerators::text(),
			Generators::int(),
			Generators::bool(),
			Generators::constant(null),
			Generators::constant(1.5),
		);
	}

	/** Objects whose keys are the ones the parser reads, with values of any type. */
	private static function junkObject(Generator $values): Generator {
		return Generators::map(
			self::assoc(...),
			Generators::seq(Generators::tuple(Generators::elements(self::ENTRY_KEYS), $values)),
		);
	}

	/**
	 * Any JSON document a model could plausibly emit, two levels deep.
	 *
	 * Entries usually carry a usable `name`, so they survive parsing and their
	 * other fields, all junk, reach the output; uniformly random keys almost
	 * never did, which left the well-formedness check with nothing to check.
	 */
	private static function junkDocument(): Generator {
		$value = Generators::oneOf(self::scalar(), Generators::seq(self::scalar()), self::junkObject(self::scalar()));
		$name = Generators::frequency(
			[3, Generators::suchThat(static fn (string $s): bool => $s !== '', PropertyGenerators::text())],
			[1, self::scalar()],
		);
		$entry = Generators::map(
			static fn (array $t): array => ['name' => $t[0]] + $t[1],
			Generators::tuple($name, self::junkObject($value)),
		);
		return Generators::oneOf(
			self::scalar(),
			$entry,
			Generators::seq(Generators::oneOf($entry, self::scalar())),
			Generators::map(static fn (array $l): array => ['tool_calls' => $l], Generators::seq($entry)),
		);
	}

	/** A well-formed call, plus which of the provider encodings carries it. */
	private static function encodedCall(): Generator {
		return Generators::tuple(
			PropertyGenerators::text(),
			Generators::suchThat(static fn (string $s): bool => $s !== '', PropertyGenerators::text()),
			Generators::map(self::assoc(...), Generators::seq(Generators::tuple(
				PropertyGenerators::text(['q', 'x', '_']),
				Generators::oneOf(PropertyGenerators::text(), Generators::int(), Generators::bool()),
			))),
			Generators::elements('args', 'arguments', 'function', 'arguments-as-json'),
		);
	}

	private function assertWellFormed(array $calls): void {
		$this->assertTrue(array_is_list($calls));
		foreach ($calls as $call) {
			$this->assertSame(['id', 'name', 'arguments'], array_keys($call));
			$this->assertIsString($call['id']);
			$this->assertIsString($call['name']);
			$this->assertNotSame('', $call['name']);
			$this->assertIsArray($call['arguments']);
		}
	}

	/**
	 * For any list of calls, each encoded in any provider's shape, bare or
	 * wrapped in `{"tool_calls": …}`, parsing returns exactly those calls.
	 */
	public function testEveryProviderEncodingReadsBackAsTheCallsItCarries(): void {
		$this->forAll(Generators::seq(self::encodedCall()), Generators::bool())
			->withMaxSize(self::NESTED_MAX_SIZE)
			->then(function (array $calls, bool $wrapped): void {
				$entries = array_map(static fn (array $c): array => match ($c[3]) {
					'args' => ['id' => $c[0], 'name' => $c[1], 'args' => $c[2]],
					'arguments' => ['id' => $c[0], 'name' => $c[1], 'arguments' => $c[2]],
					'function' => ['id' => $c[0], 'function' => ['name' => $c[1], 'arguments' => $c[2]]],
					'arguments-as-json' => ['id' => $c[0], 'name' => $c[1], 'arguments' => json_encode($c[2], JSON_THROW_ON_ERROR)],
				}, $calls);
				$raw = json_encode($wrapped ? ['tool_calls' => $entries] : $entries, JSON_THROW_ON_ERROR);

				$this->assertSame(
					array_map(static fn (array $c): array => ['id' => $c[0], 'name' => $c[1], 'arguments' => $c[2]], $calls),
					ToolCallParser::parse($raw),
				);
			});
	}

	/** For any JSON a model could send, the parser returns only well-formed calls. */
	public function testAnyJsonYieldsOnlyWellFormedCalls(): void {
		$this->forAll(self::junkDocument())->withMaxSize(self::NESTED_MAX_SIZE)->then(function (mixed $document): void {
			$this->assertWellFormed(ToolCallParser::parse(json_encode($document, JSON_THROW_ON_ERROR)));
		});
	}

	/** For any text at all, the parser either reports it is not JSON or returns well-formed calls. */
	public function testAnyTextIsEitherRejectedAsNotJsonOrParsed(): void {
		$this->forAll(PropertyGenerators::text())->then(function (string $raw): void {
			try {
				$calls = ToolCallParser::parse($raw);
			} catch (\JsonException) {
				$this->addToAssertionCount(1);
				return;
			}
			$this->assertWellFormed($calls);
		});
	}

	/** A call missing its name names no tool, so it is dropped, not guessed. */
	public function testDropsEntriesThatNameNoTool(): void {
		$this->assertSame([], ToolCallParser::parse('[{"args":{"query":"x"}},{"name":"","args":{}},"nc_semantic_search"]'));
	}
}
