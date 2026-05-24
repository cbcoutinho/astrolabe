<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCP\AppFramework\Http;
use OCP\IUser;

/**
 * Pin the admin-only nature of `refresh_error` exposure on the search
 * endpoint. authRequiredBody() in ApiController gates the field on
 * IGroupManager::isAdmin; this test guards against regressions that
 * would leak IdP response detail to a regular user via search's 401
 * body.
 */
final class ApiControllerSearchAuthTest extends AbstractApiControllerTestCase {
	public function testSearchOmitsRefreshErrorForNonAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(false);

		// Token resolution fails (refresh path exhausted).
		$this->tokenStorage->method('getAccessToken')->willReturn(null);

		// authRequiredBody() must short-circuit before consulting
		// getLastError() for a non-admin user. Assert by making any
		// call to it fail the test outright.
		$this->tokenRefresher->expects($this->never())
			->method('getLastError');

		$response = $this->controller->search('hello world');
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertIsArray($data);
		$this->assertFalse($data['success']);
		$this->assertArrayNotHasKey('refresh_error', $data);
		$this->assertNotEmpty($data['error']);
	}

	public function testSearchIncludesRefreshErrorForAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$this->tokenStorage->method('getAccessToken')->willReturn(null);
		$this->tokenRefresher->method('getLastError')
			->willReturn('IdP rejected refresh_token (HTTP 401). Detail: invalid_grant');

		$response = $this->controller->search('hello world');
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertIsArray($data);
		$this->assertFalse($data['success']);
		$this->assertArrayHasKey('refresh_error', $data);
		$this->assertStringContainsString('invalid_grant', $data['refresh_error']);
	}

	public function testSearchOmitsRefreshErrorForAdminWhenLastErrorNull(): void {
		// Guards the `if ($refreshError !== null)` clause in
		// authRequiredBody(): even for an admin, the key must be absent
		// rather than serialized as `"refresh_error": null` when the
		// refresher has no captured error (e.g. the refresh path was
		// never entered because there is no stored token).
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$this->tokenStorage->method('getAccessToken')->willReturn(null);
		$this->tokenRefresher->method('getLastError')->willReturn(null);

		$response = $this->controller->search('hello world');
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertIsArray($data);
		$this->assertFalse($data['success']);
		$this->assertArrayNotHasKey('refresh_error', $data);
		$this->assertNotEmpty($data['error']);
	}
}
