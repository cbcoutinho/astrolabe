<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Access;

use OCA\Astrolabe\Service\Access\AccessDecision;
use OCA\Astrolabe\Service\Access\CalendarAccessVerifier;
use OCP\Calendar\ICalendar;
use OCP\Calendar\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CalendarAccessVerifierTest extends TestCase {
	private IManager&MockObject $manager;
	private CalendarAccessVerifier $verifier;

	protected function setUp(): void {
		parent::setUp();
		$this->manager = $this->createMock(IManager::class);
		$this->verifier = new CalendarAccessVerifier($this->manager, $this->createMock(LoggerInterface::class));
	}

	private function calendar(string $uri, string $key): ICalendar&MockObject {
		$cal = $this->createMock(ICalendar::class);
		$cal->method('getUri')->willReturn($uri);
		$cal->method('getKey')->willReturn($key);
		return $cal;
	}

	public function testDelegatesWhenNoIdentifier(): void {
		$this->manager->expects($this->never())->method('getCalendarsForPrincipal');
		$this->assertSame(AccessDecision::DELEGATE, $this->verifier->verify('alice', ['doc_type' => 'calendar', 'id' => 'x']));
	}

	public function testAllowedWhenCalendarUriMatches(): void {
		$this->manager->method('getCalendarsForPrincipal')
			->with('principals/users/alice')
			->willReturn([$this->calendar('personal', '1'), $this->calendar('work', '2')]);
		$doc = ['doc_type' => 'calendar', 'id' => 'x', 'metadata' => ['calendar_uri' => 'work']];
		$this->assertSame(AccessDecision::ALLOWED, $this->verifier->verify('alice', $doc));
	}

	public function testDeniedWhenNoCalendarMatches(): void {
		$this->manager->method('getCalendarsForPrincipal')->willReturn([$this->calendar('personal', '1')]);
		$doc = ['doc_type' => 'calendar', 'id' => 'x', 'metadata' => ['calendar_uri' => 'someone-elses']];
		$this->assertSame(AccessDecision::DENIED, $this->verifier->verify('alice', $doc));
	}

	public function testDelegatesWhenEnumerationThrows(): void {
		$this->manager->method('getCalendarsForPrincipal')->willThrowException(new \RuntimeException('caldav down'));
		$doc = ['doc_type' => 'calendar', 'id' => 'x', 'metadata' => ['calendar_id' => 'work']];
		$this->assertSame(AccessDecision::DELEGATE, $this->verifier->verify('alice', $doc));
	}
}
