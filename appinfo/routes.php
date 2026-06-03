<?php

declare(strict_types=1);

/**
 * Routes configuration for the Astrolabe app.
 *
 * Astrolabe authenticates to the MCP server with short-lived JWTs minted
 * on demand from the current Nextcloud session (McpTokenMinter), so
 * there are no longer any OAuth authorize / callback / refresh routes.
 * The only persisted per-user credential is the Nextcloud app password
 * used by the MCP server for background WebDAV indexing; that is
 * provisioned via the CredentialsController routes below.
 */

return [
	'routes' => [
		// Background sync credentials (app password for MCP server WebDAV access)
		[
			'name' => 'credentials#storeAppPassword',
			'url' => '/api/v1/background-sync/credentials',
			'verb' => 'POST',
		],
		[
			'name' => 'credentials#getCredentials',
			'url' => '/api/v1/background-sync/credentials/{userId}',
			'verb' => 'GET',
		],
		[
			'name' => 'credentials#deleteCredentials',
			'url' => '/api/v1/background-sync/credentials/revoke',
			'verb' => 'POST',
		],
		[
			'name' => 'credentials#getStatus',
			'url' => '/api/v1/background-sync/status',
			'verb' => 'GET',
		],

		// Admin provisioning (admin-only via SecurityMiddleware — these methods
		// carry no #[NoAdminRequired] attribute)
		[
			'name' => 'credentials#adminListProvisioning',
			'url' => '/api/v1/background-sync/admin/users',
			'verb' => 'GET',
		],
		[
			'name' => 'credentials#adminProvisionUser',
			'url' => '/api/v1/background-sync/admin/users/{userId}',
			'verb' => 'POST',
		],
		[
			'name' => 'credentials#adminDeprovisionUser',
			'url' => '/api/v1/background-sync/admin/users/{userId}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'credentials#adminSetSelfProvision',
			'url' => '/api/v1/background-sync/admin/self-provision',
			'verb' => 'POST',
		],

		// Vector search API
		[
			'name' => 'api#search',
			'url' => '/api/search',
			'verb' => 'GET',
		],
		[
			'name' => 'api#vectorStatus',
			'url' => '/api/vector-status',
			'verb' => 'GET',
		],
		[
			'name' => 'api#chunkContext',
			'url' => '/api/chunk-context',
			'verb' => 'GET',
		],
		[
			'name' => 'api#pdfPreview',
			'url' => '/api/pdf-preview',
			'verb' => 'GET',
		],

		// Admin settings
		[
			'name' => 'api#serverStatus',
			'url' => '/api/admin/server-status',
			'verb' => 'GET',
		],
		[
			'name' => 'api#adminVectorStatus',
			'url' => '/api/admin/vector-status',
			'verb' => 'GET',
		],
		[
			'name' => 'api#saveSearchSettings',
			'url' => '/api/admin/search-settings',
			'verb' => 'POST',
		],

		// Webhook management (admin)
		[
			'name' => 'api#getWebhookPresets',
			'url' => '/api/admin/webhooks/presets',
			'verb' => 'GET',
		],
		[
			'name' => 'api#enableWebhookPreset',
			'url' => '/api/admin/webhooks/presets/{presetId}/enable',
			'verb' => 'POST',
		],
		[
			'name' => 'api#disableWebhookPreset',
			'url' => '/api/admin/webhooks/presets/{presetId}/disable',
			'verb' => 'POST',
		],
	],
];
