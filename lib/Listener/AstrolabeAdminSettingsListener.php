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

		// Map field IDs to system config keys
		$value = match($fieldId) {
			'mcp_server_url' => $this->config->getSystemValue('mcp_server_url', ''),
			'astrolabe_client_id' => $this->config->getSystemValue('astrolabe_client_id', ''),
			'astrolabe_client_secret' => '****', // Never leak the secret on read
			'astrolabe_internal_url' => $this->config->getSystemValue('astrolabe_internal_url', ''),
			default => null,
		};

		if ($value !== null) {
			$event->setValue($value);
		}
	}

	private const KNOWN_FIELDS = [
		'mcp_server_url',
		'astrolabe_client_id',
		'astrolabe_client_secret',
		'astrolabe_internal_url',
	];

	private function handleSetValue(DeclarativeSettingsSetValueEvent $event): void {
		$fieldId = $event->getFieldId();
		$value = $event->getValue();

		// Don't silently consume events for unknown field IDs. The form is
		// closed today, but if a field is ever renamed without updating
		// KNOWN_FIELDS this returns control to the dispatcher rather than
		// dropping the save with no trace.
		if (!in_array($fieldId, self::KNOWN_FIELDS, true)) {
			return;
		}

		// For password fields, if the value is '****', don't update (user didn't change it)
		if ($fieldId === 'astrolabe_client_secret' && $value === '****') {
			$event->stopPropagation();
			return;
		}

		// Surface invalid astrolabe_internal_url at save time so the admin sees
		// the problem immediately, rather than discovering at OAuth-flow runtime
		// that NcInternalUrlResolver silently fell back to http://localhost.
		// The save itself still succeeds — the resolver's runtime fallback is
		// safe — this is a UX nudge for managed-NC operators.
		if ($fieldId === 'astrolabe_internal_url') {
			$trimmed = trim((string)$value);
			if ($trimmed !== '') {
				$scheme = filter_var($trimmed, FILTER_VALIDATE_URL)
					? parse_url($trimmed, PHP_URL_SCHEME)
					: null;
				if ($scheme !== 'http' && $scheme !== 'https') {
					$this->logger->warning(
						'astrolabe_internal_url set to a value that will fall back to http://localhost at runtime',
						[
							'configured_url' => $trimmed,
							'app' => Application::APP_ID,
						],
					);
				}
			}
		}

		try {
			// The KNOWN_FIELDS guard above narrows $fieldId so the match is
			// exhaustive without a default arm.
			match($fieldId) {
				'mcp_server_url' => $this->config->setSystemValue('mcp_server_url', (string)$value),
				'astrolabe_client_id' => $this->config->setSystemValue('astrolabe_client_id', (string)$value),
				'astrolabe_client_secret' => $this->config->setSystemValue('astrolabe_client_secret', (string)$value),
				'astrolabe_internal_url' => $this->config->setSystemValue('astrolabe_internal_url', (string)$value),
			};

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
