<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Contract\Consumer;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use OCA\Astrolabe\Service\McpServerClient;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IRequest;
use PhpPact\Consumer\InteractionBuilder;
use PhpPact\Consumer\Matcher\Matcher;
use PhpPact\Consumer\Model\ConsumerRequest;
use PhpPact\Consumer\Model\ProviderResponse;
use PhpPact\Standalone\MockService\MockServerConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Consumer contract: astrolabe (McpServerClient) -> nextcloud-mcp-server /api/v1.
 *
 * This is the other half of the bidirectional contract in ADR-029. astrolabe's
 * McpServerClient consumes the MCP server's management API; this test pins the
 * request shape and response contract it depends on, producing a pact with
 * consumer=astrolabe, provider=nextcloud-mcp-server that the MCP server verifies.
 *
 * Scope: the public, stateless ``GET /api/v1/status`` call (twice — once for
 * the response shape, once for the ``X-Request-Id`` correlation header), plus the
 * bearer-authenticated ``POST /api/v1/vector-sync/purge`` consent-purge call
 * (with a provider state). Full provider verification of the authenticated
 * surface (search, webhooks, apps, chunk-context, purge) needs
 * provider-state + token handling stood up on the MCP side — the deferred
 * follow-up (see tests/contract/README.md); the published pact rides the
 * broker's pending flow until then.
 *
 * It is an INTEGRATION test: pact-php boots its bundled Rust mock server (needs
 * ext-ffi), so it runs in the contract suite, not ``composer test:unit``.
 */
final class McpServerClientPactTest extends TestCase {
	private function mockServerConfig(): MockServerConfig {
		$config = new MockServerConfig();
		$config
			->setConsumer('astrolabe')
			->setProvider('nextcloud-mcp-server')
			->setPactDir(__DIR__ . '/../pacts');
		if ($logLevel = getenv('PACT_LOGLEVEL')) {
			$config->setLogLevel($logLevel);
		}
		return $config;
	}

	/**
	 * Build McpServerClient with a real PSR-18 client pointed at the mock server.
	 */
	private function clientFor(MockServerConfig $config, ?IRequest $request = null): McpServerClient {
		$ncConfig = $this->createMock(IConfig::class);
		$ncConfig->method('getSystemValue')
			->willReturnCallback(function (string $key, $default) use ($config) {
				if ($key === 'mcp_server_url') {
					return (string)$config->getBaseUri();
				}
				return $default;
			});

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.0.0');

		$factory = new HttpFactory();
		return new McpServerClient(
			new GuzzleClient(['http_errors' => false]),
			$factory,
			$factory,
			$ncConfig,
			$this->createMock(LoggerInterface::class),
			$appManager,
			$request,
		);
	}

	/**
	 * As clientFor(), but also configures the shared webhook secret so
	 * McpServerClient::sendSyncEvent() authenticates to the ingress instead of
	 * short-circuiting (it refuses to POST an unauthenticated payload).
	 */
	private function clientForWithWebhookSecret(MockServerConfig $config, string $secret): McpServerClient {
		$ncConfig = $this->createMock(IConfig::class);
		$ncConfig->method('getSystemValue')
			->willReturnCallback(function (string $key, $default) use ($config, $secret) {
				if ($key === 'mcp_server_url') {
					return (string)$config->getBaseUri();
				}
				if ($key === 'mcp_webhook_secret') {
					return $secret;
				}
				return $default;
			});

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.0.0');

		$factory = new HttpFactory();
		return new McpServerClient(
			new GuzzleClient(['http_errors' => false]),
			$factory,
			$factory,
			$ncConfig,
			$this->createMock(LoggerInterface::class),
			$appManager,
		);
	}

