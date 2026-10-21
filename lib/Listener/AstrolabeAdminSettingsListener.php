<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Listener;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Settings\Admin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\Settings\Events\DeclarativeSettingsGetValueEvent;
use OCP\Settings\Events\DeclarativeSettingsSetValueEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<DeclarativeSettingsGetValueEvent|DeclarativeSettingsSetValueEvent>
 */
class AstrolabeAdminSettingsListener implements IEventListener {
	/**
	 * Agent fields, which live in **app** config — see AstrolabeAgentSettings
	 * for why they are persisted here rather than by Nextcloud's internal storage.
	 *
	 * (The MCP connection settings used to be a declarative form handled here
	 * too; they now have Astrolabe's own form, saved by
	 * ApiController::saveConnectionSettings with validation.)
	 *
	 * @var array<string, 'bool'|'int'|'string'>
	 */
	private const AGENT_FIELDS = [
		Admin::SETTING_AGENT_ENABLED => 'bool',
		Admin::SETTING_AGENT_SCOPES => 'string',
		Admin::SETTING_AGENT_MAX_ITERATIONS => 'int',
		Admin::SETTING_AGENT_TIMEOUT => 'int',
	];

	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof DeclarativeSettingsGetValueEvent && !$event instanceof DeclarativeSettingsSetValueEvent) {
			return;
		}

		if ($event->getApp() !== Application::APP_ID) {
			return;
		}

		if ($event->getFormId() !== 'astrolabe-agent-settings') {
			return;
		}

		if ($event instanceof DeclarativeSettingsGetValueEvent) {
			$this->handleGetValue($event);
		} elseif ($event instanceof DeclarativeSettingsSetValueEvent) {
			$this->handleSetValue($event);
		}
	}

	private function handleGetValue(DeclarativeSettingsGetValueEvent $event): void {
		$fieldId = $event->getFieldId();

		if (isset(self::AGENT_FIELDS[$fieldId])) {
			$event->setValue($this->readAgentValue($fieldId));
		}
	}

	private function handleSetValue(DeclarativeSettingsSetValueEvent $event): void {
		$fieldId = $event->getFieldId();

		if (isset(self::AGENT_FIELDS[$fieldId])) {
			$this->writeAgentValue($fieldId, $event->getValue());
			$event->stopPropagation();
		}
	}

	/**
	 * Read an agent setting, typed the way the form expects to render it.
	 */
	private function readAgentValue(string $fieldId): bool|int|string {
		return match (self::AGENT_FIELDS[$fieldId]) {
			'bool' => $this->appConfig->getValueBool(Application::APP_ID, $fieldId, self::agentDefault($fieldId) === true),
			'int' => $this->appConfig->getValueInt(Application::APP_ID, $fieldId, (int)self::agentDefault($fieldId)),
			default => $this->appConfig->getValueString(Application::APP_ID, $fieldId, (string)self::agentDefault($fieldId)),
		};
	}

	private function writeAgentValue(string $fieldId, mixed $value): void {
		match (self::AGENT_FIELDS[$fieldId]) {
			// A checkbox arrives as a bool from the UI but as "1"/"0" from other
			// callers, so normalise rather than casting a string straight to bool
			// — (bool)"0" is true.
			'bool' => $this->appConfig->setValueBool(
				Application::APP_ID,
				$fieldId,
				filter_var($value, FILTER_VALIDATE_BOOL),
			),
			'int' => $this->appConfig->setValueInt(Application::APP_ID, $fieldId, (int)$value),
			default => $this->appConfig->setValueString(Application::APP_ID, $fieldId, (string)$value),
		};

		$this->logger->info('Astrolabe agent setting updated', [
			'field' => $fieldId,
			'app' => Application::APP_ID,
		]);
	}

	private static function agentDefault(string $fieldId): bool|int|string {
		return match ($fieldId) {
			Admin::SETTING_AGENT_ENABLED => Admin::DEFAULT_AGENT_ENABLED,
			Admin::SETTING_AGENT_SCOPES => Admin::DEFAULT_AGENT_SCOPES,
			Admin::SETTING_AGENT_MAX_ITERATIONS => Admin::DEFAULT_AGENT_MAX_ITERATIONS,
			Admin::SETTING_AGENT_TIMEOUT => Admin::DEFAULT_AGENT_TIMEOUT,
			default => '',
		};
	}
}
