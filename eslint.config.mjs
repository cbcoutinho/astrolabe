import { recommendedJavascript } from '@nextcloud/eslint-config'

export default [
	...recommendedJavascript,
	{
		rules: {
			'jsdoc/require-jsdoc': 'off',
			'vue/first-attribute-linebreak': 'off',
			// The app only logs via console.error/warn; keep those, forbid console.log.
			'no-console': ['error', { allow: ['error', 'warn'] }],
			// pdf.js's modern build calls JS APIs only the newest engines have
			// (Map.prototype.getOrInsertComputed); elsewhere no PDF loads at
			// all. Its legacy build ships the polyfills: use only that.
			'no-restricted-imports': ['error', {
				patterns: [{
					regex: '^pdfjs-dist(/build/.*)?$',
					message: 'Import pdf.js from pdfjs-dist/legacy/build/ (the modern build breaks on all but the newest browsers).',
				}],
			}],
		},
	},
]