	public function testGetStatusHonoursTheManagementContract(): void {
		$matcher = new Matcher();
		$config = $this->mockServerConfig();

		$request = (new ConsumerRequest())
			->setMethod('GET')
			->setPath('/api/v1/status');

		// The fields McpServerClient::getStatus() relies on; matched by type so the
		// contract pins the shape, not the MCP server's exact values.
		// ``supported_search_types`` is the query-type vocabulary the UI gates its
		// algorithm picker on and SearchCapabilities enforces.
		$response = (new ProviderResponse())
			->setStatus(200)
			->addHeader('Content-Type', 'application/json')
			->setBody([
				'version' => $matcher->like('0.1.0'),
				'auth_mode' => $matcher->like('basic'),
				'vector_sync_enabled' => $matcher->boolean(false),
				'uptime_seconds' => $matcher->integer(123),
				'management_api_version' => $matcher->like('1.0'),
				'supported_search_types' => $matcher->eachLike('semantic'),
				// Gates the SAR export UI (SearchCapabilities::isSarAvailable).
				'sar_available' => $matcher->boolean(false),
			]);

		$builder = new InteractionBuilder($config);
		$builder
			->uponReceiving('a request for MCP server status')
			->with($request)
			->willRespondWith($response);

		$status = $this->clientFor($config)->getStatus();

		// The mock server echoes the matcher example values, so these assertions
		// are intentionally coupled to the `$matcher->like(...)` examples above.
		$this->assertTrue($builder->verify(), 'Pact consumer verification failed');
		$this->assertArrayNotHasKey('error', $status);
		$this->assertSame('0.1.0', $status['version'] ?? null);
		$this->assertSame('basic', $status['auth_mode'] ?? null);
		$this->assertFalse($status['vector_sync_enabled'] ?? null);
		$this->assertSame(123, $status['uptime_seconds'] ?? null);
		$this->assertSame('1.0', $status['management_api_version'] ?? null);
		$this->assertSame(['semantic'], $status['supported_search_types'] ?? null);
		$this->assertFalse($status['sar_available'] ?? null);
	}

	/**
	 * SAR case create: the case astrolabe opens for the current user.
	 */
	public function testCreateSarCaseHonoursTheContract(): void {
		$matcher = new Matcher();
		$config = $this->mockServerConfig();

		$request = (new ConsumerRequest())
			->setMethod('POST')
			->setPath('/api/v1/sar/cases')
			->addHeader('Authorization', $matcher->regex('Bearer mint-token', 'Bearer .+'))
			->addHeader('Content-Type', 'application/json')
			->setBody([
				'folder' => $matcher->like('/Team'),
				'name' => $matcher->like('SAR-1'),
				'subject' => $matcher->eachLike('Jane Doe'),
				'description' => $matcher->like('ref 7'),
			]);

		$response = (new ProviderResponse())
			->setStatus(201)
			->addHeader('Content-Type', 'application/json')
			->setBody(self::sarCaseBody($matcher, 'open'));

		$builder = new InteractionBuilder($config);
		$builder
			->given('a user with background access can create a SAR case')
			->uponReceiving('a request to create a SAR case')
			->with($request)
			->willRespondWith($response);

		$result = $this->clientFor($config)->sarCases('POST', '', 'mint-token', [
			'folder' => '/Team',
			'name' => 'SAR-1',
			'subject' => ['Jane Doe'],
			'description' => 'ref 7',
		]);

		$this->assertTrue($builder->verify(), 'Pact consumer verification failed');
		$this->assertSame(101, $result['case_id'] ?? null);
	}

	/**
	 * SAR case items: adding a search result to the case, with its reason and
	 * the query that found it, and logging the query.
	 */
	public function testChangeSarCaseItemsHonoursTheContract(): void {
		$matcher = new Matcher();
		$config = $this->mockServerConfig();

		$request = (new ConsumerRequest())
			->setMethod('POST')
			->setPath('/api/v1/sar/cases/101/items')
			->addHeader('Authorization', $matcher->regex('Bearer mint-token', 'Bearer .+'))
			->addHeader('Content-Type', 'application/json')
			->setBody([
				'add' => $matcher->eachLike([
					'doc_type' => $matcher->like('file'),
					'doc_id' => $matcher->like('12'),
					'reason' => $matcher->like('mentions the subject'),
					'title' => $matcher->like('Letter'),
					'found_by' => $matcher->like('jane doe'),
				]),
				'remove' => [],
				'queries' => $matcher->eachLike([
					'text' => $matcher->like('jane doe'),
					'hits' => $matcher->integer(3),
				]),
			]);

		$response = (new ProviderResponse())
			->setStatus(200)
			->addHeader('Content-Type', 'application/json')
			->setBody(self::sarCaseBody($matcher, 'open'));

		$builder = new InteractionBuilder($config);
		$builder
			->given('an open SAR case 101 exists')
			->uponReceiving('a request to add documents to a SAR case')
			->with($request)
			->willRespondWith($response);

		$result = $this->clientFor($config)->sarCases('POST', '/101/items', 'mint-token', [
			'add' => [[
				'doc_type' => 'file',
				'doc_id' => '12',
				'reason' => 'mentions the subject',
				'title' => 'Letter',
				'found_by' => 'jane doe',
			]],
			'remove' => [],
			'queries' => [['text' => 'jane doe', 'hits' => 3]],
		]);

		$this->assertTrue($builder->verify(), 'Pact consumer verification failed');
		$this->assertSame('open', $result['case']['state'] ?? null);
	}

