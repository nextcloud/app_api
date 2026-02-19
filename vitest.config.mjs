/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import vue from '@vitejs/plugin-vue'
import { resolve } from 'node:path'
import { defineConfig } from 'vitest/config'

export default defineConfig({
	plugins: [vue()],
	test: {
		include: ['src/**/*.{test,spec}.{js,ts}'],
		environment: 'jsdom',
		environmentOptions: {
			jsdom: {
				url: 'http://nextcloud.local',
			},
		},
		setupFiles: [
			resolve(import.meta.dirname, 'src/__tests__/setup.js'),
		],
		coverage: {
			include: ['src/**'],
			exclude: ['**/*.spec.*', '**/*.test.*', 'src/__tests__/**'],
			provider: 'istanbul',
			reporter: ['lcov', 'text'],
		},
		server: {
			deps: {
				inline: [/@nextcloud\//],
			},
		},
	},
})
