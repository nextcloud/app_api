/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Load the picker scripts of ExApp reference providers.
 *
 * Each ExApp script registers its custom picker element with
 * `window._registerCustomPickerElement(providerId, ...)`. Before the script runs, the base URL of its
 * ExApp is published in `window.OCA.AppAPI.referencePickers[providerId].baseUrl`, and the script tag
 * carries `data-base-url` and `data-provider-id` (readable via `document.currentScript.dataset`).
 * The base URL is `/exapps/<appid>` with HaRP, otherwise the AppAPI PHP proxy.
 *
 * @param {Array<{appid: string, provider_id: string, base_url: string, script_url: string}>} pickers from initial state
 * @param {Window} win window to publish the base URLs on
 * @param {Document} doc document to add the script tags to
 */
export function loadExAppPickers(pickers, win, doc) {
	win.OCA ??= {}
	win.OCA.AppAPI ??= {}
	win.OCA.AppAPI.referencePickers ??= {}
	const registry = win.OCA.AppAPI.referencePickers

	const loaded = new Set()
	for (const picker of pickers) {
		registry[picker.provider_id] = { appId: picker.appid, baseUrl: picker.base_url }
		if (loaded.has(picker.script_url)) {
			continue
		}
		loaded.add(picker.script_url)

		// AppAPI validates script paths on registration. Rows stored before that, or a bad base URL, must
		// still not load a script from another ExApp or another origin.
		if (!isInsideExApp(picker.script_url, picker.base_url, win)) {
			continue
		}

		const script = doc.createElement('script')
		script.src = picker.script_url
		script.async = false
		script.dataset.appId = picker.appid
		script.dataset.providerId = picker.provider_id
		script.dataset.baseUrl = picker.base_url
		doc.head.appendChild(script)
	}
}

/**
 * Whether `scriptUrl` resolves (as the browser will, after `..` and encoding) to a path under `baseUrl`
 * on the page's own origin.
 *
 * @param {string} scriptUrl the script URL
 * @param {string} baseUrl the ExApp base URL
 * @param {Window} win window giving the page origin
 */
export function isInsideExApp(scriptUrl, baseUrl, win) {
	try {
		const origin = win.location.origin
		const script = new URL(scriptUrl, win.location.href)
		const base = new URL(baseUrl.replace(/\/*$/, '/'), win.location.href)
		return script.origin === origin && base.origin === origin && script.pathname.startsWith(base.pathname)
	} catch {
		return false
	}
}
