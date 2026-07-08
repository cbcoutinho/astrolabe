<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Access;

use OCA\Astrolabe\Service\Access\AccessDecision;
use OCA\Astrolabe\Service\Access\CalendarAccessVerifier;
use PHPUnit\Framework\TestCase;

/**
 * CalendarAccessVerifier currently always DELEGATEs (see its docblock): matching
 * on a per-principal calendar URI/key would risk a false-ALLOW during the
 * staleness re-check, so calendar is left to the MCP backstop until an
 * owner-scoped identifier contract is confirmed.
 */
final class CalendarAccessVerifierTest extends TestCase {
	private CalendarAccessVerifier $verifier;

	protected function setUp(): void {
		parent::setUp();
		$this->verifier = new CalendarAccessVerifier();
	}

	public function testHandlesCalendarDocType(): void {
		$this->assertSame(['calendar'], $this->verifier->docTypes());
	}

	public function testAlwaysDelegates(): void {
		$this->assertSame(AccessDecision::DELEGATE, $this->verifier->verify('alice', ['doc_type' => 'calendar', 'id' => 'x']));
		$this->assertSame(AccessDecision::DELEGATE, $this->verifier->verify('alice', [
			'doc_type' => 'calendar',
			'id' => 'x',
			'metadata' => ['calendar_uri' => 'personal'],
		]));
	}
}
