<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Listener;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Listener\AstrolabeAdminSettingsListener;
use OCP\IConfig;
use OCP\IUser;
use OCP\Settings\Events\DeclarativeSettingsGetValueEvent;
use OCP\Settings\Events\DeclarativeSettingsSetValueEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AstrolabeAdminSettingsListener.
 *
 * After the auth refactor the listener handles three system-config fields:
 * mcp_server_url, mcp_server_public_url, astrolabe_client_id.
 */
final class AstrolabeAdminSettingsListenerTest extends TestCase {
	private IConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;
	private AstrolabeAdminSettingsListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new AstrolabeAdminSettingsListener($this->config, $this->logger);
	}

	/**
	 * @dataProvider provideKnownFields
	 */
	public function testRoundTripsKnownFields(string $fieldId, string $value): void {
		$this->config->expects($this->once())
			->method('setSystemValue')
			->with($fieldId, $value);

		$saveEvent = $this->buildSetEvent($fieldId, $value);
		$this->listener->handle($saveEvent);
		$this->assertTrue($saveEvent->isPropagationStopped());

		$this->config->expects($this->once())
			->method('getSystemValue')
			->with($fieldId, '')
			->willReturn($value);

		$readEvent = $this->buildGetEvent($fieldId);
		$this->listener->handle($readEvent);
		$this->assertSame($value, $readEvent->getValue());
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function provideKnownFields(): array {
		return [
			'internal MCP URL' => ['mcp_server_url', 'http://localhost:8000'],
			'public MCP URL' => ['mcp_server_public_url', 'https://mcp.example.com'],
			'OIDC client identifier' => ['astrolabe_client_id', 'astrolabe-client'],
		];
	}

	public function testUnknownFieldDoesNotStopPropagationOnSave(): void {
		// Unknown field IDs must not be silently consumed — otherwise a
		// future rename would drop saves with no log trail.
		$this->config->expects($this->never())->method('setSystemValue');

		$event = $this->buildSetEvent('some_unknown_field', 'whatever');
		$this->listener->handle($event);

		$this->assertFalse(
			$event->isPropagationStopped(),
			'Listener must not stop propagation for unknown field IDs',
		);
	}

	public function testUnknownFieldIsIgnoredOnRead(): void {
		// The listener must not touch config for unknown field IDs;
		// another handler in the chain may own the field.
		$this->config->expects($this->never())->method('getSystemValue');

		$event = $this->buildGetEvent('some_unknown_field');
		$this->listener->handle($event);

		// DeclarativeSettingsGetValueEvent::getValue() throws when nothing
		// has set the value yet, which is the contract for "this handler
		// didn't claim the field".
		$this->expectException(\Exception::class);
		$event->getValue();
	}

	public function testIgnoresEventForDifferentApp(): void {
		$this->config->expects($this->never())->method('setSystemValue');

		$user = $this->createMock(IUser::class);
		$event = new DeclarativeSettingsSetValueEvent(
			$user,
			'someotherapp',
			'astrolabe-admin-settings',
			'mcp_server_url',
			'https://other',
		);

		$this->listener->handle($event);
		$this->assertFalse($event->isPropagationStopped());
	}

	public function testIgnoresEventForDifferentForm(): void {
		$this->config->expects($this->never())->method('setSystemValue');

		$user = $this->createMock(IUser::class);
		$event = new DeclarativeSettingsSetValueEvent(
			$user,
			Application::APP_ID,
			'some-other-form',
			'mcp_server_url',
			'https://other',
		);

		$this->listener->handle($event);
		$this->assertFalse($event->isPropagationStopped());
	}

	private function buildSetEvent(string $fieldId, mixed $value): DeclarativeSettingsSetValueEvent {
		$user = $this->createMock(IUser::class);
		return new DeclarativeSettingsSetValueEvent(
			$user,
			Application::APP_ID,
			'astrolabe-admin-settings',
			$fieldId,
			$value,
		);
	}

	private function buildGetEvent(string $fieldId): DeclarativeSettingsGetValueEvent {
		$user = $this->createMock(IUser::class);
		return new DeclarativeSettingsGetValueEvent(
			$user,
			Application::APP_ID,
			'astrolabe-admin-settings',
			$fieldId,
		);
	}
}
