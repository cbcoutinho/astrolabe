<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Access;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Access verifier for Deck cards.
 *
 * Resolves Deck's own {@see \OCA\Deck\Service\PermissionService} lazily (by FQCN
 * string, so Astrolabe carries no compile-time dependency on Deck) and asks it
 * whether the user has READ on the card's board. The registry only dispatches
 * here when the Deck app is installed, but resolution is still guarded so any
 * failure degrades to DELEGATE (fall back to the MCP backstop) rather than error.
 */
final class DeckAccessVerifier implements AccessVerifierInterface {
	/**
	 * Index of the READ permission in PermissionService::getPermissions()'s
	 * result — mirrors \OCA\Deck\Db\Acl::PERMISSION_READ (0). Hardcoded so this
	 * class never references a Deck class that may be absent at load time.
	 */
	private const PERMISSION_READ = 0;

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function docTypes(): array {
		return ['deck_card'];
	}

	#[\Override]
	public function verify(string $uid, array $doc): AccessDecision {
		$metadata = $doc['metadata'] ?? [];
		$boardId = isset($metadata['board_id']) && is_numeric($metadata['board_id'])
			? (int)$metadata['board_id']
			: 0;
		if ($boardId <= 0) {
			return AccessDecision::DELEGATE;
		}

		try {
			/** @psalm-suppress MixedAssignment resolved by FQCN string; typed loosely on purpose */
			$service = $this->container->get('OCA\\Deck\\Service\\PermissionService');
			if (!is_object($service) || !method_exists($service, 'getPermissions')) {
				return AccessDecision::DELEGATE;
			}
			/**
			 * @psalm-suppress MixedMethodCall, MixedAssignment cross-app service, resolved dynamically
			 * @var array<int, bool> $permissions
			 */
			$permissions = $service->getPermissions($boardId, $uid);
			return !empty($permissions[self::PERMISSION_READ]) ? AccessDecision::ALLOWED : AccessDecision::DENIED;
		} catch (\Throwable $e) {
			$this->logger->debug('Deck access check could not decide; delegating', ['board_id' => $boardId, 'error' => $e->getMessage()]);
			return AccessDecision::DELEGATE;
		}
	}
}
