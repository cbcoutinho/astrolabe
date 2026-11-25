<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Mcp;

use Mcp\Client\Transport\HttpTransport;
use OCA\Astrolabe\Service\Mcp\McpClientFactory;
use PHPUnit\Framework\TestCase;

final class McpClientFactoryTest extends TestCase {
	/** nextcloud-mcp-server's document_viewer.MAX_BYTES: the largest PDF the viewer serves. */
	private const SERVER_MAX_PDF_BYTES = 25 * 1024 * 1024;

	public function testAnMcpAppConnectionCarriesTheLargestPdfTheViewerServes(): void {
		// read_document_bytes and preview_changes return the PDF base64-encoded
		// in one tool result; allow 64 KiB for the JSON-RPC envelope around it.
		// Padded base64 length: 4 characters per started 3 bytes.
		$encoded = 4 * intdiv(self::SERVER_MAX_PDF_BYTES + 2, 3);
		foreach (McpClientFactory::messageLimits(true) as $limit) {
			$this->assertGreaterThan($encoded + 64 * 1024, $limit);
		}
	}

	public function testOtherConnectionsKeepTheSdkDefault(): void {
		$this->assertSame([
			'maxSseBufferBytes' => HttpTransport::DEFAULT_MAX_SSE_BUFFER_BYTES,
			'maxResponseBodyBytes' => HttpTransport::DEFAULT_MAX_RESPONSE_BODY_BYTES,
		], McpClientFactory::messageLimits(false));
	}
}
