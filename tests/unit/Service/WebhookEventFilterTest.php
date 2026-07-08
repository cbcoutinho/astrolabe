<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service;

use OCA\Astrolabe\Service\WebhookEventFilter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WebhookEventFilter.
 *
 * The filter must behave identically to the `webhook_listeners` app's
 * PHPMongoQuery for the subset the presets use: dotted field paths, a
 * ``/pattern/flags`` regex value, and literal equality otherwise.
 */
final class WebhookEventFilterTest extends TestCase {
	/** The Notes preset filter — the real-world case this must get right. */
	private const NOTES_FILTER = ['event.node.path' => '/^\/.*\/files\/Notes\//'];

	public function testEmptyFilterAlwaysMatches(): void {
		$this->assertTrue(WebhookEventFilter::matches([], ['event' => ['node' => ['path' => '/anything']]]));
	}

	public function testNotesRegexMatchesNotePath(): void {
		$data = ['event' => ['node' => ['path' => '/admin/files/Notes/todo.md']]];
		$this->assertTrue(WebhookEventFilter::matches(self::NOTES_FILTER, $data));
	}

	public function testNotesRegexRejectsNonNotePath(): void {
		$data = ['event' => ['node' => ['path' => '/admin/files/Documents/report.pdf']]];
		$this->assertFalse(WebhookEventFilter::matches(self::NOTES_FILTER, $data));
	}

	public function testMissingFieldNeverMatches(): void {
		$data = ['event' => ['node' => ['id' => 42]]]; // no `path`
		$this->assertFalse(WebhookEventFilter::matches(self::NOTES_FILTER, $data));
	}

	public function testLiteralEqualityMatches(): void {
		$filter = ['event.class' => 'OCP\\Files\\Events\\Node\\NodeWrittenEvent'];
		$data = ['event' => ['class' => 'OCP\\Files\\Events\\Node\\NodeWrittenEvent']];
		$this->assertTrue(WebhookEventFilter::matches($filter, $data));
	}

	public function testLiteralEqualityRejectsDifferentValue(): void {
		$filter = ['event.class' => 'OCP\\Files\\Events\\Node\\NodeWrittenEvent'];
		$data = ['event' => ['class' => 'OCP\\Files\\Events\\Node\\NodeCreatedEvent']];
		$this->assertFalse(WebhookEventFilter::matches($filter, $data));
	}

	public function testAllEntriesMustMatch(): void {
		$filter = [
			'event.node.path' => '/^\/.*\/files\/Notes\//',
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
