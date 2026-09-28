<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCP\AppFramework\Http;

/**
 * SAR case proxy (ApiController::sar*): gated on the MCP server's advertised
 * capability, forwarded as the current user to /api/v1/sar/cases, and the MCP
 * server's 4xx answers passed through so the UI can show them.
 */
final class ApiControllerSarTest extends AbstractApiControllerTestCase {
	private const CASE = ['case_id' => 101, 'case' => ['name' => 'SAR-1', 'state' => 'open']];

	private function available(): void {
		$this->authenticateUserWithToken('alice', 'alice-token');
		$this->searchCapabilities->method('isSarAvailable')->willReturn(true);
	}

	public function testIs404WhenServerDoesNotAdvertiseSar(): void {
		$this->authenticateUserWithToken();
		$this->searchCapabilities->method('isSarAvailable')->willReturn(false);
		$this->client->expects($this->never())->method('sarCases');

		$response = $this->controller->sarList();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testCreateForwardsAsCurrentUserAndReturns201(): void {
		$this->available();
		$this->client->expects($this->once())
			->method('sarCases')
			->with('POST', '', 'alice-token', [
				'folder' => '/Team',
				'name' => 'SAR-1',
				'subject' => ['Jane Doe'],
				'description' => 'ref 7',
			])
			->willReturn(self::CASE);

		$response = $this->controller->sarCreate('/Team', 'SAR-1', ['Jane Doe'], 'ref 7');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(self::CASE, $response->getData());
	}

	public function testGetPassesPaging(): void {
		$this->available();
		$this->client->expects($this->once())
			->method('sarCases')
			->with('GET', '/101', 'alice-token', null, ['offset' => 5, 'limit' => 50])
			->willReturn(self::CASE);

		$this->assertSame(Http::STATUS_OK, $this->controller->sarGet(101, 5, 50)->getStatus());
	}

	public function testUpdateSendsOnlyGivenFields(): void {
		$this->available();
		$this->client->expects($this->once())
			->method('sarCases')
			->with('PATCH', '/101', 'alice-token', ['state' => 'closed'])
			->willReturn(self::CASE);

		$this->controller->sarUpdate(101, state: 'closed');
	}

	public function testItemsForwardAddRemoveAndQueries(): void {
		$this->available();
		$add = [['doc_type' => 'note', 'doc_id' => '12', 'reason' => 'r']];
		$this->client->expects($this->once())
			->method('sarCases')
			->with('POST', '/101/items', 'alice-token', [
				'add' => $add,
				'remove' => [],
				'queries' => [['text' => 'jane', 'hits' => 2]],
			])
			->willReturn(self::CASE);

		$this->controller->sarItems(101, $add, [], [['text' => 'jane', 'hits' => 2]]);
	}

	public function testExportReturns202AndSendsFolderOnlyWhenGiven(): void {
		$this->available();
		$this->client->expects($this->exactly(2))
			->method('sarCases')
			->willReturnCallback(function (string $method, string $path, string $token, ?array $body): array {
				$this->assertSame(['POST', '/101/exports'], [$method, $path]);
				static $calls = 0;
				$this->assertSame($calls++ === 0 ? [] : ['output_folder' => '/Out'], $body);
				return self::CASE;
			});

		$this->assertSame(Http::STATUS_ACCEPTED, $this->controller->sarExport(101)->getStatus());
		$this->controller->sarExport(101, '/Out');
	}

	public function testServerStatusAndMessagePassThrough(): void {
		$this->available();
		$this->client->method('sarCases')->willReturn([
			'error' => 'the case is closed; this needs it to be open',
			'status' => 409,
		]);

		$response = $this->controller->sarItems(101, [['doc_type' => 'note', 'doc_id' => '1', 'reason' => 'r']]);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertStringContainsString('closed', $response->getData()['error']);
	}

	public function testCaseSearchSendsTheSameFiltersToTheCaseWithoutPca(): void {
		$this->available();
		$this->client->expects($this->once())
			->method('search')
			->with(
				'grievance',
				'hybrid',
				20,
				false,
				['file'],
				'alice-token',
				'2023-01-01T00:00:00Z',
				null,
				['/HR/Conduct'],
				101,
				40,
			)
			->willReturn(['results' => [], 'total_documents' => 0]);

		$response = $this->controller->search(
			query: 'grievance',
			limit: 20,
			doc_types: 'file',
			include_pca: 'true',
			modified_after: '2023-01-01T00:00:00Z',
			path_prefixes: '/HR/Conduct',
			offset: 40,
			sar_case: 101,
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayNotHasKey('coordinates_3d', $response->getData());
	}

	public function testCaseSearchOfAClosedCasePassesTheConflictThrough(): void {
		$this->available();
		$this->client->method('search')->willReturn([
			'error' => 'this case is closed',
			'status' => 409,
		]);

		$response = $this->controller->search(query: 'grievance', sar_case: 101);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertStringContainsString('closed', $response->getData()['error']);
	}

	public function testCaseSearchIs404WhenServerDoesNotAdvertiseSar(): void {
		$this->authenticateUserWithToken();
		$this->searchCapabilities->method('isSarAvailable')->willReturn(false);
		$this->client->expects($this->never())->method('search');

		$response = $this->controller->search(query: 'grievance', sar_case: 101);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	/**
	 * The MCP server requires sar.read to read cases and sar.write for the
	 * rest; Astrolabe asks for exactly that scope on each call's token.
	 */
	public function testSarCallsMintTokensWithTheScopeTheyNeed(): void {
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->searchCapabilities->method('isSarAvailable')->willReturn(true);
		$minted = [];
		$this->tokenMinter->method('mintForUser')
			->willReturnCallback(function (string $uid, string $scopes) use (&$minted): string {
				$minted[] = $scopes;
				return 'token';
			});
		$this->client->method('sarCases')->willReturn(self::CASE);
		$this->client->method('search')->willReturn(['results' => [], 'total_documents' => 0]);

		$this->controller->sarGet(101);
		$this->controller->sarList();
		$this->controller->sarUpdate(101, state: 'closed');
		$this->controller->sarItems(101, [['doc_type' => 'note', 'doc_id' => '1', 'reason' => 'r']]);
		$this->controller->sarExport(101);
		$this->controller->search(query: 'q', sar_case: 101);
		$this->controller->search(query: 'q');

		$this->assertSame(['sar.read', 'sar.read', 'sar.write', 'sar.write', 'sar.write', 'sar.write semantic.read', ''], $minted);
	}

	public function testTimeoutPassesThroughAs504(): void {
		// A SAR call that hit the HTTP timeout keeps its meaning rather than
		// turning into a generic 500.
		$this->available();
		$this->client->method('sarCases')->willReturn(['error' => 'timed out', 'status' => 504, 'timeout' => true]);

		$this->assertSame(Http::STATUS_GATEWAY_TIMEOUT, $this->controller->sarList()->getStatus());
	}

	public function testUnexpectedStatusBecomes500(): void {
		$this->available();
		$this->client->method('sarCases')->willReturn(['error' => 'teapot', 'status' => 418]);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $this->controller->sarList()->getStatus());
	}

	public function testWithoutUserIs401(): void {
		$this->searchCapabilities->method('isSarAvailable')->willReturn(true);
		$this->userSession->method('getUser')->willReturn(null);
		$this->client->expects($this->never())->method('sarCases');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->sarList()->getStatus());
	}
}
