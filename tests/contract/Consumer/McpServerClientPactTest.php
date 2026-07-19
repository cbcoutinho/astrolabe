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
