<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Table backing the Assistant agent's conversation memory.
 *
 * `core:contextagent:interaction` is stateless on the wire: each turn carries an
 * opaque `conversation_token` and nothing else, so the provider has to store the
 * history itself and hand back a token that finds it again.
 *
 * The token is the lookup key and arrives from the client, so it is generated
 * with a CSPRNG and every read is additionally filtered by `user_id` — a guessed
 * token must not surface another user's conversation.
 *
 * @psalm-suppress UnusedClass — discovered and run by the migration framework.
 */
final class Version0390Date20260725220000CreateAgentConversations extends SimpleMigrationStep {
	#[\Override]
	public function name(): string {
		return 'Create the Astrolabe agent conversation table';
	}

	#[\Override]
	public function description(): string {
		return 'Stores per-user agent chat history keyed by an opaque conversation token.';
	}

	/**
	 * @param \Closure(): ISchemaWrapper $schemaClosure
	 * @param array<array-key, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('astrolabe_agent_conv')) {
			return null;
		}

		$table = $schema->createTable('astrolabe_agent_conv');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 20,
		]);
		$table->addColumn('token', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		// The turn history as JSON. Text rather than a child table: it is only
		// ever read and written whole, and never queried across rows.
		$table->addColumn('history', Types::TEXT, [
			'notnull' => true,
			'default' => '[]',
		]);
		$table->addColumn('updated_at', Types::INTEGER, [
			'notnull' => true,
			'default' => 0,
		]);

		/** @psalm-suppress DeprecatedMethod — the replacement is not in the OCP surface. */
		$table->setPrimaryKey(['id']);
		// Unique on the token alone so a collision cannot create a second row,
		// while lookups still filter on user_id as the authorisation check.
		$table->addUniqueIndex(['token'], 'astrolabe_agent_token');
		// Supports the expiry sweep.
		$table->addIndex(['updated_at'], 'astrolabe_agent_updated');

		return $schema;
	}
}
