<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use Mcp\Client\Client;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use OCA\Astrolabe\Controller\McpAppController;
use OCA\Astrolabe\Service\Assistant\AgentLoop;
use OCA\Astrolabe\Service\Mcp\McpClientFactory;
use OCA\Astrolabe\Service\McpApp\RedactionProposals;
use OCP\AppFramework\Http;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The MCP App host proxy: which tools the view may reach, and that redaction
 * strings and review controls come only from the server-side proposal store,
 * never from the (untrusted) view.
 */
final class McpAppControllerTest extends TestCase {
	private const ID = 'abcdefghijklmnopqrstuvwx';

	private Client&MockObject $client;
	private RedactionProposals $proposals;
	private McpAppController $controller;
	/** @var list<array{string, array<string, mixed>}> */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(static function (string $key) use (&$store): mixed {
			return $store[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$store): bool {
			$store[$key] = $value;
			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(self::ID);
		$this->proposals = new RedactionProposals($cacheFactory, $random);

		$this->client = $this->createMock(Client::class);
		$this->client->method('callTool')->willReturnCallback(function (string $name, array $args): CallToolResult {
			$this->calls[] = [$name, $args];
			return new CallToolResult([new TextContent('ok')]);
		});
		$clientFactory = $this->createMock(McpClientFactory::class);
		$clientFactory->method('connect')->willReturn($this->client);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new McpAppController(
			'astrolabe',
			$this->createMock(IRequest::class),
			$clientFactory,
			$this->createMock(AgentLoop::class),
			$userSession,
			$this->proposals,
		);
	}

	public function testRefusesToolsTheViewMayNotCall(): void {
		$response = $this->controller->callTool('nc_webdav_delete_resource', ['path' => '/']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->calls);
	}

	public function testSwapsTheProposalIdForTheStoredTextsAndReview(): void {
		$review = ['heading' => 'People', 'buttons' => [['label' => 'Only A', 'set' => 'only', 'groups' => ['Person A']]]];
		$id = $this->proposals->store('alice', 7, [['text' => 'Jane Doe', 'group' => 'Person A']], $review);

		$response = $this->controller->callTool('open_document', ['file_id' => 7, 'proposal' => $id]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['open_document', [
			'file_id' => 7,
			'redact_text' => [['text' => 'Jane Doe', 'group' => 'Person A']],
			'review' => $review,
		]]], $this->calls);
	}

	/** The view is untrusted: whatever it sends for these is dropped. */
	public function testDropsClientSuppliedRedactTextAndReview(): void {
		$this->controller->callTool('open_document', [
			'file_id' => 7,
			'redact_text' => ['injected'],
			'review' => ['heading' => 'injected'],
		]);

		$this->assertSame([['open_document', ['file_id' => 7]]], $this->calls);
	}

	public function testAnotherUsersOrFilesProposalOpensWithoutRegions(): void {
		$this->proposals->store('bob', 7, ['bob secret']);
		$this->controller->callTool('open_document', ['file_id' => 7, 'proposal' => self::ID]);

		$this->proposals->store('alice', 8, ['other file']);
		$this->controller->callTool('open_document', ['file_id' => 7, 'proposal' => self::ID]);

		$this->assertSame([
			['open_document', ['file_id' => 7]],
			['open_document', ['file_id' => 7]],
		], $this->calls);
	}

	public function testOtherToolsPassArgumentsThrough(): void {
		$this->controller->callTool('preview_redaction', ['file_id' => 7, 'redact_text' => ['x']]);

		$this->assertSame([['preview_redaction', ['file_id' => 7, 'redact_text' => ['x']]]], $this->calls);
	}

	public function testViewCspDefaultsToNothingExternal(): void {
		$csp = McpAppController::viewCsp([]);

		$this->assertStringContainsString("default-src 'none'", $csp);
		$this->assertStringContainsString("connect-src 'none'", $csp);
		$this->assertStringContainsString("frame-ancestors 'self'", $csp);
		$this->assertStringNotContainsString('https:', $csp);
	}

	public function testViewCspAllowsOnlyWellFormedHttpsOrigins(): void {
		$csp = McpAppController::viewCsp([
			'resourceDomains' => ['https://cdn.example.com', 'http://plain.example', 'https://x.example; script-src *', 42, 'https://ok.example:8443'],
			'connectDomains' => ['https://api.example.com'],
		]);

		$this->assertStringContainsString('script-src \'unsafe-inline\' https://cdn.example.com https://ok.example:8443', $csp);
		$this->assertStringContainsString('connect-src https://api.example.com', $csp);
		$this->assertStringNotContainsString('plain.example', $csp);
		$this->assertStringNotContainsString('script-src *', $csp);
	}
}
