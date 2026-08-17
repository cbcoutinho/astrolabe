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
				'Astrolabe authenticates to the MCP server on behalf of the current '
				. "session user. With the 'oidc' app (Nextcloud is the identity "
				. 'provider) it mints a short-lived JWT: register an OIDC client whose '
				. "resource URL matches your MCP server. With the 'user_oidc' app "
				. '(Nextcloud signs users in through an external identity provider such '
				. "as Keycloak) it uses the user's own token from that provider. Either "
				. 'way, enter the client identifier the token is for below.'
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
						"With the 'oidc' app: the identifier of the OIDC client registered "
						. 'there, which Astrolabe mints per-user access tokens against. '
						. "With the 'user_oidc' app: the identifier of the client at your "
						. 'external identity provider that the MCP server accepts tokens for '
						. "(the token exchange's target audience)."
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
						. 'WEBHOOK_SECRET. Use an https:// MCP Server URL — the secret is sent '
						. 'as a bearer token, so an http:// endpoint would transmit it '
						. 'unencrypted. Leave blank to keep the current value unchanged; '
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
