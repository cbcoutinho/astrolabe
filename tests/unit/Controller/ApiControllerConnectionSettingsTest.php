<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCP\AppFramework\Http;

/**
 * Tests for the admin MCP connection-settings endpoint: invalid values never
 * reach system config, and the webhook secret stays write-only.
 */
final class ApiControllerConnectionSettingsTest extends AbstractApiControllerTestCase {
	public function testSavesValidSettingsToSystemConfig(): void {
		$this->config->expects($this->once())
			->method('setSystemValues')
			->with([
				'mcp_server_url' => 'https://acme.tenants.example.com',
				'mcp_server_public_url' => '',
				'astrolabe_client_id' => 'astrolabe-client',
				'mcp_webhook_secret' => 's3cr3t',
			]);
		$this->config->method('getSystemValue')
			->with('mcp_webhook_secret', '')
			->willReturn('s3cr3t');

		$response = $this->controller->saveConnectionSettings(
			serverUrl: 'https://acme.tenants.example.com',
			clientId: 'astrolabe-client',
			webhookSecret: 's3cr3t',
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertTrue($data['config']['webhookSecretConfigured']);
		$this->assertArrayNotHasKey('webhookSecret', $data['config']);
	}

	public function testRejectsWhitespaceWithoutStoringAnything(): void {
		$this->config->expects($this->never())->method('setSystemValues');
		$this->config->expects($this->never())->method('setSystemValue');

		$response = $this->controller->saveConnectionSettings(
			serverUrl: 'https://acme.tenants.example.com ',
			clientId: ' astrolabe-client',
		);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame(['mcp_server_url', 'astrolabe_client_id'], array_keys($data['errors']));
	}

	public function testEmptySecretKeepsTheStoredOne(): void {
		$this->config->expects($this->once())
			->method('setSystemValues')
			->with($this->logicalNot($this->arrayHasKey('mcp_webhook_secret')));
		$this->config->method('getSystemValue')->willReturn('already-set');

		$response = $this->controller->saveConnectionSettings(serverUrl: 'https://acme.example.com');

		$this->assertTrue($response->getData()['config']['webhookSecretConfigured']);
	}
}
