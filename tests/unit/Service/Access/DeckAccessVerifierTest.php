<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Access;

use OCA\Astrolabe\Service\Access\AccessDecision;
use OCA\Astrolabe\Service\Access\DeckAccessVerifier;
use PHPUnit\Framework\TestCase;

/**
 * DeckAccessVerifier currently always DELEGATEs (see its docblock): checking READ
 * on a client-supplied board_id without confirming the card (doc_id) belongs to
 * that board would allow a false-ALLOW, so deck is left to the MCP backstop until
 * a doc_id→board membership check can be done server-side.
 */
final class DeckAccessVerifierTest extends TestCase {
	private DeckAccessVerifier $verifier;

	protected function setUp(): void {
		parent::setUp();
		$this->verifier = new DeckAccessVerifier();
	}

	public function testHandlesDeckCardDocType(): void {
		$this->assertSame(['deck_card'], $this->verifier->docTypes());
	}

	public function testAlwaysDelegates(): void {
		$this->assertSame(AccessDecision::DELEGATE, $this->verifier->verify('alice', ['doc_type' => 'deck_card', 'id' => 5]));
		$this->assertSame(AccessDecision::DELEGATE, $this->verifier->verify('alice', [
			'doc_type' => 'deck_card',
			'id' => 5,
			'metadata' => ['board_id' => 3],
		]));
	}
}
