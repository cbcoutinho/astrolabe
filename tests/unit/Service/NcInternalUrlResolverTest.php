<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service;

use OCA\Astrolabe\Service\NcInternalUrlResolver;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for NcInternalUrlResolver.
 *
 * Asserts the single source of truth for the internal-URL resolution
 * rule (trim + validate + port warning + rtrim).
 */
final class NcInternalUrlResolverTest extends TestCase {
	private IConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;
	private NcInternalUrlResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->resolver = new NcInternalUrlResolver($this->config, $this->logger);
	}

	/**
	 * @dataProvider provideResolutionCases
	 */
	public function testResolve(mixed $configValue, string $expected): void {
		$this->config->method('getSystemValue')
			->with('astrolabe_internal_url', '')
			->willReturn($configValue);

		$this->assertSame($expected, $this->resolver->resolve());
	}

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public static function provideResolutionCases(): array {
		return [
			'empty string → localhost' => ['', 'http://localhost'],
			'non-string (false) → localhost' => [false, 'http://localhost'],
			'whitespace-only → localhost' => ['   ', 'http://localhost'],
			'valid URL → returned as-is' => ['https://cloud.example.com', 'https://cloud.example.com'],
			'trailing slash trimmed' => ['https://cloud.example.com/', 'https://cloud.example.com'],
			'trailing space trimmed (the reviewer regression)' => ['https://cloud.example.com ', 'https://cloud.example.com'],
			'leading + trailing whitespace trimmed' => ["\thttps://cloud.example.com\n", 'https://cloud.example.com'],
			'kubernetes service URL preserved' => ['http://nextcloud.default.svc:80', 'http://nextcloud.default.svc:80'],
		];
	}

	public function testInvalidUrlLogsWarningAndFallsBackToLocalhost(): void {
		$this->config->method('getSystemValue')
			->with('astrolabe_internal_url', '')
			->willReturn('not-a-url');

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Invalid astrolabe_internal_url format'),
				$this->callback(fn ($ctx) => ($ctx['configured_url'] ?? null) === 'not-a-url'),
			);

		$this->assertSame('http://localhost', $this->resolver->resolve());
	}

	public function testHighPortUrlLogsExternalPortMappingWarning(): void {
		$this->config->method('getSystemValue')
			->with('astrolabe_internal_url', '')
			->willReturn('http://localhost:8080');

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('external port mapping'),
				$this->callback(fn ($ctx) => ($ctx['configured_url'] ?? null) === 'http://localhost:8080'),
			);

		$this->assertSame('http://localhost:8080', $this->resolver->resolve());
	}
}
