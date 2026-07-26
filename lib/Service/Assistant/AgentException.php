<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Assistant;

/**
 * An agent turn could not be completed.
 *
 * Messages here reach the user in the Assistant chat, so they are written to be
 * read by one — what went wrong and, where possible, what to check.
 */
final class AgentException extends \RuntimeException {
	public function __construct(string $message, ?\Throwable $previous = null) {
		parent::__construct($message, 0, $previous);
	}
}
