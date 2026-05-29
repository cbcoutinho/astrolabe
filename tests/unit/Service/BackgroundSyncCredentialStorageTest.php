<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service;

use OCA\Astrolabe\Service\BackgroundSyncCredentialStorage;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BackgroundSyncCredentialStorageTest extends TestCase {
	private IConfig&MockObject $config;
	private ICrypto&MockObject $crypto;
	private LoggerInterface&MockObject $logger;
	private BackgroundSyncCredentialStorage $storage;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->storage = new BackgroundSyncCredentialStorage(
			$this->config,
			$this->crypto,
			$this->logger,
		);
	}

	public function testStoreEncryptsAndWritesAllThreeKeys(): void {
		$this->crypto->expects($this->once())
			->method('encrypt')
			->with('aaaaa-bbbbb-ccccc-ddddd-eeeee')
			->willReturn('ENC');

		$written = [];
		$this->config->expects($this->exactly(3))
			->method('setUserValue')
			->willReturnCallback(function ($uid, $app, $key, $value) use (&$written): void {
				$this->assertSame('alice', $uid);
				$this->assertSame('astrolabe', $app);
				$written[$key] = $value;
			});

		$this->storage->storeAppPassword('alice', 'aaaaa-bbbbb-ccccc-ddddd-eeeee');

		$this->assertSame('ENC', $written['background_sync_password']);
		$this->assertSame('app_password', $written['background_sync_type']);
		$this->assertArrayHasKey('background_sync_provisioned_at', $written);
	}

	public function testGetDecryptsStoredPassword(): void {
		$this->config->method('getUserValue')
			->with('alice', 'astrolabe', 'background_sync_password', '')
			->willReturn('ENC');
		$this->crypto->method('decrypt')->with('ENC')->willReturn('plain-pw');

		$this->assertSame('plain-pw', $this->storage->getAppPassword('alice'));
	}

	public function testGetReturnsNullWhenNoCredential(): void {
		$this->config->method('getUserValue')->willReturn('');
		$this->crypto->expects($this->never())->method('decrypt');

		$this->assertNull($this->storage->getAppPassword('alice'));
	}

	public function testGetReturnsNullOnDecryptFailure(): void {
		$this->config->method('getUserValue')->willReturn('ENC');
		$this->crypto->method('decrypt')->willThrowException(new \Exception('bad key'));

		$this->assertNull($this->storage->getAppPassword('alice'));
	}

	public function testDeleteRemovesAllThreeKeys(): void {
		$deleted = [];
		$this->config->expects($this->exactly(3))
			->method('deleteUserValue')
			->willReturnCallback(function ($uid, $app, $key) use (&$deleted): void {
				$deleted[] = $key;
			});

		$this->storage->deleteAppPassword('alice');

		$this->assertEqualsCanonicalizing(
			['background_sync_password', 'background_sync_type', 'background_sync_provisioned_at'],
			$deleted,
		);
	}

	public function testHasAccessReflectsStoredCredential(): void {
		$this->config->method('getUserValue')->willReturn('ENC');
		$this->crypto->method('decrypt')->willReturn('pw');
		$this->assertTrue($this->storage->hasAccess('alice'));
	}

	public function testHasAccessFalseWhenNoCredential(): void {
		$this->config->method('getUserValue')->willReturn('');
		$this->assertFalse($this->storage->hasAccess('alice'));
	}

	public function testGetProvisionedAtParsesTimestampOrNull(): void {
		$this->config->method('getUserValue')
			->willReturnOnConsecutiveCalls('1700000000', '');
		$this->assertSame(1700000000, $this->storage->getProvisionedAt('alice'));
		$this->assertNull($this->storage->getProvisionedAt('bob'));
	}
}
