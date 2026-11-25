<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * An MCP App view document with its own CSP (exploratory POC).
 *
 * The headers are emitted from the callback, after the middleware's, so this
 * policy replaces Nextcloud's nonce-based one for this document only.
 *
 * @template-extends Response<Http::STATUS_OK, array<string, mixed>>
 */
final class McpAppViewResponse extends Response implements ICallbackResponse {
	public function __construct(
		private string $html,
		private string $csp,
	) {
		parent::__construct();
	}

	#[\Override]
	public function callback(IOutput $output): void {
		$output->setHeader('Content-Security-Policy: ' . $this->csp);
		$output->setHeader('Content-Type: text/html; charset=utf-8');
		$output->setOutput($this->html);
	}
}
