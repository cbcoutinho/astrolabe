<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Assistant;

/**
 * A summary could not be scheduled.
 *
 * Carries the HTTP status the controller should surface, so the mapping from
 * failure reason to status code lives with the reason rather than being
 * re-derived at the boundary.
 *
 * The status is narrowed to the set this service actually raises, which is what
 * lets the controller hand it straight to a JSONResponse without a cast.
 */
final class SummaryException extends \RuntimeException {
	/**
	 * @param 403|422|500|502|503 $statusCode
	 */
	public function __construct(
		string $message,
		private int $statusCode,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	/**
	 * @return 403|422|500|502|503
	 */
	public function getStatusCode(): int {
		return $this->statusCode;
	}
}
