<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Listener;

use OCA\Astrolabe\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
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
	 * Fields holding a secret: never echoed back on Get, and an empty value on
	 * Set means "leave the stored value unchanged" (so the admin needn't re-enter
	 * the secret on every save).
	 */
	private const SECRET_FIELDS = [
		'mcp_webhook_secret',
	];

	public function __construct(
		private IConfig $config,
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

		if ($event->getFormId() !== 'astrolabe-admin-settings') {
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
}
