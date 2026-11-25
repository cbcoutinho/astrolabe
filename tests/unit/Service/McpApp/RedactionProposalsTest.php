<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\McpApp;

use Eris\Generators;
use Eris\TestTrait;
use OCA\Astrolabe\Service\McpApp\RedactionProposals;
use OCA\Astrolabe\Tests\Unit\PropertyGenerators;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The store that keeps model-proposed redaction strings out of viewer links.
 * An id must only resolve for the user and file it was minted for.
 */
final class RedactionProposalsTest extends TestCase {
	use TestTrait;

	private const ID = 'abcdefghijklmnopqrstuvwx';

	/** @var array<string, mixed> */
	private array $store = [];

	private function proposals(): RedactionProposals {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->store[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
			$this->store[$key] = $value;
			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(self::ID);
		return new RedactionProposals($cacheFactory, $random);
	}

	public function testRoundTripsTextsAndReview(): void {
		$proposals = $this->proposals();
		$items = ['ID 123', ['text' => 'Jane Doe', 'group' => 'Person A']];
		$review = ['heading' => 'People', 'buttons' => [['label' => 'Only A', 'set' => 'only', 'groups' => ['Person A']]]];

		$id = $proposals->store('alice', 7, $items, $review);

		$this->assertSame(self::ID, $id);
		$this->assertSame($items, $proposals->texts('alice', $id, 7));
		$this->assertSame($review, $proposals->review('alice', $id, 7));
	}

	public function testAProposalWithoutReviewHasNone(): void {
		$proposals = $this->proposals();
		$id = $proposals->store('alice', 7, ['x']);

		$this->assertNull($proposals->review('alice', $id, 7));
	}

	public function testAnIdIsUselessToAnotherUserOrForAnotherFile(): void {
		$proposals = $this->proposals();
		$id = $proposals->store('alice', 7, ['secret'], ['heading' => 'h']);

		$this->assertSame([], $proposals->texts('bob', $id, 7));
		$this->assertNull($proposals->review('bob', $id, 7));
		$this->assertSame([], $proposals->texts('alice', $id, 8));
		$this->assertNull($proposals->review('alice', $id, 8));
	}

	/** Expired and evicted entries look the same as unknown ids: a cache miss. */
	public function testUnknownOrMalformedIdsResolveToNothing(): void {
		$proposals = $this->proposals();
		$proposals->store('alice', 7, ['secret']);

		$this->assertSame([], $proposals->texts('alice', 'zyxwvutsrqponmlkjihgfedc', 7));
		$this->assertSame([], $proposals->texts('alice', '../' . self::ID, 7));
		$this->assertNull($proposals->review('alice', 'short', 7));
	}

	public function testViewerContextRoundTripsAndIsCapped(): void {
		$proposals = $this->proposals();
		$this->assertNull($proposals->viewerContext('alice'));

		$proposals->saveViewerContext('alice', 7, str_repeat('é', 5000));

		$ctx = $proposals->viewerContext('alice');
		$this->assertNotNull($ctx);
		$this->assertSame(7, $ctx['file_id']);
		$this->assertSame(4000, mb_strlen($ctx['text']));
		$this->assertNull($proposals->viewerContext('bob'));
	}

	/**
	 * normalize() reads model output: anything it returns is a string or a
	 * {text, group?} pair of strings, whatever went in.
	 */
	public function testNormalizeOnlyEverReturnsWellFormedItems(): void {
		$scalar = Generators::oneOf(
			PropertyGenerators::text(),
			Generators::int(),
			Generators::bool(),
			Generators::constant(null),
		);
		$item = Generators::oneOf(
			$scalar,
			Generators::associative(['text' => $scalar, 'group' => $scalar]),
			Generators::associative(['text' => $scalar]),
			Generators::associative(['group' => $scalar]),
		);
		$this->forAll(Generators::seq($item))->withMaxSize(10)
			->then(function (array $items): void {
				foreach (RedactionProposals::normalize($items) as $out) {
					if (is_string($out)) {
						continue;
					}
					$this->assertIsString($out['text']);
					$this->assertSame([], array_diff(array_keys($out), ['text', 'group']));
					if (isset($out['group'])) {
						$this->assertIsString($out['group']);
						$this->assertNotSame('', $out['group']);
					}
				}
			});
	}

	public function testNormalizeKeepsValidItemsInOrderAndDropsTheRest(): void {
		$this->assertSame(
			['a', ['text' => 'b', 'group' => 'G'], ['text' => 'c']],
			RedactionProposals::normalize(['a', 5, ['text' => 'b', 'group' => 'G'], ['text' => 'c', 'group' => ''], ['group' => 'G'], null]),
		);
		$this->assertSame(['one term'], RedactionProposals::normalize('one term'), 'a bare string is one item');
		$this->assertSame([], RedactionProposals::normalize(null));
	}
}
