import { recommendedJavascript } from '@nextcloud/eslint-config'

export default [
	...recommendedJavascript,
	{
		rules: {
			'jsdoc/require-jsdoc': 'off',
			'vue/first-attribute-linebreak': 'off',
			// The app only logs via console.error/warn; keep those, forbid console.log.
			'no-console': ['error', { allow: ['error', 'warn'] }],
		},
	},
]
