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
 * Focuses on write-time validation of astrolabe_internal_url: the save
 * itself must always succeed, but invalid values should produce a log
 * warning so managed-NC operators see the misconfiguration immediately
 * rather than discovering the silent http://localhost fallback at OAuth
 * runtime.
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
	 * @dataProvider provideInvalidInternalUrls
	 */
	public function testInvalidInternalUrlLogsWarningOnSave(string $value): void {
		$warningSeen = false;
		$this->logger->method('warning')
			->willReturnCallback(function (string $message) use (&$warningSeen): void {
				if (str_contains($message, 'astrolabe_internal_url set to a value that will fall back')) {
					$warningSeen = true;
				}
			});

		// Save still succeeds — config.setSystemValue gets the raw value.
		$this->config->expects($this->once())
			->method('setSystemValue')
			->with('astrolabe_internal_url', $value);

		$this->listener->handle($this->buildEvent('astrolabe_internal_url', $value));

		$this->assertTrue($warningSeen, 'Expected fallback-warning to be logged for invalid scheme');
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provideInvalidInternalUrls(): array {
		return [
			'bare hostname' => ['cloud.example.com'],
			'ftp scheme' => ['ftp://example.com'],
			'file scheme' => ['file:///etc/passwd'],
			'malformed' => ['not-a-url'],
		];
	}

	/**
	 * @dataProvider provideValidInternalUrls
	 */
	public function testValidInternalUrlDoesNotLogFallbackWarning(string $value): void {
		$this->logger->method('warning')
			->willReturnCallback(function (string $message): void {
				$this->assertStringNotContainsString(
					'astrolabe_internal_url set to a value that will fall back',
					$message,
					'Valid http/https URL must not trigger the fallback warning',
				);
			});

		$this->config->expects($this->once())
			->method('setSystemValue')
			->with('astrolabe_internal_url', $value);

		$this->listener->handle($this->buildEvent('astrolabe_internal_url', $value));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provideValidInternalUrls(): array {
		return [
			'https URL' => ['https://cloud.example.com'],
			'http URL' => ['http://localhost'], // NOSONAR
			'http with port' => ['http://nextcloud.default.svc:8080'], // NOSONAR
			'empty string (will skip validation)' => [''],
			'whitespace only (will skip validation)' => ['   '],
		];
	}

	public function testReadReturnsConfiguredInternalUrl(): void {
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('astrolabe_internal_url', '')
			->willReturn('https://nc.example.com');

		$event = $this->buildGetEvent('astrolabe_internal_url');
		$this->listener->handle($event);

		$this->assertSame('https://nc.example.com', $event->getValue());
	}

	public function testInvalidUrlWarningDoesNotBlockOtherFields(): void {
		// Other fields must not trigger the internal-url validation path.
		$this->logger->method('warning')
			->willReturnCallback(function (string $message): void {
				$this->assertStringNotContainsString(
					'astrolabe_internal_url',
					$message,
					'mcp_server_url save must not produce an internal-url warning',
				);
			});

		$this->config->expects($this->once())
			->method('setSystemValue')
			->with('mcp_server_url', 'ftp://example.com');

		$this->listener->handle($this->buildEvent('mcp_server_url', 'ftp://example.com'));
	}

	private function buildEvent(string $fieldId, mixed $value): DeclarativeSettingsSetValueEvent {
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