	/**
	 * SAR case search: the search page's own filters, sent to the case, which
	 * returns one row per document with the navigation metadata the result
	 * list reads, and logs the query.
	 */
	public function testSarCaseSearchHonoursTheContract(): void {
		$matcher = new Matcher();
		$config = $this->mockServerConfig();

		$request = (new ConsumerRequest())
			->setMethod('POST')
			->setPath('/api/v1/sar/cases/101/search')
			->addHeader('Authorization', $matcher->regex('Bearer mint-token', 'Bearer .+'))
			->addHeader('Content-Type', 'application/json')
			->setBody([
				'query' => 'grievance',
				'algorithm' => 'hybrid',
				'limit' => 20,
				'include_pca' => false,
				'doc_types' => ['file'],
				'modified_after' => '2023-01-01T00:00:00Z',
				'path_prefixes' => ['/HR/Conduct'],
				'offset' => 0,
				'granularity' => 'document',
			]);

		$response = (new ProviderResponse())
			->setStatus(200)
			->addHeader('Content-Type', 'application/json')
			->setBody([
				'results' => $matcher->eachLike([
					'id' => $matcher->integer(12),
					'doc_type' => $matcher->like('file'),
					'title' => $matcher->like('Letter.pdf'),
					'relevance' => $matcher->number(0.8),
					'relevance_source' => $matcher->like('fusion_ordinal'),
					'metadata' => $matcher->like(['path' => '/HR/Conduct/Letter.pdf']),
				]),
				'total_found' => $matcher->integer(1),
				'algorithm_used' => $matcher->like('hybrid'),
				'granularity' => 'document',
			]);

		$builder = new InteractionBuilder($config);
		$builder
			->given('an open SAR case 101 exists')
			->uponReceiving('a filtered search for a SAR case')
			->with($request)
			->willRespondWith($response);

		$result = $this->clientFor($config)->search(
			'grievance',
			'hybrid',
			20,
			false,
			['file'],
			'mint-token',
			'2023-01-01T00:00:00Z',
			null,
			['/HR/Conduct'],
			101,
			0,
		);

		$this->assertTrue($builder->verify(), 'Pact consumer verification failed');
		$this->assertSame(1, $result['total_documents'] ?? null);
		$this->assertSame('/HR/Conduct/Letter.pdf', $result['results'][0]['metadata']['path'] ?? null);
	}

	/**
	 * SAR case list: what the SAR page lists (name, state, item count).
	 */
	public function testListSarCasesHonoursTheContract(): void {
		$matcher = new Matcher();
		$config = $this->mockServerConfig();

		$request = (new ConsumerRequest())
			->setMethod('GET')
			->setPath('/api/v1/sar/cases')
			->addHeader('Authorization', $matcher->regex('Bearer mint-token', 'Bearer .+'));

		$response = (new ProviderResponse())
			->setStatus(200)
			->addHeader('Content-Type', 'application/json')
			->setBody([
				'cases' => $matcher->eachLike([
					'case_id' => $matcher->integer(101),
					'path' => $matcher->like('/Team/SAR-1/sar-case.json'),
					'name' => $matcher->like('SAR-1'),
					'state' => $matcher->regex('open', 'open|exporting|ready_for_audit|closed'),
					'items' => $matcher->integer(1),
					'updated_at' => $matcher->like('2026-09-25T08:00:00+00:00'),
				]),
			]);

		$builder = new InteractionBuilder($config);
		$builder
			->given('an open SAR case 101 exists')
			->uponReceiving('a request to list SAR cases')
			->with($request)
			->willRespondWith($response);

		$result = $this->clientFor($config)->sarCases('GET', '', 'mint-token');

		$this->assertTrue($builder->verify(), 'Pact consumer verification failed');
		$this->assertSame('SAR-1', $result['cases'][0]['name'] ?? null);
	}

