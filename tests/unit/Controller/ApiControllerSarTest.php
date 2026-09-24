<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCP\AppFramework\Http;

/**
 * SAR export proxy (ApiController::sarSubmit / sarStatus): gated on the MCP
 * server's advertised capability, forwarded as the current user, and the MCP
 * server's 4xx answers passed through so the UI can show them.
 */
final class ApiControllerSarTest extends AbstractApiControllerTestCase {
	private const STATUS = [
		'state' => 'running',
		'archive_path' => '/Team/SAR-1.zip',
		'total' => 1,
		'processed' => 0,
		'failed' => 0,
	];

	public function testSubmitIs404WhenServerDoesNotAdvertiseSar(): void {
		$this->authenticateUserWithToken();
		$this->searchCapabilities->method('isSarExportAvailable')->willReturn(false);
		$this->client->expects($this->never())->method('submitSarExport');

		$response = $this->controller->sarSubmit(output_folder: '/Team', name: 'SAR-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testSubmitForwardsExportAsCurrentUserAndReturns202(): void {
		$this->authenticateUserWithToken('alice', 'alice-token');
		$this->searchCapabilities->method('isSarExportAvailable')->willReturn(true);
		$items = [['doc_type' => 'file', 'doc_id' => '12', 'reason' => 'letter']];
		$this->client->expects($this->once())
			->method('submitSarExport')
			->with([
				'output_folder' => '/Team',
				'name' => 'SAR-1',
				'subject' => ['Jane Doe'],
				'items' => $items,
				'queries' => ['jane'],
			], 'alice-token')
			->willReturn(self::STATUS);

		$response = $this->controller->sarSubmit(
			output_folder: '/Team',
			name: 'SAR-1',
			subject: ['Jane Doe'],
			items: $items,
			queries: ['jane'],
		);

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(self::STATUS, $response->getData());
	}

	public function testSubmitPassesServerStatusAndMessageThrough(): void {
		$this->authenticateUserWithToken();
		$this->searchCapabilities->method('isSarExportAvailable')->willReturn(true);
		$this->client->method('submitSarExport')->willReturn([
			'error' => 'an export already exists at /Team/SAR-1.status.json',
			'status' => 409,
		]);

		$response = $this->controller->sarSubmit(output_folder: '/Team', name: 'SAR-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertStringContainsString('already exists', $response->getData()['error']);
	}

	public function testSubmitWithoutUserIs401(): void {
		$this->searchCapabilities->method('isSarExportAvailable')->willReturn(true);
		$this->userSession->method('getUser')->willReturn(null);
		$this->client->expects($this->never())->method('submitSarExport');

		$response = $this->controller->sarSubmit(output_folder: '/Team', name: 'SAR-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testStatusForwardsFolderAndName(): void {
		$this->authenticateUserWithToken('alice', 'alice-token');
		$this->searchCapabilities->method('isSarExportAvailable')->willReturn(true);
		$this->client->expects($this->once())
			->method('getSarExport')
			->with('/Team', 'SAR-1', 'alice-token')
			->willReturn(['state' => 'done'] + self::STATUS);

		$response = $this->controller->sarStatus(output_folder: '/Team', name: 'SAR-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('done', $response->getData()['state']);
	}

	public function testStatusOfUnknownExportIs404(): void {
		$this->authenticateUserWithToken();
		$this->searchCapabilities->method('isSarExportAvailable')->willReturn(true);
		$this->client->method('getSarExport')->willReturn(['error' => 'no export', 'status' => 404]);

		$response = $this->controller->sarStatus(output_folder: '/Team', name: 'X');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
