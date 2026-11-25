<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use Eris\Generators;
use Eris\TestTrait;
use Mcp\Client\Client;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ReadResourceResult;
use OCA\Astrolabe\Controller\McpAppController;
use OCA\Astrolabe\Service\Assistant\AgentException;
use OCA\Astrolabe\Service\Assistant\AgentLoop;
use OCA\Astrolabe\Service\Mcp\McpClientFactory;
use OCA\Astrolabe\Service\McpApp\RedactionProposals;
use OCA\Astrolabe\Tests\Unit\PropertyGenerators;
use OCP\AppFramework\Http;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The MCP App host proxy: which tools the view may reach, and that redaction
 * strings and review controls come only from the server-side proposal store,
 * never from the (untrusted) view.
 */
final class McpAppControllerTest extends TestCase {
	use TestTrait;

	private const ID = 'abcdefghijklmnopqrstuvwx';

	private Client&MockObject $client;
	private RedactionProposals $proposals;
	private McpAppController $controller;
	private AgentLoop&MockObject $agentLoop;
	private LoggerInterface&MockObject $logger;
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
		$this->proposals = new RedactionProposals($cacheFactory, $random, $this->createMock(\Psr\Log\LoggerInterface::class));

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

		$this->agentLoop = $this->createMock(AgentLoop::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->controller = new McpAppController(
			'astrolabe',
			$this->createMock(IRequest::class),
			$clientFactory,
			$this->agentLoop,
			$userSession,
			$this->proposals,
			$this->logger,
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
		$this->assertStringContainsString("base-uri 'none'", $csp);
		$this->assertStringContainsString("form-action 'none'", $csp);
		$this->assertStringContainsString("script-src 'unsafe-inline';", $csp);
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

	/** "1e3" is numeric and would cast to file 1000; only digits name a file. */
	public function testFileIdMustBeAnIntOrDigits(): void {
		$this->assertSame(7, McpAppController::fileId(7));
		$this->assertSame(7, McpAppController::fileId('7'));
		foreach ([-7, 0, '0', '1e3', '7.0', ' 7', '-7', '', '0x7', null, 7.0, '99999999999999999999'] as $bad) {
			$this->assertNull(McpAppController::fileId($bad), var_export($bad, true));
		}
	}

	public function testANonDigitFileIdIsRefusedBeforeAnyCall(): void {
		$id = $this->proposals->store('alice', 1000, ['Jane Doe']);
		$response = $this->controller->callTool('open_document', ['file_id' => '1e3', 'proposal' => $id]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame([], $this->calls);
	}

	public function testSaveRedactionNeedsAValidFileId(): void {
		foreach ([['file_id' => '7; drop'], ['file_id' => 7.5], []] as $arguments) {
			$response = $this->controller->callTool('save_redaction', $arguments + ['regions' => [], 'etag' => 'e1']);
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), var_export($arguments, true));
		}
		$this->assertSame([], $this->calls);
	}

	public function testADigitStringFileIdIsPassedOnAsAnInt(): void {
		$this->controller->callTool('save_redaction', ['file_id' => '7', 'etag' => 'e1']);

		$this->assertSame([['save_redaction', ['file_id' => 7, 'etag' => 'e1']]], $this->calls);
	}

	public function testAnUpstreamFailureIsLoggedAndNotRelayed(): void {
		$client = $this->createMock(Client::class);
		$client->method('callTool')->willThrowException(new \RuntimeException('POST http://mcp.internal:8000/mcp: 401 invalid_token'));
		$this->logger->expects($this->once())->method('warning');

		$response = $this->controllerWith($client)->callTool('preview_redaction', ['file_id' => 7]);

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertStringNotContainsString('internal', json_encode($response->getData(), JSON_THROW_ON_ERROR));
	}

	public function testViewRefusesANonUiUri(): void {
		$response = $this->controller->view('https://evil.example/x.html');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testViewRefusesAResourceWithoutHtml(): void {
		$client = $this->createMock(Client::class);
		$client->method('readResource')->willReturn(new ReadResourceResult([new BlobResourceContents('ui://x', 'text/html', 'AAAA')]));

		$response = $this->controllerWith($client)->view('ui://x');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
	}

	public function testViewHidesTheUpstreamError(): void {
		$client = $this->createMock(Client::class);
		$client->method('readResource')->willThrowException(new \RuntimeException('token for alice at http://mcp.internal'));
		$this->logger->expects($this->once())->method('warning');

		$response = $this->controllerWith($client)->view('ui://x');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertStringNotContainsString('internal', json_encode($response->getData(), JSON_THROW_ON_ERROR));
	}

	public function testModelContextIsStoredForTheCurrentUserOnly(): void {
		$this->controller->modelContext(7, 'Jane Doe: on');

		$this->assertSame(['file_id' => 7, 'text' => 'Jane Doe: on'], $this->proposals->viewerContext('alice'));
		$this->assertNull($this->proposals->viewerContext('bob'));
	}

	public function testModelContextRefusesANonPositiveFileId(): void {
		foreach ([0, -7] as $bad) {
			$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->modelContext($bad, 'x')->getStatus());
		}
		$this->assertNull($this->proposals->viewerContext('alice'));
	}

	/** AgentException messages are written for users, so they pass through. */
	public function testMessageReportsAFailedTurn(): void {
		$this->agentLoop->method('run')->willThrowException(new AgentException('No tools are available to the assistant.'));

		$response = $this->controller->message('hello');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame(['error' => 'No tools are available to the assistant.'], $response->getData());
	}

	public function testMessageIsCappedAndMarkedAsFromTheViewer(): void {
		$this->agentLoop->expects($this->once())->method('run')
			->with('alice', $this->callback(static fn (string $p): bool => mb_strlen($p) === 4000), [], '', true)
			->willReturn(['output' => 'ok', 'sources' => [], 'turns' => []]);

		$this->controller->message(str_repeat('x', 10000));
	}

	public function testMessageHidesAnUnexpectedFailure(): void {
		$this->agentLoop->method('run')->willThrowException(new \RuntimeException('http://internal:8000 token expired'));

		$response = $this->controller->message('hello');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertStringNotContainsString('internal', json_encode($response->getData()) ?: '');
	}

	/** Found by the property below: PCRE's `$` also matches before a final newline. */
	public function testViewCspRefusesAnOriginWithATrailingNewline(): void {
		$csp = McpAppController::viewCsp(['resourceDomains' => ["https://cdn.example\n"]]);

		$this->assertStringNotContainsString("\n", $csp);
		$this->assertStringNotContainsString('cdn.example', $csp);
	}

	public function testViewCspKeepsAtMostTenOriginsPerDirective(): void {
		$domains = array_map(static fn (int $i): string => "https://cdn$i.example", range(1, 25));
		$csp = McpAppController::viewCsp(['resourceDomains' => $domains]);

		$this->assertStringContainsString('https://cdn10.example', $csp);
		$this->assertStringNotContainsString('https://cdn11.example', $csp);
	}

	/**
	 * Whatever the server declares, the header keeps its eight directives and
	 * only well-formed https origins: no ';', newline or quote gets in from input.
	 */
	public function testViewCspNeverCarriesSeparatorsOrQuotesFromInput(): void {
		$alphabet = ['a', 'z', '0', '9', '.', '-', ':', ';', "\n", "\r", "'", '"', ' ', '/', '*'];
		$domain = Generators::oneOf(
			PropertyGenerators::text($alphabet),
			Generators::map(static fn (string $s): string => 'https://' . $s, PropertyGenerators::text($alphabet)),
			Generators::map(static fn (string $s): string => 'https://cdn.example' . $s, PropertyGenerators::text($alphabet)),
		);
		$baseQuotes = substr_count(McpAppController::viewCsp([]), "'");
		$this->forAll(Generators::seq($domain), Generators::seq($domain))->withMaxSize(15)
			->then(function (array $res, array $connect) use ($baseQuotes): void {
				$csp = McpAppController::viewCsp(['resourceDomains' => $res, 'connectDomains' => $connect]);
				$this->assertCount(10, explode('; ', $csp));
				$this->assertDoesNotMatchRegularExpression('/[\r\n"]/', $csp);
				$this->assertLessThanOrEqual($baseQuotes, substr_count($csp, "'"));
				foreach (preg_split('/[; ]+/', $csp) ?: [] as $token) {
					if (str_starts_with($token, 'https://')) {
						$this->assertMatchesRegularExpression('#^https://[a-z0-9.-]+(:\d+)?$#i', $token);
					}
				}
			});
	}

	private function controllerWith(Client $client): McpAppController {
		$clientFactory = $this->createMock(McpClientFactory::class);
		$clientFactory->method('connect')->willReturn($client);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		return new McpAppController(
			'astrolabe',
			$this->createMock(IRequest::class),
			$clientFactory,
			$this->agentLoop,
			$userSession,
			$this->proposals,
			$this->logger,
		);
	}
}
