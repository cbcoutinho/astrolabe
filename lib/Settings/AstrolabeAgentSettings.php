<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Settings;

use OCP\IL10N;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

/**
 * Admin controls for the Assistant agent.
 *
 * Separate from the MCP connection form because these are stored in **app**
 * config rather than system config, and `STORAGE_TYPE_INTERNAL` lets Nextcloud
 * persist them directly — the connection form needs a listener only because it
 * writes system config.
 *
 * @psalm-suppress UnusedClass — registered via IRegistrationContext.
 */
final class AstrolabeAgentSettings implements IDeclarativeSettingsForm {
	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private IL10N $l,
	) {
	}

	#[\Override]
	public function getSchema(): array {
		return [
			'id' => 'astrolabe-agent-settings',
			'priority' => 20,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'astrolabe',
			// External, and persisted by AstrolabeAdminSettingsListener, even though
			// these are plain app-config values that Nextcloud could store itself.
			// DeclarativeManager::getStorageType() scans an app's schemas in order
			// and returns the *first* schema's storage type once a field is not
			// found in it, so a second form declaring a different type has that
			// type ignored — every field here would resolve as external anyway and
			// then fail for want of a handler.
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => $this->l->t('Assistant agent'),
			'description' => $this->l->t(
				'Lets the Nextcloud Assistant answer from this user\'s own content by '
				. 'searching it through the MCP server. Turning this on makes Astrolabe '
				. 'the backend for the Assistant\'s "Chat with AI"; if the Context Agent '
				. 'app is also installed, choose between them under Artificial Intelligence. '
				. 'The agent can only read.'
			),

			'fields' => [
				[
					'id' => Admin::SETTING_AGENT_ENABLED,
					'title' => $this->l->t('Answer Assistant chats with Astrolabe'),
					'description' => $this->l->t(
						'Off by default. Requires a text-generation provider that supports '
						. 'tool calling, such as the OpenAI/LocalAI integration.'
					),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => Admin::DEFAULT_AGENT_ENABLED,
				],
				[
					'id' => Admin::SETTING_AGENT_SCOPES,
					'title' => $this->l->t('Permissions requested for the agent'),
					'description' => $this->l->t(
						'Space-separated scopes. The MCP server hides any tool these do not '
						. 'grant, so this is the real limit on what the agent can do. Read-only '
						. 'by default: adding a write scope would let the assistant change '
						. 'content without asking first.'
					),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => Admin::DEFAULT_AGENT_SCOPES,
					'default' => Admin::DEFAULT_AGENT_SCOPES,
				],
				[
					'id' => Admin::SETTING_AGENT_MAX_ITERATIONS,
					'title' => $this->l->t('Maximum tool rounds per message'),
					'description' => $this->l->t(
						'How many times the assistant may search before it must answer. '
						. 'Each round costs a model call.'
					),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => Admin::DEFAULT_AGENT_MAX_ITERATIONS,
				],
				[
					'id' => Admin::SETTING_AGENT_TIMEOUT,
					'title' => $this->l->t('Time limit per message (seconds)'),
					'description' => $this->l->t(
						'Answers with whatever it has once this elapses, and says that it '
						. 'stopped early. Raise it for slower models.'
					),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => Admin::DEFAULT_AGENT_TIMEOUT,
				],
			],
		];
	}

}
