<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCA\Astrolabe\Settings\Admin;
use OCP\AppFramework\Http;
use OCP\Files\Node;
use OCP\IUser;

/**
 * Controller-level tests for the astrolabe-side access checks on the
 * content-fetch endpoints (chunk-context, pdf-preview) and the search
 * post-filter. Decisions are driven through the real DocumentAccessService wired
 * in the base fixture: SearchSources::isInstalled + IRootFolder::getById/get.
 */
final class ApiControllerAccessTest extends AbstractApiControllerTestCase {
	private function withUser(string $uid = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function filesInstalled(): void {
		$this->searchSources->method('isInstalled')->willReturn(true);
	}

	// --- chunkContext ---------------------------------------------------------

	public function testChunkContextAllowedCallsMcp(): void {
		$this->filesInstalled();
		$this->userFolder->method('getById')->with(42)->willReturn([$this->createMock(Node::class)]);
		$this->authenticateUserWithToken('alice', 'tok');

		$this->client->expects($this->once())
			->method('getChunkContext')
			->willReturn(['chunk_text' => 'hello']);

		$response = $this->controller->chunkContext('file', '42', 0, 10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('hello', $response->getData()['chunk_text'] ?? null);
	}

	public function testChunkContextDeniedReturns403AndSkipsMcpAndToken(): void {
		$this->filesInstalled();
		$this->userFolder->method('getById')->with(42)->willReturn([]); // unshared since indexing
		$this->withUser('alice');

		$this->tokenMinter->expects($this->never())->method('mintForUser');
		$this->client->expects($this->never())->method('getChunkContext');

		$response = $this->controller->chunkContext('file', '42', 0, 10);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('access_denied', $response->getData()['code'] ?? null);
	}

	public function testChunkContextDelegatesWhenSourceNotInstalled(): void {
		// deck not installed ⇒ DELEGATE ⇒ the controller proceeds to the MCP
		// backstop rather than denying locally.
		$this->searchSources->method('isInstalled')->willReturn(false);
		$this->authenticateUserWithToken('alice', 'tok');

		$this->client->expects($this->once())
			->method('getChunkContext')
			->willReturn(['chunk_text' => 'card body']);

		$response = $this->controller->chunkContext('deck_card', '5', 0, 10, null, null, 3);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testChunkContextUnauthenticatedReturns401(): void {
		// No session user configured ⇒ getUser() returns null.
		$this->client->expects($this->never())->method('getChunkContext');
		$response = $this->controller->chunkContext('file', '42', 0, 10);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	// PDF page rendering no longer has a controller action: pages are rasterized
	// in the browser from the copy already in Nextcloud, which enforces access
	// natively on the WebDAV read. There is nothing left to access-check here.

	// --- search post-filter ---------------------------------------------------

	public function testSearchDropsInaccessibleResultsAndAlignedCoords(): void {
		$this->filesInstalled();
		$this->appConfig->method('getValueBool')
			->with('astrolabe', Admin::SETTING_SHOW_VISUALIZATION, Admin::DEFAULT_SHOW_VISUALIZATION)
			->willReturn(true);
		$this->authenticateUserWithToken('alice', 'tok');

		// fileId 1 accessible, fileId 2 not.
		$this->userFolder->method('getById')->willReturnMap([
			[1, [$this->createMock(Node::class)]],
			[2, []],
		]);

		$this->client->method('search')->willReturn([
			'results' => [
				['doc_type' => 'file', 'id' => 1, 'metadata' => []],
				['doc_type' => 'file', 'id' => 2, 'metadata' => []],
			],
			'coordinates_3d' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]],
			'algorithm_used' => 'hybrid',
			'total_documents' => 2,
		]);

		$data = $this->controller->search('anything', 'hybrid', 10, '', 'true')->getData();

		$this->assertCount(1, $data['results']);
		$this->assertSame(1, $data['results'][0]['id']);
		// The dropped result's coordinate is removed too, keeping the plot aligned.
		$this->assertCount(1, $data['coordinates_3d']);
		$this->assertSame([0.1, 0.2, 0.3], $data['coordinates_3d'][0]);
		// total_documents is clamped to the post-filter count (was 2 from MCP).
		$this->assertSame(1, $data['total_documents']);
	}
}
