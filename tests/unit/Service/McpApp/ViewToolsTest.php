<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\McpApp;

use Eris\Generators;
use Eris\TestTrait;
use Mcp\Client\Client;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Result\ReadResourceResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use OCA\Astrolabe\Service\Mcp\McpClientFactory;
use OCA\Astrolabe\Service\McpApp\ViewTools;
use OCA\Astrolabe\Tests\Unit\PropertyGenerators;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * Which tools a view may call, from what the MCP server publishes: the tool's
 * ui binding and visibility, its scopes, and whether it writes.
 */
final class ViewToolsTest extends TestCase {
	use TestTrait;

	private const URI = 'ui://nextcloud-mcp/document-viewer.html';

	/** @param array<string, mixed> $meta */
	private static function tool(string $name, array $meta, ?bool $readOnly = true): Tool {
		return new Tool($name, null, ['type' => 'object'], null, new ToolAnnotations(readOnlyHint: $readOnly), null, $meta);
	}

	/** @param list<string> $scopes */
	private static function viewerMeta(array $scopes, ?array $visibility = ['app'], string $uri = self::URI): array {
		$ui = ['resourceUri' => $uri];
		if ($visibility !== null) {
			$ui['visibility'] = $visibility;
		}
		return ['ui' => $ui, ViewTools::SCOPES_META_KEY => $scopes];
	}

	public function testKeepsTheViewsAppToolsWithTheirScopesAndWhetherTheyWrite(): void {
		$tools = ViewTools::fromListing([
			self::tool('open_document', self::viewerMeta(['files.read'], null)),
			self::tool('preview_changes', self::viewerMeta(['files.read'])),
			self::tool('apply_changes', self::viewerMeta(['files.read', 'files.write']), null),
		], self::URI);

		$this->assertSame([
			'open_document' => ['scopes' => 'files.read', 'write' => false],
			'preview_changes' => ['scopes' => 'files.read', 'write' => false],
			// No readOnlyHint is not "read-only": the host confirms it.
			'apply_changes' => ['scopes' => 'files.read files.write', 'write' => true],
		], $tools);
	}

	public function testLeavesOutEverythingTheViewMayNotCall(): void {
		$tools = ViewTools::fromListing([
			self::tool('nc_webdav_delete_resource', [], false),
			self::tool('model_only', self::viewerMeta(['files.read'], ['model'])),
			self::tool('other_view', self::viewerMeta(['files.read'], ['app'], 'ui://other/view.html')),
			self::tool('no_scopes', ['ui' => ['resourceUri' => self::URI, 'visibility' => ['app']]]),
			self::tool('bad_scope', self::viewerMeta(['files.read', 'files.read openid'])),
		], self::URI);

		$this->assertSame([], $tools);
	}

	public function testScopesAreAllOrNothing(): void {
		$this->assertSame('files.read files.write', ViewTools::scopes([ViewTools::SCOPES_META_KEY => ['files.read', 'files.write', 'files.read']]));
		foreach ([null, [], [ViewTools::SCOPES_META_KEY => []], [ViewTools::SCOPES_META_KEY => 'files.read'],
			[ViewTools::SCOPES_META_KEY => ['files.read', 7]], [ViewTools::SCOPES_META_KEY => ["files.read\n"]]] as $meta) {
			$this->assertSame('', ViewTools::scopes($meta), var_export($meta, true));
		}
	}

	/**
	 * Whatever the server publishes, the minted scope list is space-separated
	 * well-formed scopes: no whitespace, quote or separator gets in from input.
	 */
	public function testScopesNeverCarryAnythingButWellFormedScopes(): void {
		$alphabet = ['a', 'z', '_', '0', '.', ' ', "\n", "\t", '"', ';', 'files.read', 'files.write'];
		// Mostly valid scopes, so the all-valid branch is reached, plus near-misses.
		$scope = Generators::frequency(
			[4, Generators::elements('files.read', 'files.write', 'notes.read', 'shopping_list.read')],
			[1, PropertyGenerators::text($alphabet)],
			[1, Generators::map(static fn (string $s): string => 'files.read' . $s, PropertyGenerators::text($alphabet))],
		);
		$this->forAll(Generators::seq($scope))->withMaxSize(6)
			->then(function (array $published): void {
				$scopes = ViewTools::scopes([ViewTools::SCOPES_META_KEY => $published]);
				if ($scopes === '') {
					return;
				}
				foreach (explode(' ', $scopes) as $scope) {
					$this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/D', $scope);
				}
			});
	}

	public function testListsWithTheScopesTheResourcePublishesAndCachesTheResult(): void {
		$client = $this->createMock(Client::class);
		$client->expects($this->once())->method('readResource')->with(self::URI)->willReturn(new ReadResourceResult([
			new TextResourceContents(self::URI, 'text/html;profile=mcp-app', '<p>', [ViewTools::SCOPES_META_KEY => ['files.read', 'files.write']]),
		]));
		$client->expects($this->once())->method('listTools')->willReturn(new ListToolsResult([
			self::tool('preview_changes', self::viewerMeta(['files.read'])),
		]));
		$minted = [];
		$factory = $this->createMock(McpClientFactory::class);
		$factory->method('connect')->willReturnCallback(function (string $uid, string $scopes) use (&$minted, $client): Client {
			$minted[] = $scopes;
			return $client;
		});
		$viewTools = new ViewTools($factory, $this->arrayCacheFactory());

		$first = $viewTools->forView('alice', self::URI);
		$second = $viewTools->forView('alice', self::URI);

		// The resource needs no scope; the listing asks for the published union.
		$this->assertSame(['', 'files.read files.write'], $minted);
		$this->assertSame(['preview_changes' => ['scopes' => 'files.read', 'write' => false]], $first);
		$this->assertSame($first, $second);
	}

	public function testAResourceWithoutScopesOffersNoTools(): void {
		$client = $this->createMock(Client::class);
		$client->method('readResource')->willReturn(new ReadResourceResult([new TextResourceContents(self::URI, 'text/html', '<p>')]));
		$client->expects($this->never())->method('listTools');
		$factory = $this->createMock(McpClientFactory::class);
		$factory->method('connect')->willReturn($client);

		$this->assertSame([], (new ViewTools($factory, $this->arrayCacheFactory()))->forView('alice', self::URI));
	}

	private function arrayCacheFactory(): ICacheFactory {
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(static function (string $key) use (&$store): mixed {
			return $store[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$store): bool {
			$store[$key] = $value;
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		return $factory;
	}
}
