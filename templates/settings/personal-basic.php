<?php
/**
 * Personal settings template for single-user BasicAuth mode.
 *
 * Shows server connection status and vector sync info, but informs the user
 * that full Astrolabe features (search, visualization, webhooks) require
 * an OAuth-enabled deployment.
 *
 * @var array $_ Template parameters
 * @var string $_['userId'] Current user ID
 * @var string $_['serverUrl'] MCP server URL
 * @var array $_['serverStatus'] Server status from API
 * @var string $_['authMode'] Authentication mode ('basic')
 * @var bool $_['vectorSyncEnabled'] Whether vector sync is enabled
 */

use OCP\Util;

Util::addStyle('astrolabe', 'astrolabe-personalSettings');
?>

<div class="section">
	<h2><?php p($l->t('Astrolabe')); ?></h2>
	<p><?php p($l->t('AI-powered semantic search across your Nextcloud content.')); ?></p>
</div>

<div class="section">
	<h2><?php p($l->t('Service Status')); ?></h2>

	<div class="mcp-status-card">
		<p>
			<span class="icon icon-checkmark" style="display: inline-block; vertical-align: middle;"></span>
			<strong><?php p($l->t('Connected')); ?></strong>
		</p>
		<p>
			<strong><?php p($l->t('Version:')); ?></strong>
			<?php p($_['serverStatus']['version'] ?? $l->t('Unknown')); ?>
		</p>
		<p>
			<strong><?php p($l->t('Auth Mode:')); ?></strong>
			<?php p($l->t('Single-User BasicAuth')); ?>
		</p>
		<p>
			<strong><?php p($l->t('Semantic Search:')); ?></strong>
			<?php if ($_['vectorSyncEnabled']): ?>
				<span style="color: var(--color-success); font-weight: 600;"><?php p($l->t('Enabled')); ?></span>
			<?php else: ?>
				<span style="color: var(--color-text-maxcontrast);"><?php p($l->t('Disabled')); ?></span>
			<?php endif; ?>
		</p>
	</div>
</div>

<div class="section">
	<h2><?php p($l->t('Limited Functionality')); ?></h2>

	<div class="notecard notecard-warning">
		<p>
			<?php p($l->t('The MCP server is running in single-user BasicAuth mode. In this mode, Astrolabe can display server status but advanced features are not available.')); ?>
		</p>
	</div>

	<p><?php p($l->t('The following features require an OAuth-enabled deployment:')); ?></p>
	<ul>
		<li><?php p($l->t('Semantic search from Nextcloud')); ?></li>
		<li><?php p($l->t('Vector visualization')); ?></li>
		<li><?php p($l->t('Webhook management')); ?></li>
		<li><?php p($l->t('Background content indexing')); ?></li>
	</ul>

	<p>
		<?php p($l->t('To enable these features, configure the MCP server with OAuth support (multi-user BasicAuth with offline access, or full OAuth mode).')); ?>
	</p>

	<p>
		<a href="https://github.com/cbcoutinho/nextcloud-mcp-server/blob/master/docs/configuration.md" target="_blank" rel="noopener noreferrer" class="button">
			<?php p($l->t('Configuration Guide')); ?>
		</a>
	</p>
</div>