	/**
	 * SAR case get: what the case page and the export progress read.
	 */
	public function testGetSarCaseHonoursTheContract(): void {
		$matcher = new Matcher();
		$config = $this->mockServerConfig();

		$request = (new ConsumerRequest())
			->setMethod('GET')
			->setPath('/api/v1/sar/cases/101')
			->setQuery(['offset' => '0', 'limit' => '200'])
			->addHeader('Authorization', $matcher->regex('Bearer mint-token', 'Bearer .+'));

		$body = self::sarCaseBody($matcher, 'ready_for_audit');
		$body['latest_export'] = [
			'state' => $matcher->regex('done', 'running|done|failed'),
			'archive_path' => $matcher->like('/Team/SAR-1/exports/SAR-1-v1.zip'),
			'total' => $matcher->integer(1),
			'processed' => $matcher->integer(1),
			'failed' => $matcher->integer(0),
		];
		$response = (new ProviderResponse())
			->setStatus(200)
			->addHeader('Content-Type', 'application/json')
			->setBody($body);

		$builder = new InteractionBuilder($config);
		$builder
			->given('an exported SAR case 101 exists')
			->uponReceiving('a request for a SAR case')
			->with($request)
			->willRespondWith($response);

		$result = $this->clientFor($config)->sarCases('GET', '/101', 'mint-token', null, ['offset' => 0, 'limit' => 200]);

		$this->assertTrue($builder->verify(), 'Pact consumer verification failed');
		$this->assertSame('ready_for_audit', $result['case']['state'] ?? null);
		$this->assertSame('done', $result['latest_export']['state'] ?? null);
	}

	/**
	 * SAR case export: starting the redacted archive (202; the case locks).
	 */
	public function testExportSarCaseHonoursTheContract(): void {
		$matcher = new Matcher();
		$config = $this->mockServerConfig();

		$request = (new ConsumerRequest())
			->setMethod('POST')
			->setPath('/api/v1/sar/cases/101/exports')
			->addHeader('Authorization', $matcher->regex('Bearer mint-token', 'Bearer .+'))
			->addHeader('Content-Type', 'application/json')
			->setBody(new \stdClass());

		$response = (new ProviderResponse())
			->setStatus(202)
			->addHeader('Content-Type', 'application/json')
			->setBody(self::sarCaseBody($matcher, 'exporting'));

		$builder = new InteractionBuilder($config);
		$builder
			->given('an open SAR case 101 exists')
			->uponReceiving('a request to export a SAR case')
			->with($request)
			->willRespondWith($response);

		$result = $this->clientFor($config)->sarCases('POST', '/101/exports', 'mint-token', []);

		$this->assertTrue($builder->verify(), 'Pact consumer verification failed');
		$this->assertSame('exporting', $result['case']['state'] ?? null);
	}

	/**
	 * The case fields the SAR views read.
	 *
	 * @return array<string, mixed>
	 */
	private static function sarCaseBody(Matcher $matcher, string $state): array {
		return [
			'case_id' => $matcher->integer(101),
			'path' => $matcher->like('/Team/SAR-1/sar-case.json'),
			'items_total' => $matcher->integer(1),
			'case' => [
				'name' => $matcher->like('SAR-1'),
				'description' => $matcher->like('ref 7'),
				'state' => $matcher->regex($state, 'open|exporting|ready_for_audit|closed'),
				'subject' => $matcher->eachLike('Jane Doe'),
				'items' => $matcher->eachLike([
					'doc_type' => $matcher->like('file'),
					'doc_id' => $matcher->like('12'),
					'reason' => $matcher->like('mentions the subject'),
					'title' => $matcher->like('Letter'),
				]),
				'updated_at' => $matcher->like('2026-09-25T08:00:00+00:00'),
			],
		];
	}

