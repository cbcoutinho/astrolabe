<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCP\AppFramework\Http;
use OCP\IUser;

/**
 * Controller tests for the OAuth refresh diagnostic endpoint
 * (ApiController::refreshDiagnostic).
 *
 * The endpoint is the operator-facing tool for diagnosing "MCP server
 * authorization required" loops on deployments where nextcloud.log is
 * inaccessible (e.g. Hetzner Storage Share). It runs a real refresh,
 * persists a rotated refresh_token under the storage lock, and returns
 * a structured report including IdpTokenRefresher::getLastError() on
 * failure. The cases below cover the auth gate, the
 * "never authorized" / failed / successful refresh paths, the
 * stale-read race fix (re-reading the refresh_token inside the lock),
 * and the concurrent-deletion abort case.
 */
final class ApiControllerRefreshDiagnosticTest extends AbstractApiControllerTestCase {
	public function testReturns401WhenUnauthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->refreshDiagnostic();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertIsArray($data);
		$this->assertFalse($data['success']);
	}

	public function testReturns403WhenNonAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(false);

		$response = $this->controller->refreshDiagnostic();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertIsArray($data);
		$this->assertFalse($data['success']);
		$this->assertEquals('Admin privileges required', $data['error']);
	}

	public function testReportsNoStoredToken(): void {
		$this->authenticateAdmin();
		$this->tokenStorage->method('getUserToken')->with('admin')->willReturn(null);
		// withTokenLock must NOT fire — early return before the refresh
		// attempt should skip locking entirely.
		$this->tokenStorage->expects($this->never())->method('withTokenLock');

		$response = $this->controller->refreshDiagnostic();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertIsArray($data);
		$this->assertTrue($data['success']);
		$this->assertFalse($data['diagnostic']['has_stored_token']);
		$this->assertStringContainsString('No OAuth token stored', $data['diagnostic']['conclusion']);
		$this->assertArrayNotHasKey('refresh_attempt', $data['diagnostic']);
	}

	public function testReportsFailedRefresh(): void {
		$this->authenticateAdmin();
		$this->passthroughLock();
		$now = time();
		$storedToken = [
			'access_token' => 'old-access',
			'refresh_token' => 'old-refresh',
			'expires_at' => $now - 10,  // expired
			'issued_at' => $now - 3610,
		];
		$this->tokenStorage->method('getUserToken')->willReturn($storedToken);
		$this->tokenStorage->method('isExpired')->willReturn(true);

		// Refresh fails — refresher returns null and exposes its reason
		// via getLastError(). The fixture reason mirrors what the live
		// service emits when the IdP rejects with HTTP 401.
		$this->tokenRefresher->method('refreshAccessToken')
			->willReturn(null);
		$this->tokenRefresher->method('getLastError')
			->willReturn('IdP rejected refresh_token (HTTP 401). Refresh token likely expired or revoked.');
		// Failed refresh must NOT persist anything.
		$this->tokenStorage->expects($this->never())->method('storeUserToken');

		$response = $this->controller->refreshDiagnostic();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['success']);
		$this->assertEquals('failed', $data['diagnostic']['refresh_attempt']);
		$this->assertStringContainsString('HTTP 401', $data['diagnostic']['refresh_error']);
	}

	public function testReportsSuccessfulRefreshWithRotation(): void {
		$this->authenticateAdmin();
		$this->passthroughLock();
		$now = time();
		$storedToken = [
			'access_token' => 'old-access',
			'refresh_token' => 'old-refresh',
			'expires_at' => $now + 30,
			'issued_at' => $now - 3570,
		];
		$this->tokenStorage->method('getUserToken')->willReturn($storedToken);
		$this->tokenStorage->method('isExpired')->willReturn(false);

		$this->tokenRefresher->method('refreshAccessToken')
			->willReturn([
				'access_token' => 'new-access',
				'refresh_token' => 'new-rotated-refresh',
				'expires_in' => 3600,
			]);

		// Rotated refresh_token MUST be persisted, otherwise the next
		// real refresh would redeem the now-invalidated old token.
		$this->tokenStorage->expects($this->once())
			->method('storeUserToken')
			->with(
				'admin',
				'new-access',
				'new-rotated-refresh',
				$this->greaterThan($now),
				$this->greaterThanOrEqual($now),
			);

		$response = $this->controller->refreshDiagnostic();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['success']);
		$this->assertEquals('success', $data['diagnostic']['refresh_attempt']);
		$this->assertTrue($data['diagnostic']['idp_rotated_refresh_token']);
		$this->assertEquals(3600, $data['diagnostic']['new_access_token_expires_in']);
	}

	public function testReReadsRefreshTokenInsideLock(): void {
		// Regression test for the stale-read race: if RefreshUserTokens
		// rotates the refresh_token between the outer getUserToken() and
		// the lock acquisition, the diagnostic must redeem the *current*
		// value, not the snapshot taken before the lock.
		$this->authenticateAdmin();
		$this->passthroughLock();
		$now = time();
		$staleToken = [
			'access_token' => 'old-access',
			'refresh_token' => 'STALE-refresh',
			'expires_at' => $now - 10,
		];
		$freshToken = [
			'access_token' => 'mid-access',
			'refresh_token' => 'FRESH-refresh',
			'expires_at' => $now + 1800,
		];
		// First call (outside lock) returns the stale snapshot; second
		// call (inside lock, after the simulated race) returns the rotated
		// fresh token. The fix re-reads inside the lock and must redeem
		// the fresh value.
		$this->tokenStorage->method('getUserToken')
			->willReturnOnConsecutiveCalls($staleToken, $freshToken);
		$this->tokenStorage->method('isExpired')->willReturn(true);

		$capturedRefreshArg = null;
		$this->tokenRefresher->method('refreshAccessToken')
			->willReturnCallback(function (string $arg) use (&$capturedRefreshArg): ?array {
				$capturedRefreshArg = $arg;
				return [
					'access_token' => 'final-access',
					'refresh_token' => 'final-refresh',
					'expires_in' => 3600,
				];
			});
		$this->tokenStorage->expects($this->once())->method('storeUserToken');

		$response = $this->controller->refreshDiagnostic();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals(
			'FRESH-refresh',
			$capturedRefreshArg,
			'Diagnostic must use the refresh_token re-read inside the lock, not the pre-lock snapshot',
		);
	}

	public function testReportsConcurrentDeletionGracefully(): void {
		// If RefreshUserTokens (or a user-triggered revoke) deletes the
		// stored token between the outer getUserToken() and lock
		// acquisition, the diagnostic must NOT call refreshAccessToken()
		// and must NOT surface "Refresh failed — see refresh_error" with
		// a null refresh_error. It must report a distinct "aborted"
		// outcome so the admin can rerun the diagnostic.
		$this->authenticateAdmin();
		$this->passthroughLock();

		$now = time();
		$outerToken = [
			'access_token' => 'old-access',
			'refresh_token' => 'old-refresh',
			'expires_at' => $now - 10,
		];
		// Outer read returns a token; inner read (inside the lock)
		// returns null — the concurrent-deletion race.
		$this->tokenStorage->method('getUserToken')
			->willReturnOnConsecutiveCalls($outerToken, null);
		$this->tokenStorage->method('isExpired')->willReturn(true);

		$this->tokenRefresher->expects($this->never())->method('refreshAccessToken');
		$this->tokenStorage->expects($this->never())->method('storeUserToken');

		$response = $this->controller->refreshDiagnostic();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['success']);
		$this->assertEquals('aborted', $data['diagnostic']['refresh_attempt']);
		$this->assertArrayNotHasKey('refresh_error', $data['diagnostic']);
		$this->assertStringContainsString(
			'concurrently deleted',
			$data['diagnostic']['conclusion'],
		);
	}
}
