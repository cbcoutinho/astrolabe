<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service;

/**
 * Sync preset configurations: the event bundles an admin can turn on so
 * {@see \OCA\Astrolabe\Listener\SyncEventListener} delivers them to the MCP
 * server's webhook ingress.
 *
 * A preset only exists for content the MCP server actually indexes — notes,
 * files carrying an index tag, and Deck cards. Presets for content with no
 * index (calendar objects, Tables rows, Forms submissions) were removed in
 * 0.42.0: they cost a background job per change and the server dropped the
 * envelope on arrival. Those types stay in sync through the polling scanner.
 *
 * Before adding a preset, check that the server's
 * ``vector/webhook_parser.py`` turns the event into a DocumentTask.
 */
class WebhookPresets {
	// File/Notes webhook events
	public const FILE_EVENT_CREATED = 'OCP\\Files\\Events\\Node\\NodeCreatedEvent';
	public const FILE_EVENT_WRITTEN = 'OCP\\Files\\Events\\Node\\NodeWrittenEvent';
	// Use BeforeNodeDeletedEvent instead of NodeDeletedEvent to get node.id
	// See: https://github.com/nextcloud/server/issues/56371
	public const FILE_EVENT_DELETED = 'OCP\\Files\\Events\\Node\\BeforeNodeDeletedEvent';

	// System-tag assign/unassign (Nextcloud 32+). A single event class covers
	// both directions; the payload's `eventType` distinguishes them. Lets
	// adding/removing the `vector-index` tag trigger near-real-time (re)indexing
	// instead of waiting for the hourly scan. Requires NC >= 32 — MapperEvent
	// gained getWebhookSerializable() in 32.0.0; older servers never deliver it.
	public const SYSTEMTAG_EVENT_MAPPER = 'OCP\\SystemTag\\MapperEvent';

	// Deck webhook events (require nextcloud/deck PR #7910, which adds
	// IWebhookCompatibleEvent to these event classes). BoardUpdatedEvent only
	// carries a board ID and is used as a fan-out signal; the polling scanner
	// reconciles affected cards.
	public const DECK_EVENT_CARD_CREATED = 'OCA\\Deck\\Event\\CardCreatedEvent';
	public const DECK_EVENT_CARD_UPDATED = 'OCA\\Deck\\Event\\CardUpdatedEvent';
	public const DECK_EVENT_CARD_DELETED = 'OCA\\Deck\\Event\\CardDeletedEvent';
	public const DECK_EVENT_BOARD_UPDATED = 'OCA\\Deck\\Event\\BoardUpdatedEvent';

	// NOTE: Contacts does NOT support webhooks — its event classes do not
	// implement IWebhookCompatibleEvent. Use CardDAV sync-token mechanism for
	// efficient syncing.

	/**
	 * Get all available webhook presets.
	 *
	 * @return array<string, array{
	 *   name: string,
	 *   description: string,
	 *   app: string,
	 *   events: array<array{event: string, filter: array}>
	 * }>
	 */
	public static function getPresets(): array {
		return [
			'notes_sync' => [
				'name' => 'Notes Sync',
				'description' => 'Real-time synchronization for Notes app (create, update, delete)',
				'app' => 'notes',
				'events' => [
					[
						'event' => self::FILE_EVENT_CREATED,
						'filter' => ['event.node.path' => '/^\\/.*\\/files\\/Notes\\//'],
					],
					[
						'event' => self::FILE_EVENT_WRITTEN,
						'filter' => ['event.node.path' => '/^\\/.*\\/files\\/Notes\\//'],
					],
					[
						'event' => self::FILE_EVENT_DELETED,
						'filter' => ['event.node.path' => '/^\\/.*\\/files\\/Notes\\//'],
					],
				],
			],
			'files_sync' => [
				'name' => 'Files Sync',
				'description' => 'Real-time synchronization for indexed files: content changes to tagged documents, deletions, and index-tag changes (Nextcloud 32+)',
				'app' => 'files',
				'events' => [
					[
						'event' => self::FILE_EVENT_CREATED,
						'filter' => [],
					],
					[
						'event' => self::FILE_EVENT_WRITTEN,
						'filter' => [],
					],
					[
						'event' => self::FILE_EVENT_DELETED,
						'filter' => [],
					],
					// Tag assign/unassign. Drives vector-index (re)indexing when a
					// file or folder is tagged/untagged. Delivered only on NC >= 32;
					// harmless to register on older servers (it simply never fires).
					[
						'event' => self::SYSTEMTAG_EVENT_MAPPER,
						'filter' => [],
					],
				],
			],
			'deck_sync' => [
				'name' => 'Deck Sync',
				'description' => 'Real-time synchronization for Deck cards (create, update, delete) and board updates',
				'app' => 'deck',
				'events' => [
					[
						'event' => self::DECK_EVENT_CARD_CREATED,
						'filter' => [],
					],
					[
						'event' => self::DECK_EVENT_CARD_UPDATED,
						'filter' => [],
					],
					[
						'event' => self::DECK_EVENT_CARD_DELETED,
						'filter' => [],
					],
					[
						'event' => self::DECK_EVENT_BOARD_UPDATED,
						'filter' => [],
					],
				],
			],
		];
	}

	/**
	 * Get a webhook preset by ID.
	 *
	 * @param string $presetId Preset identifier (e.g., "notes_sync", "files_sync")
	 * @return array|null Preset configuration or null if not found
	 */
	public static function getPreset(string $presetId): ?array {
		$presets = self::getPresets();
		return $presets[$presetId] ?? null;
	}

	/**
	 * Decode the admin's enabled-preset id list from its JSON app-config value.
	 *
	 * Shared by the boot-time listener subscription, the runtime listener, and
	 * the admin controller so the decode-and-filter-strings routine can't drift
	 * across those three call sites.
	 *
	 * @return list<string>
	 * @psalm-suppress MixedAssignment decoded JSON values are mixed by nature
	 */
	public static function decodeEnabledPresetIds(string $json): array {
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return [];
		}
		$ids = [];
		foreach ($decoded as $id) {
			if (is_string($id)) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Get list of event class names for a preset.
	 *
	 * @param string $presetId Preset identifier
	 * @return array<string> List of fully qualified event class names
	 */
	public static function getPresetEvents(string $presetId): array {
		$preset = self::getPreset($presetId);
		if ($preset === null) {
			return [];
		}

		return array_map(
			fn ($eventConfig) => $eventConfig['event'],
			$preset['events']
		);
	}

	/**
	 * Filter webhook presets to only show those for installed apps.
	 *
	 * @param array<string> $installedApps List of installed app names
	 * @return array<string, array> Filtered presets
	 */
	public static function filterPresetsByInstalledApps(array $installedApps): array {
		$filtered = [];
		foreach (self::getPresets() as $presetId => $preset) {
			$appName = $preset['app'];
			// "files" is always available (core functionality)
			if ($appName === 'files' || in_array($appName, $installedApps)) {
				$filtered[$presetId] = $preset;
			}
		}
		return $filtered;
	}
}
