/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { recommendedJavascript } from '@nextcloud/eslint-config'

export default [
	...recommendedJavascript,

	{
		name: 'app_api/ignores',
		ignores: ['**/__snapshots__/'],
	},

	{
		name: 'app_api/rules',
		files: ['**/*.js', '**/*.vue'],
		rules: {
			'jsdoc/require-jsdoc': 'off',
			'no-console': 'off',
			// Sorting would change the module evaluation order of the bundles
			'perfectionist/sort-imports': 'off',
		},
	},

	{
		name: 'app_api/vue-rules',
		files: ['**/*.vue'],
		rules: {
			'vue/first-attribute-linebreak': 'off',
			// These need changes to component props and emits, not just formatting
			'vue/no-required-prop-with-default': 'off',
			'vue/no-unused-properties': 'off',
			'vue/no-unused-refs': 'off',
			'vue/require-explicit-emits': 'off',
		},
	},
]
