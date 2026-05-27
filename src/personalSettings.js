/**
 * Personal settings page JavaScript for Astrolabe.
 *
 * Handles the two app-password forms (provision + revoke). Search itself
 * is handled by App.vue and the unified search provider; this script
 * does not touch any OAuth flow.
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

	const appPasswordForm = document.getElementById('mcp-app-password-form')
	if (appPasswordForm) {
		appPasswordForm.addEventListener('submit', async function(e) {
			e.preventDefault()
			const submitButton = document.getElementById('mcp-save-app-password-button')
			const originalText = submitButton.textContent

			try {
				submitButton.disabled = true
				submitButton.textContent = t('astrolabe', 'Saving...')

				const formData = new FormData(appPasswordForm)
				const response = await fetch(appPasswordForm.action, {
					method: 'POST',
					body: formData,
				})

				const result = await response.json()

				if (response.ok && result.success) {
					showSuccess(t('astrolabe', 'Background indexing enabled.'))
					setTimeout(() => window.location.reload(), 1000)
				} else {
					showError(result.error || t('astrolabe', 'Failed to save app password. Please check that it is valid.'))
				}
			} catch (error) {
				console.error('App password provisioning error:', error)
				showError(t('astrolabe', 'Unable to connect to server. Please check that the MCP server is running and try again.'))
			} finally {
				submitButton.disabled = false
				submitButton.textContent = originalText
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