	/**
	 * Correlation contract: every request carries Nextcloud's reqId.
	 *
	 * astrolabe exports no spans of its own — there is no OTLP collector within
	 * reach of the managed storage it runs on — so end-to-end tracing depends on
	 * the MCP server recording an identifier astrolabe forwards. ``X-Request-Id``
	 * is Nextcloud's reqId, the value prefixing every line the same request
	 * writes to ``nextcloud.log``, which makes it the join key between a
	 * user-visible failure and the backend trace.
	 *
	 * Pinned as a contract because the value is only useful if the provider
	 * actually reads it: astrolabe sending the header and the MCP server
	 * attaching it to spans are two halves of one agreement, and this is the
	 * consumer half.
	 */
	public function testForwardsNextcloudRequestIdForTraceCorrelation(): void {
		$matcher = new Matcher();
		$config = $this->mockServerConfig();

		$request = (new ConsumerRequest())
			->setMethod('GET')
			->setPath('/api/v1/status')
			->addHeader('X-Request-Id', $matcher->like('nc-req-id-123'));

		$response = (new ProviderResponse())
			->setStatus(200)
			->addHeader('Content-Type', 'application/json')
			->setBody([
				'version' => $matcher->like('0.1.0'),
				'auth_mode' => $matcher->like('basic'),
				'vector_sync_enabled' => $matcher->boolean(false),
				'uptime_seconds' => $matcher->integer(123),
				'management_api_version' => $matcher->like('1.0'),
				'supported_search_types' => $matcher->eachLike('semantic'),
			]);

		$builder = new InteractionBuilder($config);
		$builder
			->uponReceiving('a request carrying the Nextcloud request id')
			->with($request)
			->willRespondWith($response);

		$ncRequest = $this->createMock(IRequest::class);
		$ncRequest->method('getId')->willReturn('nc-req-id-123');
		$ncRequest->method('getHeader')->willReturn('');

		$status = $this->clientFor($config, $ncRequest)->getStatus();

		$this->assertTrue($builder->verify(), 'Pact consumer verification failed');
		$this->assertArrayNotHasKey('error', $status);
	}

	/**
	 * Strict gating contract: when vector sync is disabled the server advertises
	 * ``supported_search_types: []`` and rejects any explicit search algorithm
	 * with HTTP 422 ``unsupported_search_type`` rather than silently returning
	 * nothing. (Keyword vs hybrid is now a per-document indexing choice on the
	 * server — the ``keyword-index`` tag — not a keyword-only server mode; when
	 * vector sync is on all three algorithms are offered.)
	 *
	 * astrolabe gates the request client-side from ``/api/v1/status`` (see
	 * SearchCapabilities) and hides the options in its UI, but this pins the
	 * server-side backstop the client relies on. The provider state names the
	 * precondition the MCP server sets up; it matches the handler registered on
	 * the provider side.
	 */
	public function testSearchRejectsUnsupportedAlgorithmWhenVectorSyncDisabled(): void {
		$matcher = new Matcher();
		$config = $this->mockServerConfig();

		$request = (new ConsumerRequest())
			->setMethod('POST')
			->setPath('/api/v1/vector-viz/search')
			->addHeader('Content-Type', 'application/json')
			->setBody([
				'query' => $matcher->like('leadership award'),
				// The field under test — pinned exactly; the rest are incidental.
				'algorithm' => 'semantic',
				'limit' => $matcher->like(10),
				'include_pca' => $matcher->boolean(true),
			]);

		$response = (new ProviderResponse())
			->setStatus(422)
			->addHeader('Content-Type', 'application/json')
			->setBody([
				'error' => 'unsupported_search_type',
				'requested' => 'semantic',
				// Vector sync off → nothing is supported.
				'supported_search_types' => [],
			]);

		$builder = new InteractionBuilder($config);
		$builder
			->given('the server has vector sync disabled')
			->uponReceiving('a semantic search request when vector sync is disabled')
			->with($request)
			->willRespondWith($response);

		$result = $this->clientFor($config)->search('leadership award', 'semantic');

		// The client maps the non-2xx status to a structured error array; the
		// contract's job is to pin the request shape + the 422 response body.
		$this->assertTrue($builder->verify(), 'Pact consumer verification failed');
		$this->assertArrayHasKey('error', $result);
	}

