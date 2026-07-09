<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Access;

/**
 * Access verifier scaffold for Mail messages.
 *
 * Currently a **conservative no-op that always DELEGATEs** to the MCP
 * verify-on-read backstop. The previous implementation checked mailbox ownership
 * from a (client-supplied, on the content-fetch path) `mailbox_id` but never
 * confirmed the requested message (`doc_id`) actually lives in that mailbox — so
 * a user could pair another user's message `doc_id` with a mailbox they own and
 * get a false-ALLOW.
 *
 * A sound local check must resolve the message by `doc_id` and confirm it belongs
 * to the user (e.g. `IMailManager::getMessage($uid, $messageId)`), which depends
 * on the confirmed `doc_id`/message-id contract the MCP-side Mail verifier owns.
 * Until that lands (coordinated follow-up, Deck board 11), delegate to the MCP
 * backstop, which already does the membership check.
 */
final class MailAccessVerifier implements AccessVerifierInterface {
	#[\Override]
	public function docTypes(): array {
		return ['mail_message'];
	}

	#[\Override]
	public function verify(string $uid, array $doc): AccessDecision {
		return AccessDecision::DELEGATE;
	}
}
