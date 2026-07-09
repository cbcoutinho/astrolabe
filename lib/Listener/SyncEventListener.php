<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Listener;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\BackgroundJob\SyncEventDeliveryJob;
use OCA\Astrolabe\Service\WebhookEventFilter;
use OCA\Astrolabe\Service\WebhookPresets;
use OCA\Astrolabe\Settings\Admin;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\EventDispatcher\IWebhookCompatibleEvent;
use OCP\Files\Events\Node\AbstractNodeEvent;
use OCP\Files\Events\Node\AbstractNodesEvent;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Native replacement for the previous "register webhooks via the MCP server"
 * path: reacts to Nextcloud node/tag (and app) events and enqueues delivery of
 * the exact webhook envelope Nextcloud's own webhook engine would POST to the
 * MCP server's ingress ``/webhooks/nextcloud``.
 *
 * Subscribed dynamically at boot (see {@see Application::boot()}) to only the
 * event classes of the presets the admin has enabled, so this handler is never
 * woken for events we don't care about. Mirrors the shape of core's
 * `webhook_listeners` `WebhooksEventListener`: build the envelope, apply the
 * preset filter, enqueue an async delivery job rather than calling out inline.
 *
 * @template-implements IEventListener<Event>
 */
final class SyncEventListener implements IEventListener {
	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private IJobList $jobList,
		private IUserSession $userSession,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		// Only events that can serialize themselves for a webhook are deliverable;
		// this is also what core's webhook engine requires.
		if (!$event instanceof IWebhookCompatibleEvent) {
			return;
		}

		if (!$this->appConfig->getValueBool(Application::APP_ID, Admin::SETTING_NATIVE_SYNC_ENABLED, Admin::DEFAULT_NATIVE_SYNC_ENABLED)) {
			return;
		}
		$enabledPresets = $this->enabledPresets();
		if ($enabledPresets === []) {
			return;
		}

		$user = $this->resolveUser($event);
		if ($user === null) {
			// The MCP ingress drops an envelope with a null user, so there is
			// nothing to deliver. Common in contexts we can't attribute.
			$this->logger->debug('Skipping sync event with no resolvable user', ['event_class' => $event::class]);
			return;
		}

		// Build the envelope and enqueue delivery. This runs synchronously on the
		// triggering file/tag operation's request, so it must never throw: a bug
		// in envelope-building or a transient IJobList::add() failure would
		// otherwise turn a routine file save into a 500 — the exact failure the
		// async QueuedJob design exists to prevent. Fail closed: log and drop the
		// event (the MCP polling scanner reconciles anything not delivered).
		try {
			$eventData = $event->getWebhookSerializable();
			$eventData['class'] = $event::class;
			$envelope = [
				'event' => $eventData,
				'user' => $user,
				'time' => time(),
			];

			// Enqueue at most one delivery for this fired event even when several
			// enabled presets cover the same event class (e.g. Notes + All-Files both
			// listen for NodeWrittenEvent): the payload is identical, so a second
			// delivery would be redundant. Deliver on the first matching filter.
			foreach ($enabledPresets as $presetId) {
				$preset = WebhookPresets::getPreset($presetId);
				if ($preset === null || !isset($preset['events']) || !is_array($preset['events'])) {
					continue;
				}
				/** @var mixed $presetEvent */
				foreach ($preset['events'] as $presetEvent) {
					if (!is_array($presetEvent) || ($presetEvent['event'] ?? null) !== $event::class) {
						continue;
					}
					/** @psalm-suppress MixedAssignment preset filter is an untyped array literal */
					$rawFilter = $presetEvent['filter'] ?? [];
					$filter = is_array($rawFilter) ? $rawFilter : [];
					if (WebhookEventFilter::matches($filter, $envelope)) {
						$this->jobList->add(SyncEventDeliveryJob::class, [$envelope, bin2hex(random_bytes(5))]);
						return;
					}
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to enqueue sync event; dropping (scanner will reconcile)', [
				'event_class' => $event::class,
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * The preset ids the admin has enabled (JSON list app-config).
	 *
	 * @return list<string>
	 */
	private function enabledPresets(): array {
		return WebhookPresets::decodeEnabledPresetIds(
			$this->appConfig->getValueString(Application::APP_ID, Admin::SETTING_ENABLED_SYNC_PRESETS, Admin::DEFAULT_ENABLED_SYNC_PRESETS),
		);
	}

	/**
	 * Resolve the uid + display name the document belongs to.
	 *
	 * Prefers the **node owner** over the acting session user for node-carrying
	 * events (`AbstractNodeEvent`/`AbstractNodesEvent` — Files/Notes): these can
	 * fire in a background/cron/WebDAV context with no session, and when an admin
	 * edits another user's file the document (and the MCP per-user app-password
	 * used to index it) belongs to the owner, not the actor. Falls back to the
	 * session user, then to the ``/{uid}/files/...`` path segment.
	 *
	 * **Non-node events (SystemTag `MapperEvent`, Deck, etc.) have no `Node`, so
	 * they use the acting session user.** That's correct for the normal in-session
	 * case (a user tagging a file, editing a card); a tag/card change fired purely
	 * from a background/cron/`occ` context with no session would find no user and
	 * be skipped — an accepted limitation (owner-resolution for those types is a
	 * follow-up), since those events overwhelmingly originate in a user request.
	 *
	 * @return array{uid: string, displayName: string}|null
	 */
	private function resolveUser(Event $event): ?array {
		$node = null;
		try {
			if ($event instanceof AbstractNodesEvent) {
				$node = $event->getTarget();
			} elseif ($event instanceof AbstractNodeEvent) {
				$node = $event->getNode();
			}
			$owner = $node?->getOwner();
			if ($owner !== null) {
				return ['uid' => $owner->getUID(), 'displayName' => $owner->getDisplayName()];
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Could not read node owner for sync event', ['error' => $e->getMessage()]);
		}

		$sessionUser = $this->userSession->getUser();
		if ($sessionUser !== null) {
			return ['uid' => $sessionUser->getUID(), 'displayName' => $sessionUser->getDisplayName()];
		}

		// Last resort: the first path segment of a node path is the owner uid
		// (``/{uid}/files/...``). Only meaningful for node events.
		if ($node !== null) {
			try {
				$segments = explode('/', ltrim($node->getPath(), '/'));
				if (($segments[0] ?? '') !== '') {
					return ['uid' => $segments[0], 'displayName' => $segments[0]];
				}
			} catch (\Throwable $e) {
				$this->logger->debug('Could not read node path for sync event', ['error' => $e->getMessage()]);
			}
		}

		return null;
	}
}
