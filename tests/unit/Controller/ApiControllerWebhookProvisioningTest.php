<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCP\AppFramework\Http;

/**
 * Controller-level test for the 428 (Precondition Required) provisioning
 * path on the webhook admin endpoints.
 *
 * Asserts that when McpServerClient signals
 * ``provisioning_required => true`` (because the MCP server returned 428),
 * the controller responds with HTTP 428 and the same flag in the body so
 * AdminSettings.vue can render the authorization CTA.
 */
final class ApiControllerWebhookProvisioningTest extends AbstractApiControllerTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->authenticateUserWithToken();
	}

	public function testGetWebhookPresetsReturns428WhenProvisioningRequired(): void {
		$this->client->method('getInstalledApps')->willReturn([
			'error' => 'Nextcloud access not provisioned',
			'provisioning_required' => true,
		]);

		$response = $this->controller->getWebhookPresets();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_PRECONDITION_REQUIRED, $response->getStatus());
		$this->assertIsArray($data);
		$this->assertFalse($data['success']);
		$this->assertTrue($data['provisioning_required']);
		$this->assertEquals('Nextcloud access not provisioned', $data['error']);
	}

	public function testGetWebhookPresetsReturns428WhenListWebhooksReportsProvisioning(): void {
		// getInstalledApps succeeds, listWebhooks returns 428 marker.
		$this->client->method('getInstalledApps')->willReturn(['apps' => ['notes']]);
		$this->client->method('listWebhooks')->willReturn([
			'error' => 'Provisioning required',
			'provisioning_required' => true,
		]);

		$response = $this->controller->getWebhookPresets();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_PRECONDITION_REQUIRED, $response->getStatus());
		$this->assertTrue($data['provisioning_required']);
	}

	public function testGetWebhookPresetsReturns500OnGenericError(): void {
		$this->client->method('getInstalledApps')->willReturn([
			'error' => 'Connection refused',
		]);

		$response = $this->controller->getWebhookPresets();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayNotHasKey('provisioning_required', $data);
	}
}
