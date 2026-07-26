<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Mcp;

/**
 * The MCP server could not be reached, or refused Astrolabe's token.
 *
 * Distinguished from an ordinary tool failure so callers can tell "the model
 * asked for something that went wrong" apart from "this integration is
 * misconfigured", which need very different messages.
 */
final class McpUnavailableException extends \RuntimeException {
	public function __construct(string $message, ?\Throwable $previous = null) {
		parent::__construct($message, 0, $previous);
	}
}
