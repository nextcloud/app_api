/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import '@testing-library/jest-dom/vitest'

// Mock Nextcloud globals
window.OC = {
	config: { version: '32.0.0' },
}
window.OCA = {}
window.OCP = {}
window._oc_webroot = ''

window.t = (app, text, vars) => {
	if (vars) {
		return Object.entries(vars).reduce(
			(result, [key, value]) => result.replace(`{${key}}`, value),
			text,
		)
	}
	return text
}

window.n = (app, singular, plural, count, vars) => {
	const text = count === 1 ? singular : plural
	if (vars) {
		return Object.entries(vars).reduce(
			(result, [key, value]) => result.replace(`{${key}}`, value),
			text,
		)
	}
	return text
}
