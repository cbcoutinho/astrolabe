<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service;

use Eris\Generators;
use Eris\TestTrait;
use OCA\Astrolabe\Service\WebhookEventFilter;
use OCA\Astrolabe\Service\WebhookPresets;
use OCA\Astrolabe\Tests\Unit\PropertyGenerators;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WebhookEventFilter.
 *
 * The filter must behave identically to the `webhook_listeners` app's
 * PHPMongoQuery for the subset the presets use: dotted field paths, a
 * ``/pattern/flags`` regex value, and literal equality otherwise.
 */
final class WebhookEventFilterTest extends TestCase {
	use TestTrait;

	/** The Notes preset filter — the real-world case this must get right. */
	private const NOTES_FILTER = ['event.node.path' => WebhookPresets::NOTES_PATH_PATTERN];

	/** Path segments: anything but the `.` that separates them. */
	private const SEGMENT_CHARS = ['a', 'b', 'Z', '0', '_', '-', ' ', '/', 'é'];

	/** Wrap $value in nested arrays along the dotted $path. */
	private static function nest(string $path, mixed $value): array {
		foreach (array_reverse(explode('.', $path)) as $segment) {
			$value = [$segment => $value];
		}
		return $value;
	}

	/** Scalars that are compared literally, i.e. not of the `/pattern/flags` form. */
	private static function literals(): \Eris\Generator {
		return Generators::oneOf(
			Generators::int(),
			Generators::bool(),
			Generators::suchThat(
				static fn (string $s): bool => preg_match('/^\/(.*?)\/([a-z]*)$/i', $s) !== 1,
				PropertyGenerators::text(['a', '/', 'i', 'x', '1', ' ']),
			),
		);
	}

	public function testEmptyFilterAlwaysMatches(): void {
		$this->assertTrue(WebhookEventFilter::matches([], ['event' => ['node' => ['path' => '/anything']]]));
	}

	/**
	 * For any dotted path and any two literals, the filter matches exactly when
	 * the value found at the path is identical to the expected one.
	 */
	public function testLiteralMatchesExactlyTheIdenticalValueAtAnyPath(): void {
		$this->forAll(
			Generators::seq(PropertyGenerators::text(self::SEGMENT_CHARS)),
			self::literals(),
			self::literals(),
		)->then(function (array $segments, mixed $expected, mixed $actual): void {
			$path = implode('.', ['event', ...$segments]);
			$this->assertSame(
				$expected === $actual,
				WebhookEventFilter::matches([$path => $expected], self::nest($path, $actual)),
			);
		});
	}

	/**
	 * A node path is `/<uid>/files/<path>`: the Notes filter matches exactly the
	 * paths whose top-level folder is `Notes`, whatever the uid and file name.
	 */
	public function testNotesFilterMatchesExactlyTheTopLevelNotesFolder(): void {
		$this->forAll(
			Generators::suchThat(
				static fn (string $uid): bool => $uid !== '',
				PropertyGenerators::text(['a', 'B', '0', '.', '-', '_', ' ', '@', 'é']),
			),
			Generators::elements('', 'Projects/', 'Projects/files/', 'files/'),
			Generators::elements('Notes', 'notes', 'Notes2', 'Documents'),
			PropertyGenerators::text(),
		)->then(function (string $uid, string $parent, string $folder, string $rest): void {
			$path = "/$uid/files/$parent$folder/$rest";
			$this->assertSame(
				$parent === '' && $folder === 'Notes',
				WebhookEventFilter::matches(self::NOTES_FILTER, ['event' => ['node' => ['path' => $path]]]),
				$path,
			);
		});
	}

	/** Found by the property above: the greedy `.*` uid matched nested folders. */
	public function testNotesFilterIgnoresAFilesNotesFolderNestedElsewhere(): void {
		$data = ['event' => ['node' => ['path' => '/alice/files/Projects/files/Notes/plan.md']]];
		$this->assertFalse(WebhookEventFilter::matches(self::NOTES_FILTER, $data));
	}

	public function testMissingFieldNeverMatches(): void {
		$data = ['event' => ['node' => ['id' => 42]]]; // no `path`
		$this->assertFalse(WebhookEventFilter::matches(self::NOTES_FILTER, $data));
	}

	public function testAllEntriesMustMatch(): void {
		$filter = [
			'event.node.path' => WebhookPresets::NOTES_PATH_PATTERN,
			'event.class' => 'OCP\\Files\\Events\\Node\\NodeWrittenEvent',
		];
		$base = ['node' => ['path' => '/admin/files/Notes/todo.md']];
		$this->assertTrue(WebhookEventFilter::matches($filter, ['event' => $base + ['class' => 'OCP\\Files\\Events\\Node\\NodeWrittenEvent']]));
		// Path matches but class does not ⇒ overall no match.
		$this->assertFalse(WebhookEventFilter::matches($filter, ['event' => $base + ['class' => 'OCP\\Files\\Events\\Node\\NodeCreatedEvent']]));
	}

	public function testNonScalarActualDoesNotMatchRegex(): void {
		$data = ['event' => ['node' => ['path' => ['unexpected' => 'array']]]];
		$this->assertFalse(WebhookEventFilter::matches(self::NOTES_FILTER, $data));
	}
}
