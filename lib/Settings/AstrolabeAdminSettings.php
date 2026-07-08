<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Settings;

use OCP\IL10N;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

class AstrolabeAdminSettings implements IDeclarativeSettingsForm {
	public function __construct(
		private IL10N $l,
	) {
	}

	public function getSchema(): array {
		return [
			'id' => 'astrolabe-admin-settings',
			'priority' => 10,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'astrolabe',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => $this->l->t('MCP Server Configuration'),
			'description' => $this->l->t(
				'Astrolabe authenticates to the MCP server with short-lived JWTs '
				. "minted by the Nextcloud 'oidc' app for the current session user. "
				. "Register an OIDC client in the 'oidc' app whose resource URL "
				. 'matches your MCP server, then enter its client ID below.'
			),
			'doc_url' => 'https://github.com/cbcoutinho/nextcloud-mcp-server',

			'fields' => [
				[
					'id' => 'mcp_server_url',
					'title' => $this->l->t('MCP Server URL (internal)'),
					'description' => $this->l->t(
						'Base URL Astrolabe uses to reach the MCP server (e.g. http://localhost:8000). '
						. 'Use https:// when background indexing is enabled — the user app password '
						. 'is sent to this URL, so an http:// endpoint would transmit it unencrypted.'
					),
					'type' => DeclarativeSettingsTypes::URL,
					'placeholder' => 'http://localhost:8000',
					'default' => '',
				],
				[
					'id' => 'mcp_server_public_url',
					'title' => $this->l->t('MCP Server URL (public)'),
					'description' => $this->l->t(
						'Public URL the MCP server is reachable at from the outside world. '
						. 'Used as the `aud` claim of minted access tokens (RFC 8707 resource indicator). '
						. 'Leave empty to use the internal URL above.'
					),
					'type' => DeclarativeSettingsTypes::URL,
					'placeholder' => 'https://mcp.example.com',
					'default' => '',
				],
				[
					'id' => 'astrolabe_client_id',
					'title' => $this->l->t('OIDC client identifier'),
					'description' => $this->l->t(
						"Identifier of the OIDC client registered in the Nextcloud 'oidc' app. "
						. "Astrolabe dispatches OIDCIdentityProvider's TokenGenerationRequestEvent "
						. 'against this client to mint per-user access tokens for the MCP server.'
					),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => $this->l->t('Enter OIDC client ID'),
					'default' => '',
				],
				[
					'id' => 'mcp_webhook_secret',
					'title' => $this->l->t('Webhook shared secret'),
					'description' => $this->l->t(
						'Shared secret Astrolabe sends when delivering change events to the '
						. "MCP server's webhook ingress. Must match the MCP server's "
						. 'WEBHOOK_SECRET. Leave blank to keep the current value unchanged; '
						. 'the stored value is never displayed here.'
					),
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'placeholder' => $this->l->t('Enter webhook secret'),
					'default' => '',
				],
			],
		];
	}
}
