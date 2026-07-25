<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCA\Astrolabe\Controller\AssistantController;
use OCA\Astrolabe\Service\Assistant\SummaryException;
use OCA\Astrolabe\Service\Assistant\SummaryService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The boundary's own responsibilities: authentication, coercing untrusted request
 * shapes, and mapping a failure reason onto a status code.
 *
 * The multipart branch of uploadedPages() is deliberately not exercised here — it
 * gates on is_uploaded_file(), which cannot be satisfied outside a real upload, so
 * it belongs to the e2e tier tracked on card #885.
 */
final class AssistantControllerTest extends TestCase {
	private SummaryService&MockObject $summaryService;
	private IUserSession&MockObject $userSession;
	private IRequest&MockObject $request;
	private AssistantController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->summaryService = $this->createMock(SummaryService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new AssistantController(
			'astrolabe',
			$this->request,
			$this->summaryService,
			$this->userSession,
		);
	}

	public function testReturnsTheTaskIdAndTheTierThatAnswered(): void {
		$this->summaryService->method('schedule')->willReturn([
			'task_id' => 42,
			'mode' => 'analyze-images',
		]);

		$response = $this->controller->summarize('file', '123', 0, 900);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['success' => true, 'task_id' => 42, 'mode' => 'analyze-images'],
			$response->getData(),
		);
	}

	public function testForwardsAccessIdentifiersForRevalidation(): void {
		$this->summaryService->expects($this->once())
			->method('schedule')
			->with(
				'alice',
				'deck_card',
				'77',
				0,
				900,
				2,
				5,
				['board_id' => 3, 'mailbox_id' => null, 'calendar_uri' => null, 'path' => null],
				[],
				[],
			)
			->willReturn(['task_id' => 1, 'mode' => 'text2text']);

		$this->controller->summarize('deck_card', '77', 0, 900, 2, 5, 3);
	}

	/**
	 * File ids arrive as request parameters, so they reach the controller as
	 * strings and may be anything at all. Only well-formed integers are passed on;
	 * core still re-checks the caller's access to each one.
	 */
	public function testCoercesAndFiltersImageFileIds(): void {
		$this->summaryService->expects($this->once())
			->method('schedule')
			->with(
				$this->anything(), $this->anything(), $this->anything(),
				$this->anything(), $this->anything(), $this->anything(),
				$this->anything(), $this->anything(),
				[7, 9, 11],
				[],
			)
			->willReturn(['task_id' => 1, 'mode' => 'analyze-images']);

		$this->controller->summarize(
			'file', '123', 0, 900, null, null, null, null, null, null,
			[7, '9', 'not-a-number', null, ['nested'], '11', '-4.2'],
		);
	}

	public function testUnauthenticatedIsRejectedWithoutScheduling(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$this->summaryService->expects($this->never())->method('schedule');

		$controller = new AssistantController(
			'astrolabe',
			$this->request,
			$this->summaryService,
			$userSession,
		);

		$response = $controller->summarize('file', '123', 0, 900);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	/**
	 * The service decides the status because the reason and the code belong
	 * together; the controller must pass it through rather than flatten
	 * everything to a 500.
	 */
	public function testSurfacesTheServiceStatusCode(): void {
		$this->summaryService->method('schedule')->willThrowException(
			new SummaryException('You no longer have access to this document', Http::STATUS_FORBIDDEN),
		);

		$response = $this->controller->summarize('file', '123', 0, 900);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			['success' => false, 'error' => 'You no longer have access to this document'],
			$response->getData(),
		);
	}
}