	/**
	 * Consent-purge contract: when an admin disables a source for semantic
	 * search, McpServerClient::purgeDocTypes() asks the MCP server to delete the
	 * already-indexed content for that source's doc type(s). This pins the
	 * request shape (bearer-authenticated POST with a doc_types array) and the
	 * ``purged`` map the client reads back.
	 *
	 * The provider state names the precondition the MCP server sets up before
	 * replaying this interaction (an admin caller authorised to purge). Provider
	 * verification of this authenticated endpoint is the deferred follow-up
	 * (ADR-029); the published pact rides the broker's pending flow until then.
	 */
	public function testPurgeDocTypesHonoursTheContract(): void {
		$matcher = new Matcher();
		$config = $this->mockServerConfig();

		$request = (new ConsumerRequest())
			->setMethod('POST')
			->setPath('/api/v1/vector-sync/purge')
			->addHeader('Authorization', $matcher->regex('Bearer mint-token', 'Bearer .+'))
			->addHeader('Content-Type', 'application/json')
			->setBody([
				// Any non-empty array of doc-type strings; the gate sends the
				// disabled source's catalog doc types (e.g. files -> "file").
				'doc_types' => $matcher->eachLike('file'),
			]);

		// 200 with a per-doc-type deleted count. ``failed`` is omitted on full
		// success (the MCP server only includes it on partial failure), so the
		// contract pins only the ``purged`` map the client returns to the admin.
		$response = (new ProviderResponse())
			->setStatus(200)
			->addHeader('Content-Type', 'application/json')
			->setBody([
				'purged' => [
					'file' => $matcher->integer(4),
				],
			]);

		$builder = new InteractionBuilder($config);
		$builder
			->given('an admin can purge indexed documents')
			->uponReceiving('a request to purge a disabled source\'s doc types')
			->with($request)
			->willRespondWith($response);

		$result = $this->clientFor($config)->purgeDocTypes(['file'], 'mint-token');

		$this->assertTrue($builder->verify(), 'Pact consumer verification failed');
		$this->assertArrayNotHasKey('error', $result);
		$this->assertSame(4, $result['purged']['file'] ?? null);
	}

	/**
	 * Native-sync delivery contract: astrolabe's own listeners POST the Nextcloud
	 * webhook envelope to the MCP server's ingress ``POST /webhooks/nextcloud``
	 * (guarded by the shared WEBHOOK_SECRET), replacing the previous
	 * "register webhooks via the MCP server" indirection. This pins the request
	 * shape the MCP server's webhook_parser.py reads — ``event`` (with ``class``
	 * and the serialized node), ``user.uid``, ``time`` — plus the bearer-secret
	 * header and the ``{status}`` acknowledgement the delivery job checks.
	 *
	 * Provider verification of this authenticated ingress is the deferred
	 * follow-up (ADR-029); the published pact rides the broker's pending flow.
	 */
	public function testSendSyncEventHonoursTheIngressContract(): void {
		$matcher = new Matcher();
		$config = $this->mockServerConfig();

		$request = (new ConsumerRequest())
			->setMethod('POST')
			->setPath('/webhooks/nextcloud')
			->addHeader('Authorization', $matcher->regex('Bearer test-secret', 'Bearer .+'))
			->addHeader('Content-Type', 'application/json')
			->setBody([
				'event' => [
					'node' => [
						'id' => $matcher->integer(42),
						'path' => $matcher->like('/admin/files/Notes/todo.md'),
					],
					'class' => $matcher->like('OCP\\Files\\Events\\Node\\NodeWrittenEvent'),
				],
				'user' => [
					'uid' => $matcher->like('admin'),
					'displayName' => $matcher->like('admin'),
				],
				'time' => $matcher->integer(1720000000),
			]);

		// The ingress acknowledges receipt fast (queued/ignored); the delivery job
		// only checks for a non-error result, so the contract pins ``status``.
		$response = (new ProviderResponse())
			->setStatus(200)
			->addHeader('Content-Type', 'application/json')
			->setBody([
				'status' => $matcher->like('queued'),
			]);

		$builder = new InteractionBuilder($config);
		$builder
			->uponReceiving('a native sync event for an indexed note')
			->with($request)
			->willRespondWith($response);

		$envelope = [
			'event' => [
				'node' => ['id' => 42, 'path' => '/admin/files/Notes/todo.md'],
				'class' => 'OCP\\Files\\Events\\Node\\NodeWrittenEvent',
			],
			'user' => ['uid' => 'admin', 'displayName' => 'admin'],
			'time' => 1720000000,
		];
		$result = $this->clientForWithWebhookSecret($config, 'test-secret')->sendSyncEvent($envelope);

		$this->assertTrue($builder->verify(), 'Pact consumer verification failed');
		$this->assertArrayNotHasKey('error', $result);
		$this->assertSame('queued', $result['status'] ?? null);
	}
}
