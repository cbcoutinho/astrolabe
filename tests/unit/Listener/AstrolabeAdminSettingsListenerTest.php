<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Listener;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Listener\AstrolabeAdminSettingsListener;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\Settings\Events\DeclarativeSettingsGetValueEvent;
use OCP\Settings\Events\DeclarativeSettingsSetValueEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AstrolabeAdminSettingsListener, which persists the agent
 * declarative form. The MCP connection settings have their own validated
 * endpoint (ApiController::saveConnectionSettings) and are no longer handled here.
 */
final class AstrolabeAdminSettingsListenerTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;
	private AstrolabeAdminSettingsListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new AstrolabeAdminSettingsListener(
			$this->appConfig,
			$this->logger,
		);
	}

	public function testRetiredConnectionFormIsIgnored(): void {
		// The old declarative "MCP Server Configuration" form bypassed validation;
		// a save aimed at it (e.g. a stale browser tab) must not be persisted.
		$this->appConfig->expects($this->never())->method($this->anything());

		$event = new DeclarativeSettingsSetValueEvent(
			$this->createMock(IUser::class),
			Application::APP_ID,
			'astrolabe-admin-settings',
			'mcp_server_url',
			' https://tenant.example.com ',
		);
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}

	public function testUnknownFieldDoesNotStopPropagationOnSave(): void {
		// Unknown field IDs must not be silently consumed — otherwise a
		// future rename would drop saves with no log trail.
		$this->appConfig->expects($this->never())->method($this->anything());

		$event = $this->buildSetEvent('some_unknown_field', 'whatever');
		$this->listener->handle($event);

		$this->assertFalse(
			$event->isPropagationStopped(),
			'Listener must not stop propagation for unknown field IDs',
		);
	}

	public function testUnknownFieldIsIgnoredOnRead(): void {
		$event = $this->buildGetEvent('some_unknown_field');
		$this->listener->handle($event);

		// DeclarativeSettingsGetValueEvent::getValue() throws when nothing
		// has set the value yet, which is the contract for "this handler
		// didn't claim the field".
		$this->expectException(\Exception::class);
		$event->getValue();
	}

	public function testIgnoresEventForDifferentApp(): void {
		$this->appConfig->expects($this->never())->method($this->anything());

		$event = new DeclarativeSettingsSetValueEvent(
			$this->createMock(IUser::class),
			'someotherapp',
			'astrolabe-agent-settings',
			'agent_enabled',
			true,
		);

		$this->listener->handle($event);
		$this->assertFalse($event->isPropagationStopped());
	}

	private function buildSetEvent(string $fieldId, mixed $value): DeclarativeSettingsSetValueEvent {
		return new DeclarativeSettingsSetValueEvent(
			$this->createMock(IUser::class),
			Application::APP_ID,
			'astrolabe-agent-settings',
			$fieldId,
			$value,
		);
	}

	private function buildGetEvent(string $fieldId): DeclarativeSettingsGetValueEvent {
		return new DeclarativeSettingsGetValueEvent(
			$this->createMock(IUser::class),
			Application::APP_ID,
			'astrolabe-agent-settings',
			$fieldId,
		);
	}

	/**
	 * Agent settings live in app config, not system config.
	 */
	public function testReadsAnAgentSettingFromAppConfig(): void {
		$this->appConfig->method('getValueBool')->willReturn(true);

		$event = new DeclarativeSettingsGetValueEvent(
			$this->createMock(IUser::class),
			'astrolabe',
			'astrolabe-agent-settings',
			'agent_enabled',
		);
		$this->listener->handle($event);

		$this->assertTrue($event->getValue());
	}

	public function testWritesAnAgentSettingToAppConfig(): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('astrolabe', 'agent_scopes', 'semantic.read notes.read');

		$event = new DeclarativeSettingsSetValueEvent(
			$this->createMock(IUser::class),
			'astrolabe',
			'astrolabe-agent-settings',
			'agent_scopes',
			'semantic.read notes.read',
		);
		$this->listener->handle($event);
	}

	/**
	 * A checkbox arrives as a bool from the UI but as "0" from other callers, and
	 * (bool)"0" is true — so unchecking must not silently enable the agent.
	 */
	public function testAStringZeroDisablesRatherThanEnables(): void {
		$this->appConfig->expects($this->once())
			->method('setValueBool')
			->with('astrolabe', 'agent_enabled', false);

		$event = new DeclarativeSettingsSetValueEvent(
			$this->createMock(IUser::class),
			'astrolabe',
			'astrolabe-agent-settings',
			'agent_enabled',
			'0',
		);
		$this->listener->handle($event);
	}

	public function testAgentIterationCapIsStoredAsAnInteger(): void {
		$this->appConfig->expects($this->once())
			->method('setValueInt')
			->with('astrolabe', 'agent_max_iterations', 5);

		$event = new DeclarativeSettingsSetValueEvent(
			$this->createMock(IUser::class),
			'astrolabe',
			'astrolabe-agent-settings',
			'agent_max_iterations',
			'5',
		);
		$this->listener->handle($event);
	}
}
