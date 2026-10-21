<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Settings;

use OCA\Astrolabe\Settings\ConnectionSettings;
use PHPUnit\Framework\TestCase;

final class ConnectionSettingsTest extends TestCase {
	/**
	 * @dataProvider provideValid
	 */
	public function testAcceptsValidValue(string $key, string $value): void {
		$this->assertSame([], ConnectionSettings::validate([$key => $value]));
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function provideValid(): array {
		return [
			'tenant URL' => [ConnectionSettings::SERVER_URL, 'https://acme.tenants.astrolabecloud.com'],
			'local http URL with port' => [ConnectionSettings::SERVER_URL, 'http://localhost:8000'],
			'public URL with path' => [ConnectionSettings::PUBLIC_URL, 'https://mcp.example.com/mcp'],
			'empty URL (unconfigured)' => [ConnectionSettings::SERVER_URL, ''],
			'client id' => [ConnectionSettings::CLIENT_ID, 'Xq7v2LkP9wR3mN8s'],
			'empty secret (keep stored)' => [ConnectionSettings::WEBHOOK_SECRET, ''],
			'secret with symbols' => [ConnectionSettings::WEBHOOK_SECRET, 'a#b$c%d&e+f/g=h'],
		];
	}

	/**
	 * @dataProvider provideInvalid
	 */
	public function testRejectsInvalidValue(string $key, string $value, string $message): void {
		$this->assertSame([$key => $message], ConnectionSettings::validate([$key => $value]));
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function provideInvalid(): array {
		$edge = 'Remove the space or invisible character at the start or end.';
		$inner = 'Must not contain spaces or invisible characters.';
		$url = 'Must be a full http:// or https:// URL.';
		return [
			'trailing space on URL' => [ConnectionSettings::SERVER_URL, 'https://acme.example.com ', $edge],
			'leading space on URL' => [ConnectionSettings::PUBLIC_URL, ' https://acme.example.com', $edge],
			'trailing newline on client id' => [ConnectionSettings::CLIENT_ID, "client-id\n", $edge],
			'trailing tab on secret' => [ConnectionSettings::WEBHOOK_SECRET, "s3cr3t\t", $edge],
			'non-breaking space' => [ConnectionSettings::CLIENT_ID, "client-id\u{00A0}", $edge],
			'zero-width space' => [ConnectionSettings::CLIENT_ID, "\u{200B}client-id", $edge],
			'space inside client id' => [ConnectionSettings::CLIENT_ID, 'client id', $inner],
			'space inside URL' => [ConnectionSettings::SERVER_URL, 'https://acme .example.com', $inner],
			'invalid UTF-8' => [ConnectionSettings::CLIENT_ID, "client\xff", 'Contains invalid characters.'],
			'URL without scheme' => [ConnectionSettings::SERVER_URL, 'acme.example.com', $url],
			'non-http scheme' => [ConnectionSettings::SERVER_URL, 'ftp://acme.example.com', $url],
			'scheme without host' => [ConnectionSettings::PUBLIC_URL, 'https://', $url],
		];
	}

	public function testReportsEveryInvalidField(): void {
		$errors = ConnectionSettings::validate([
			ConnectionSettings::SERVER_URL => 'https://ok.example.com',
			ConnectionSettings::PUBLIC_URL => 'nope',
			ConnectionSettings::CLIENT_ID => ' id',
		]);

		$this->assertSame([ConnectionSettings::PUBLIC_URL, ConnectionSettings::CLIENT_ID], array_keys($errors));
	}
}
