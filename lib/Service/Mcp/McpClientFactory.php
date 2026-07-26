<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Mcp;

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Service\McpTokenMinter;
use OCA\Astrolabe\Service\McpTokenMintException;
use OCP\App\IAppManager;
use OCP\IConfig;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a per-user MCP client against `nextcloud-mcp-server`'s `/mcp` endpoint.
 *
 * Astrolabe talks to that server over two channels, and this is the second one.
 * The management API ({@see \OCA\Astrolabe\Service\McpServerClient}) carries the
 * bbox/page data the chunk viewer needs; `/mcp` carries the tool catalogue —
 * roughly 150 tools spanning notes, files, calendar, deck and the rest — which is
 * what lets a model act on a user's Nextcloud rather than just read search hits.
 *
 * **Per-user tokens are the whole point.** The server scopes every result by the
 * token's `sub` and filters `tools/list` by its scopes, so a single shared
 * credential would leak one user's data to another. That is precisely why this
 * cannot be delegated to Nextcloud's Context Agent, whose MCP configuration
 * carries one static credential per instance.
 *
 * Tokens come from {@see McpTokenMinter}, which needs no session and therefore
 * works inside a background job — which is where a TaskProcessing provider runs.
 *
 * @psalm-suppress ClassMustBeFinal — kept non-final so it can be mocked in the
 *   unit tests, mirroring the other Service classes.
 */
class McpClientFactory {
	/**
	 * Long enough for a tool that talks to Nextcloud and back, short enough that a
	 * wedged call cannot hold a worker for the whole agent budget.
	 */
	private const REQUEST_TIMEOUT = 60;

	private const INIT_TIMEOUT = 20;

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private McpTokenMinter $tokenMinter,
		private IConfig $config,
		private IAppManager $appManager,
		private ClientInterface $httpClient,
		private RequestFactoryInterface $requestFactory,
		private StreamFactoryInterface $streamFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Make the MCP SDK loadable without handing Nextcloud our whole vendor tree.
	 *
	 * Requiring `vendor/autoload.php` wholesale is actively dangerous here: this
	 * app's vendor directory also contains doctrine, psr, symfony and guzzle, all
	 * of which the server ships itself. Registering that autoloader let a *dev*
	 * copy of doctrine/dbal shadow the server's, and `QueryBuilder::select()`
	 * started throwing a TypeError for the entire instance — Nextcloud returned
	 * 500s on unrelated endpoints until the autoloader was removed.
	 *
	 * So only the SDK's own namespaces are mapped, and only the ones the client
	 * path needs. Anything the server already provides (PSR interfaces above all)
	 * is deliberately left to the server's autoloader.
	 */
	private static function bootstrapSdk(): void {
		static $registered = false;
		if ($registered) {
			return;
		}
		$registered = true;

		$vendor = dirname(__DIR__, 3) . '/vendor';
		$prefixes = [
			'Mcp\\' => $vendor . '/mcp/sdk/src',
			'Symfony\\Component\\Uid\\' => $vendor . '/symfony/uid',
			'Opis\\JsonSchema\\' => $vendor . '/opis/json-schema/src',
			'Http\\Discovery\\' => $vendor . '/php-http/discovery/src',
		];

		spl_autoload_register(static function (string $class) use ($prefixes): void {
			foreach ($prefixes as $prefix => $dir) {
				if (!str_starts_with($class, $prefix)) {
					continue;
				}
				$relative = str_replace('\\', '/', substr($class, strlen($prefix)));
				$file = $dir . '/' . $relative . '.php';
				if (is_file($file)) {
					require_once $file;
				}
				return;
			}
		});
	}

