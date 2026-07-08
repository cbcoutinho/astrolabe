<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Access;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Access verifier for Mail messages.
 *
 * Mail has no sharing model — a mailbox belongs to exactly one account, which
 * belongs to exactly one user — so access reduces to ownership. Resolves Mail's
 * {@see \OCA\Mail\Contracts\IMailManager} lazily (by FQCN string) and calls
 * ``getMailbox($uid, $mailboxId)``, which throws when the mailbox isn't owned by
 * the user. A "not found / not yours" error (ClientException) is a definitive
 * DENY; any other failure (or a missing identifier) is DELEGATE.
 */
final class MailAccessVerifier implements AccessVerifierInterface {
	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function docTypes(): array {
		return ['mail_message'];
	}

	#[\Override]
	public function verify(string $uid, array $doc): AccessDecision {
		$metadata = $doc['metadata'] ?? [];
		$mailboxId = isset($metadata['mailbox_id']) && is_numeric($metadata['mailbox_id'])
			? (int)$metadata['mailbox_id']
			: 0;
		if ($mailboxId <= 0) {
			return AccessDecision::DELEGATE;
		}

		try {
			/** @psalm-suppress MixedAssignment resolved by FQCN string; typed loosely on purpose */
			$manager = $this->container->get('OCA\\Mail\\Contracts\\IMailManager');
		} catch (\Throwable $e) {
			$this->logger->debug('Mail manager unavailable; delegating', ['error' => $e->getMessage()]);
			return AccessDecision::DELEGATE;
		}
		if (!is_object($manager) || !method_exists($manager, 'getMailbox')) {
			return AccessDecision::DELEGATE;
		}

		try {
			/** @psalm-suppress MixedMethodCall cross-app contract, resolved dynamically */
			$manager->getMailbox($uid, $mailboxId);
			return AccessDecision::ALLOWED;
		} catch (\Throwable $e) {
			// getMailbox throws ClientException when the mailbox isn't the user's
			// — a definitive deny. Anything else is transient ⇒ delegate.
			$isClientError = str_contains((new \ReflectionClass($e))->getShortName(), 'ClientException');
			return $isClientError ? AccessDecision::DENIED : AccessDecision::DELEGATE;
		}
	}
}
