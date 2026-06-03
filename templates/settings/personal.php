<?php
/**
 * Personal settings template for Astrolabe.
 *
 * @var array $_ Template parameters
 * @var string $_['userId'] Current user ID
 * @var array $_['serverStatus'] Server status from MCP server /api/v1/status
 * @var bool $_['vectorSyncEnabled'] Whether vector sync is enabled on the MCP server
 * @var bool $_['hasBackgroundAccess'] Whether this user has provisioned an app password
 * @var int|null $_['backgroundSyncProvisionedAt'] Unix ts of last provisioning, or null
 * @var string $_['serverUrl'] Public MCP server URL (for display)
 * @var bool $_['allowUserSelfProvision'] Whether users may self-provision (admin toggle)
 * @var string $_['requesttoken'] CSRF token
 */

$urlGenerator = \OC::$server->getURLGenerator();

script('astrolabe', 'astrolabe-personalSettings');
style('astrolabe', 'astrolabe-main');
?>

<div class="section">
	<h2><?php p($l->t('Astrolabe')); ?></h2>
	<p><?php p($l->t('AI-powered semantic search across your Nextcloud content. Find documents by meaning, not just keywords.')); ?></p>
</div>

<div class="section">
	<h2><?php p($l->t('Service Status')); ?></h2>
	<table class="mcp-info-table">
		<tr>
			<td><?php p($l->t('Service URL')); ?></td>
			<td><code><?php p($_['serverUrl']); ?></code></td>
		</tr>
		<tr>
			<td><?php p($l->t('Version')); ?></td>
			<td><?php p($_['serverStatus']['version'] ?? 'Unknown'); ?></td>
		</tr>
	</table>
</div>

<div class="section">
	<h2><?php p($l->t('Background Indexing')); ?></h2>
	<p class="mcp-help-text">
		<?php p($l->t('Search itself uses your active Nextcloud session — no authorization step is needed. To have the MCP server index your files in the background, provide an app password it can use to read your files via WebDAV.')); ?>
	</p>

	<?php if (!empty($_['hasBackgroundAccess'])): ?>
		<div class="mcp-background-status">
			<p>
				<span class="badge badge-success">
					<span class="icon icon-checkmark-white"></span>
					<?php p($l->t('Background indexing enabled')); ?>
				</span>
			</p>
			<?php if ($_['backgroundSyncProvisionedAt']): ?>
				<table class="mcp-info-table">
					<tr>
						<td><?php p($l->t('Provisioned at')); ?></td>
						<td><?php p(date('c', $_['backgroundSyncProvisionedAt'])); ?></td>
					</tr>
				</table>
			<?php endif; ?>
			<div class="mcp-revoke-section">
				<form method="post" action="<?php p($urlGenerator->linkToRoute('astrolabe.credentials.deleteCredentials')); ?>" id="mcp-revoke-background-form">
					<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
					<button type="submit" class="button warning" id="mcp-revoke-background-button">
						<span class="icon icon-delete"></span>
						<?php p($l->t('Disable background indexing')); ?>
					</button>
					<p class="mcp-help-text">
						<?php p($l->t('The MCP server will lose access to your Nextcloud files. Existing indexed content remains searchable until it next reconciles.')); ?>
					</p>
				</form>
			</div>
		</div>
	<?php elseif (empty($_['allowUserSelfProvision'])): ?>
		<div class="mcp-grant-section">
			<p class="mcp-help-text">
				<?php p($l->t('Background indexing is managed by your administrator. Contact them to have it enabled for your account.')); ?>
			</p>
		</div>
	<?php else: ?>
		<div class="mcp-grant-section">
			<button type="button" class="button primary" id="mcp-enable-background-button"
					data-store-url="<?php p($urlGenerator->linkToRoute('astrolabe.credentials.storeAppPassword')); ?>">
				<span class="icon icon-checkmark"></span>
				<?php p($l->t('Enable background indexing')); ?>
			</button>
			<p class="mcp-help-text">
				<?php p($l->t('One click generates a dedicated app password from your current session, sends it to the MCP server, and stores it encrypted in your user preferences. No copy-paste needed — you can disable it again at any time.')); ?>
			</p>
		</div>
	<?php endif; ?>
</div>

<?php if ($_['vectorSyncEnabled']): ?>
<div class="section">
	<h2><?php p($l->t('Search your content')); ?></h2>
	<p><?php p($l->t('Use natural language to search across your Notes, Files, Calendar, and Deck cards. Ask questions like "meeting notes from last week" or "recipes with chicken".')); ?></p>
	<a href="<?php p($urlGenerator->linkToRoute('astrolabe.page.index')); ?>" class="button primary">
		<span class="icon icon-search"></span>
		<?php p($l->t('Open Astrolabe')); ?>
	</a>
</div>
<?php else: ?>
<div class="section">
	<h2><?php p($l->t('Semantic search')); ?></h2>
	<p>
		<?php p($l->t('Semantic search is not enabled on this server. Contact your administrator to enable this feature.')); ?>
	</p>
</div>
<?php endif; ?>