	/**
	 * Connect as the given user. The caller owns the client and must disconnect.
	 *
	 * @param string $scopes Space-separated OAuth scopes beyond the defaults. The
	 *                       server filters the tool catalogue by these, so asking for less genuinely
	 *                       narrows what the model can do.
	 *
	 * @throws McpUnavailableException
	 */
	public function connect(string $userId, string $scopes = ''): Client {
		self::bootstrapSdk();

		try {
			$token = $this->tokenMinter->mintForUser($userId, $scopes);
		} catch (McpTokenMintException $e) {
			$this->logger->warning('Astrolabe could not mint an MCP access token: ' . $e->getMessage(), [
				'exception' => $e,
				'app' => Application::APP_ID,
			]);
			throw new McpUnavailableException(
				'Astrolabe could not obtain access to the MCP server on your behalf. '
				. 'An administrator needs to check the OpenID Connect configuration.',
				$e,
			);
		}

		$client = Client::builder()
			->setClientInfo(
				'Astrolabe',
				$this->appManager->getAppVersion(Application::APP_ID),
				'Nextcloud Astrolabe',
			)
			->setInitTimeout(self::INIT_TIMEOUT)
			->setRequestTimeout(self::REQUEST_TIMEOUT)
			->setLogger($this->logger)
			->build();

		// The PSR-18 client here is Nextcloud's own (see Application::register),
		// so MCP traffic inherits its proxy, CA and SSRF handling rather than
		// opening sockets of its own.
		$transport = new HttpTransport(
			$this->endpoint(),
			['Authorization' => 'Bearer ' . $token],
			$this->httpClient,
			$this->requestFactory,
			$this->streamFactory,
		);

		try {
			$client->connect($transport);
		} catch (\Throwable $e) {
			// Two audiences, two messages. The diagnosis names system config keys,
			// which is exactly what the admin who broke the connection needs and
			// exactly what an ordinary user — who is the one actually sitting in
			// the chat when this fires — can do nothing with. The detail goes to
			// the log; the chat gets a sentence that says who can fix it.
			$this->logger->warning('Astrolabe could not connect to the MCP server. ' . $this->explainFailure($e), [
				'exception' => $e,
				'app' => Application::APP_ID,
			]);
			throw new McpUnavailableException(
				'Astrolabe could not reach the MCP server, so it cannot search your content. '
				. 'An administrator needs to check the connection settings.',
				$e,
			);
		}

		return $client;
	}

	/**
	 * The transport URL, which is deliberately *not* the token's audience.
	 *
	 * `mcp_server_url` is the address this server can actually reach (a service
	 * name inside a container network, typically), while the token is minted
	 * against `mcp_server_public_url` because that is the identifier the MCP
	 * server validates `aud` against. Conflating the two produces a token that is
	 * valid but unusable, or a URL that resolves nowhere.
	 */
	private function endpoint(): string {
		/** @var mixed $base */
		$base = $this->config->getSystemValue('mcp_server_url', 'http://localhost:8000');
		return rtrim(is_string($base) ? $base : 'http://localhost:8000', '/') . '/mcp';
	}

	/**
	 * Turn a connect failure into something an admin can act on, for the log.
	 *
	 * Deliberately not the message the user sees — see the caller.
	 *
	 * A 401 here is almost never a broken token — it is the audience or issuer
	 * disagreeing, and it is genuinely hard to spot because the management API
	 * keeps working throughout: that path deliberately skips both checks, so half
	 * the integration looks healthy while `/mcp` refuses every call.
	 */
	private function explainFailure(\Throwable $e): string {
		$message = $e->getMessage();

		if (str_contains($message, '401') || stripos($message, 'unauthor') !== false) {
			return 'The MCP server rejected Astrolabe\'s token. Check that this server\'s '
				. '"mcp_server_public_url" matches the MCP server\'s NEXTCLOUD_MCP_SERVER_URL '
				. '(the token audience), and that its issuer is listed in OIDC_ISSUER or '
				. 'NEXTCLOUD_HOST. Original error: ' . $message;
		}

		return 'Could not reach the MCP server at ' . $this->endpoint() . ': ' . $message;
	}
}
