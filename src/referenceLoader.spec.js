/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it } from 'vitest'
import { loadExAppPickers } from './referenceLoader.js'

describe('loadExAppPickers', () => {
	let win

	beforeEach(() => {
		document.head.innerHTML = ''
		win = { location: { origin: 'http://cloud.test', href: 'http://cloud.test/index.php/apps/text/' } }
	})

	const picker = (appid, providerId, scriptUrl) => ({
		appid,
		provider_id: providerId,
		base_url: '/exapps/' + appid,
		script_url: scriptUrl,
	})

	it('publishes base URLs before the scripts are added', () => {
		loadExAppPickers([picker('app1', 'p1', '/exapps/app1/js/a.js')], win, document)

		expect(win.OCA.AppAPI.referencePickers.p1).toEqual({ appId: 'app1', baseUrl: '/exapps/app1' })
		const script = document.head.querySelector('script')
		expect(script.getAttribute('src')).toBe('/exapps/app1/js/a.js')
		expect(script.dataset.providerId).toBe('p1')
		expect(script.dataset.baseUrl).toBe('/exapps/app1')
		expect(script.async).toBe(false)
	})

	it('adds a script shared by two providers only once, but registers both', () => {
		loadExAppPickers([
			picker('app1', 'p1', '/exapps/app1/js/a.js'),
			picker('app1', 'p2', '/exapps/app1/js/a.js'),
		], win, document)

		expect(document.head.querySelectorAll('script')).toHaveLength(1)
		expect(Object.keys(win.OCA.AppAPI.referencePickers)).toEqual(['p1', 'p2'])
	})

	it('keeps existing OCA namespaces', () => {
		win.OCA = { Files: { x: 1 } }
		loadExAppPickers([], win, document)
		expect(win.OCA.Files).toEqual({ x: 1 })
		expect(win.OCA.AppAPI.referencePickers).toEqual({})
	})

	it.each([
		['parent segment', '/exapps/app1/../app2/js/x.js'],
		['encoded parent segment', '/exapps/app1/js/%2e%2e/%2e%2e/app2/x.js'],
		['prefix of another app id', '/exapps/app10/js/x.js'],
		['protocol-relative', '//evil.test/x.js'],
		['other origin', 'https://evil.test/exapps/app1/x.js'],
		['javascript URL', 'javascript:alert(1)'],
	])('does not add a script that resolves outside the ExApp: %s', (_, scriptUrl) => {
		loadExAppPickers([picker('app1', 'p1', scriptUrl)], win, document)

		expect(document.head.querySelectorAll('script')).toHaveLength(0)
	})
})
