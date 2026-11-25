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
		$encoded = 4 * intdiv(self::SERVER_MAX_PDF_BYTES + 2, 3);
		$this->assertGreaterThan($encoded + 64 * 1024, McpClientFactory::maxMessageBytes(true));
	}

	public function testOtherConnectionsKeepTheSdkDefault(): void {
		$this->assertSame(HttpTransport::DEFAULT_MAX_SSE_BUFFER_BYTES, McpClientFactory::maxMessageBytes(false));
	}
}
