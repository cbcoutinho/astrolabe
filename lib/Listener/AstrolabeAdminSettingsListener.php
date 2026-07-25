<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Listener;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Settings\Admin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Settings\Events\DeclarativeSettingsGetValueEvent;
use OCP\Settings\Events\DeclarativeSettingsSetValueEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<DeclarativeSettingsGetValueEvent|DeclarativeSettingsSetValueEvent>
 */
class AstrolabeAdminSettingsListener implements IEventListener {
	private const KNOWN_FIELDS = [
		'mcp_server_url',
		'mcp_server_public_url',
		'astrolabe_client_id',
		'mcp_webhook_secret',
	];

	/**
	 * Agent fields, which live in **app** config rather than system config.
	 *
	 * They are handled here rather than by Nextcloud's internal storage because
	 * DeclarativeManager resolves an app's storage type from the first schema
	 * that does not contain the field, so a second form cannot choose a different
	 * one — see AstrolabeAgentSettings.
	 *
	 * @var array<string, 'bool'|'int'|'string'>
	 */
	private const AGENT_FIELDS = [
		Admin::SETTING_AGENT_ENABLED => 'bool',
		Admin::SETTING_AGENT_SCOPES => 'string',
		Admin::SETTING_AGENT_MAX_ITERATIONS => 'int',
		Admin::SETTING_AGENT_TIMEOUT => 'int',
	];

	/**
	 * Fields holding a secret: never echoed back on Get, and an empty value on
	 * Set means "leave the stored value unchanged" (so the admin needn't re-enter
	 * the secret on every save).
	 */
	private const SECRET_FIELDS = [
		'mcp_webhook_secret',
	];

	public function __construct(
		private IConfig $config,
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

		if (!in_array($event->getFormId(), ['astrolabe-admin-settings', 'astrolabe-agent-settings'], true)) {
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
			return;
		}

		if (!in_array($fieldId, self::KNOWN_FIELDS, true)) {
			return;
		}

		// Never echo a stored secret back to the browser; the admin UI shows a
		// "configured" indicator (via Admin::getForm initial state) instead.
		if (in_array($fieldId, self::SECRET_FIELDS, true)) {
			$event->setValue('');
			return;
		}

		$event->setValue($this->config->getSystemValue($fieldId, ''));
	}

	private function handleSetValue(DeclarativeSettingsSetValueEvent $event): void {
		$fieldId = $event->getFieldId();

		if (isset(self::AGENT_FIELDS[$fieldId])) {
			$this->writeAgentValue($fieldId, $event->getValue());
			$event->stopPropagation();
			return;
		}

		if (!in_array($fieldId, self::KNOWN_FIELDS, true)) {
			return;
		}

		$value = (string)$event->getValue();

		// Empty submission for a secret field means "keep the current value" — the
		// field is rendered blank on Get, so a blank save must not clobber it.
		if ($value === '' && in_array($fieldId, self::SECRET_FIELDS, true)) {
			$event->stopPropagation();
			return;
		}

		try {
			$this->config->setSystemValue($fieldId, $value);
			$this->logger->info('Astrolabe admin setting updated', [
				'field' => $fieldId,
				'app' => Application::APP_ID,
			]);
		} catch (\Exception $e) {
			$this->logger->error('Failed to update Astrolabe admin setting', [
				'field' => $fieldId,
				'error' => $e->getMessage(),
				'app' => Application::APP_ID,
			]);
			throw $e;
		}

		$event->stopPropagation();
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
