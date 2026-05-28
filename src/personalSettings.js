/**
 * Personal settings page JavaScript for Astrolabe.
 *
 * Handles the one-click background-indexing opt-in (provision + revoke).
 * Provisioning mints a dedicated app password from the current Nextcloud
 * session via the core `getapppassword` OCS endpoint and hands it to the
 * MCP server — the user never copies or pastes a credential. Search itself
 * is handled by App.vue and the unified search provider; this script does
 * not touch any OAuth flow.
 */

import './styles/settings.css'

document.addEventListener('DOMContentLoaded', function() {
	function showError(message) {
		if (typeof OC !== 'undefined' && OC.Notification) {
			OC.Notification.showTemporary(message, { type: 'error' })
		} else {
			alert(message)
		}
	}

	function showSuccess(message) {
		if (typeof OC !== 'undefined' && OC.Notification) {
			OC.Notification.showTemporary(message, { type: 'success' })
		} else {
			alert(message)
		}
	}

	// Mint a dedicated app password from the current session (no copy-paste)
	// and hand it to the MCP server via the existing storeAppPassword endpoint.
	async function mintSessionAppPassword() {
		// core/getapppassword exchanges the active *login* session for a fresh
		// app password. Requires the OCS-APIRequest header + CSRF token.
		const ocsBase = OC.linkToOCS('core', 2) // -> <webroot>/ocs/v2.php/core/
		const resp = await fetch(ocsBase + 'getapppassword', {
			method: 'GET',
			headers: {
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
				requesttoken: OC.requestToken,
			},
		})
		if (!resp.ok) {
			throw new Error('getapppassword returned ' + resp.status)
		}
		const data = await resp.json()
		const appPassword = data?.ocs?.data?.apppassword
		if (!appPassword) {
			throw new Error('getapppassword response missing apppassword')
		}
		return appPassword
	}

	const enableButton = document.getElementById('mcp-enable-background-button')
	if (enableButton) {
		enableButton.addEventListener('click', async function() {
			const originalText = enableButton.textContent
			const storeUrl = enableButton.dataset.storeUrl

			try {
				enableButton.disabled = true
				enableButton.textContent = t('astrolabe', 'Enabling...')

				const appPassword = await mintSessionAppPassword()

				const formData = new FormData()
				formData.append('appPassword', appPassword)
				const response = await fetch(storeUrl, {
					method: 'POST',
					headers: { requesttoken: OC.requestToken },
					body: formData,
				})

				const result = await response.json()

				if (response.ok && result.success) {
					showSuccess(t('astrolabe', 'Background indexing enabled.'))
					setTimeout(() => window.location.reload(), 1000)
				} else {
					showError(result.error || t('astrolabe', 'Failed to enable background indexing.'))
				}
			} catch (error) {
				console.error('Background indexing provisioning error:', error)
				showError(t('astrolabe', 'Could not enable background indexing. Please try again.'))
			} finally {
				enableButton.disabled = false
				enableButton.textContent = originalText
			}
		})
	}

	const revokeBackgroundForm = document.getElementById('mcp-revoke-background-form')
	if (revokeBackgroundForm) {
		revokeBackgroundForm.addEventListener('submit', async function(e) {
			e.preventDefault()

			if (!confirm(t('astrolabe', 'Disable background indexing? The MCP server will lose access to your Nextcloud files.'))) {
				return
			}

			const submitButton = revokeBackgroundForm.querySelector('button[type="submit"]')
			const originalText = submitButton.textContent

			try {
				submitButton.disabled = true
				submitButton.textContent = t('astrolabe', 'Disabling...')

				const formData = new FormData(revokeBackgroundForm)
				const response = await fetch(revokeBackgroundForm.action, {
					method: 'POST',
					body: formData,
				})

				const result = await response.json()

				if (response.ok && result.success) {
					showSuccess(t('astrolabe', 'Background indexing disabled.'))
					setTimeout(() => window.location.reload(), 1000)
				} else {
					showError(result.error || t('astrolabe', 'Failed to disable background indexing.'))
				}
			} catch (error) {
				console.error('Revoke error:', error)
				showError(t('astrolabe', 'Unable to connect to server. Your access may already be revoked, or the server may be down.'))
			} finally {
				submitButton.disabled = false
				submitButton.textContent = originalText
			}
		})
	}
})
