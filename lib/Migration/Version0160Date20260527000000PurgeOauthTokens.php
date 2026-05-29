<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * Drop legacy OAuth-token rows from `oc_preferences`.
 *
 * Astrolabe used to persist encrypted OAuth access + refresh tokens
 * under `(appid='astrolabe', configkey='oauth_tokens')`. With the
 * session-derived JWT refactor (PR introducing McpTokenMinter),
 * astrolabe no longer needs or uses these rows. Background-indexing
 * app passwords under `background_sync_password` /
 * `background_sync_type` / `background_sync_provisioned_at` are
 * intentionally left in place — they remain the MCP server's credential
 * for fetching user files via WebDAV.
 *
 * @psalm-suppress UnusedClass — discovered + run by the Nextcloud migration framework.
 */
class Version0160Date20260527000000PurgeOauthTokens extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	public function name(): string {
		return 'Purge legacy Astrolabe OAuth tokens';
	}

	public function description(): string {
		return 'Removes oc_preferences rows for the obsolete astrolabe '
			. '`oauth_tokens` configkey. Astrolabe now mints short-lived '
			. 'access tokens on demand from the Nextcloud `oidc` app.';
	}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// No-op — schema is unchanged.
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// Returning null keeps the schema unchanged.
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('astrolabe')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('oauth_tokens')));

		$deleted = $qb->executeStatement();

		$msg = "Purged $deleted legacy astrolabe oauth_tokens row(s)";
		$output->info($msg);
		$this->logger->info($msg);
	}
}
