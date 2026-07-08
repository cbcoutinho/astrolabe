<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Access;

use OCA\Astrolabe\Service\Access\AccessDecision;
use OCA\Astrolabe\Service\Access\MailAccessVerifier;
use PHPUnit\Framework\TestCase;

/**
 * MailAccessVerifier currently always DELEGATEs (see its docblock): checking
 * ownership of a client-supplied mailbox_id without confirming the message
 * (doc_id) lives in it would allow a false-ALLOW, so mail is left to the MCP
 * backstop until a doc_id→owner membership check can be done server-side.
 */
final class MailAccessVerifierTest extends TestCase {
	private MailAccessVerifier $verifier;

	protected function setUp(): void {
		parent::setUp();
		$this->verifier = new MailAccessVerifier();
	}

	public function testHandlesMailMessageDocType(): void {
		$this->assertSame(['mail_message'], $this->verifier->docTypes());
	}

	public function testAlwaysDelegates(): void {
		$this->assertSame(AccessDecision::DELEGATE, $this->verifier->verify('alice', ['doc_type' => 'mail_message', 'id' => 9]));
		$this->assertSame(AccessDecision::DELEGATE, $this->verifier->verify('alice', [
			'doc_type' => 'mail_message',
			'id' => 9,
			'metadata' => ['mailbox_id' => 2],
		]));
	}
}
