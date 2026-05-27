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
	<?php else: ?>
		<div class="mcp-grant-section">
			<p><strong><?php p($l->t('Step 1:')); ?></strong>
				<a href="<?php p($urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'security'])); ?>" target="_blank">
					<?php p($l->t('Generate an app password in Security settings')); ?>
				</a>
			</p>

			<p><strong><?php p($l->t('Step 2:')); ?></strong> <?php p($l->t('Enter the app password below:')); ?></p>

			<form method="post" action="<?php p($urlGenerator->linkToRoute('astrolabe.credentials.storeAppPassword')); ?>" id="mcp-app-password-form">
				<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
				<div class="mcp-input-group">
					<input type="password" name="appPassword" id="mcp-app-password-input"
						   placeholder="xxxxx-xxxxx-xxxxx-xxxxx-xxxxx"
						   pattern="[a-zA-Z0-9]{5}-[a-zA-Z0-9]{5}-[a-zA-Z0-9]{5}-[a-zA-Z0-9]{5}-[a-zA-Z0-9]{5}"
						   required>
					<button type="submit" class="button primary" id="mcp-save-app-password-button">
						<span class="icon icon-checkmark"></span>
						<?php p($l->t('Save')); ?>
					</button>
				</div>
				<p class="mcp-help-text">
					<?php p($l->t('The app password is validated against Nextcloud, then sent to the MCP server and stored encrypted in your user preferences.')); ?>
				</p>
			</form>
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
