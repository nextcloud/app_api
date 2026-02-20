/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { registerFileAction } from '@nextcloud/files'
import {
	buildNodeInfo,
	generateAppAPIProxyUrl,
	generateExAppUIPageUrl,
	isSvgContentType,
	loadExAppInlineSvgIcon,
	loadStaticAppAPIInlineSvgIcon,
	mimeMatches,
	registerFileAction33,
	themeInlineSvg,
} from './filesplugin.js'

// vi.mock calls are hoisted above imports by vitest
vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn(() => ({
		instanceId: 'test-instance-id',
		fileActions: [],
	})),
}))

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((path) => `/nextcloud${path}`),
}))

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: vi.fn(() => ({ uid: 'testuser' })),
}))

vi.mock('@nextcloud/files', () => ({
	registerFileAction: vi.fn(),
}))

vi.mock('@nextcloud/l10n', () => ({
	translate: vi.fn((app, text) => text),
}))

describe('mimeMatches', () => {
	it('matches exact MIME type', () => {
		expect(mimeMatches('image/jpeg', 'image/jpeg')).toBe(true)
	})

	it('matches partial MIME (top-level type)', () => {
		expect(mimeMatches('image/jpeg', 'image')).toBe(true)
	})

	it('does not match unrelated MIME type', () => {
		expect(mimeMatches('image/jpeg', 'video/mp4')).toBe(false)
	})

	it('matches one of multiple comma-separated types', () => {
		expect(mimeMatches('image/png', 'image/jpeg, image/png')).toBe(true)
	})

	it('does not match any in comma-separated list', () => {
		expect(mimeMatches('application/pdf', 'image/jpeg, image/png')).toBe(false)
	})

	it('handles extra whitespace in comma-separated list', () => {
		expect(mimeMatches('video/mp4', '  video/mp4 ,  image/png  ')).toBe(true)
	})

	it('handles trailing comma', () => {
		expect(mimeMatches('image/jpeg', 'image/jpeg,')).toBe(true)
	})

	it('matches broad category against specific mime', () => {
		expect(mimeMatches('video/mp4', 'video')).toBe(true)
	})
})

describe('isSvgContentType', () => {
	it('returns true for SVG content types', () => {
		expect(isSvgContentType('image/svg+xml')).toBe(true)
		expect(isSvgContentType('image/svg+xml; charset=utf-8')).toBe(true)
		expect(isSvgContentType('Image/SVG+XML')).toBe(true)
	})

	it('returns false for non-SVG or missing content types', () => {
		expect(isSvgContentType('image/png')).toBe(false)
		expect(isSvgContentType(null)).toBe(false)
		expect(isSvgContentType('')).toBe(false)
	})
})

describe('themeInlineSvg', () => {
	it('adds dark mode filter to SVG', () => {
		const svg = '<svg xmlns="http://www.w3.org/2000/svg"><circle r="10"/></svg>'
		const result = themeInlineSvg(svg)
		expect(result).toContain('filter: var(--background-invert-if-dark)')
	})

	it('preserves existing style attribute', () => {
		const svg = '<svg xmlns="http://www.w3.org/2000/svg" style="fill: red;"><circle r="10"/></svg>'
		const result = themeInlineSvg(svg)
		expect(result).toContain('fill: red;')
		expect(result).toContain('filter: var(--background-invert-if-dark)')
	})

	it('adds semicolon between existing style and filter', () => {
		const svg = '<svg xmlns="http://www.w3.org/2000/svg" style="fill: red"><circle r="10"/></svg>'
		const result = themeInlineSvg(svg)
		expect(result).toMatch(/fill: red;\s*filter:/)
	})

	it('returns null for invalid SVG', () => {
		const result = themeInlineSvg('not valid svg at all')
		expect(result).toBeNull()
	})

	it('returns null for malformed XML', () => {
		const result = themeInlineSvg('<svg><unclosed')
		expect(result).toBeNull()
	})
})

describe('generateAppAPIProxyUrl', () => {
	it('generates correct proxy URL', () => {
		const url = generateAppAPIProxyUrl('my_app', 'api/v1/action')
		expect(url).toBe('/nextcloud/apps/app_api/proxy/my_app/api/v1/action')
	})
})

describe('generateExAppUIPageUrl', () => {
	it('generates correct embedded UI page URL', () => {
		const url = generateExAppUIPageUrl('my_app', 'index')
		expect(url).toBe('/nextcloud/apps/app_api/embedded/my_app/index')
	})
})

describe('buildNodeInfo', () => {
	const mockNode = {
		fileid: 42,
		basename: 'photo.jpg',
		dirname: '/Photos',
		attributes: {
			etag: 'abc123',
			favorite: 1,
			shareTypes: [0],
			shareAttributes: null,
			sharePermissions: 31,
			ownerDisplayName: 'Test User',
			ownerId: 'testuser',
		},
		mime: 'image/jpeg',
		permissions: 27,
		type: 'file',
		size: 1024,
		mtime: new Date('2025-01-15T12:00:00Z'),
	}

	it('maps node properties to API payload', () => {
		const info = buildNodeInfo(mockNode)
		expect(info).toEqual({
			fileId: 42,
			name: 'photo.jpg',
			directory: '/Photos',
			etag: 'abc123',
			mime: 'image/jpeg',
			favorite: 'true',
			permissions: 27,
			fileType: 'file',
			size: 1024,
			mtime: new Date('2025-01-15T12:00:00Z').getTime() / 1000,
			shareTypes: [0],
			shareAttributes: null,
			sharePermissions: 31,
			shareOwner: 'Test User',
			shareOwnerId: 'testuser',
			userId: 'testuser',
			instanceId: 'test-instance-id',
		})
	})

	it('converts favorite to string "false" when 0', () => {
		const node = { ...mockNode, attributes: { ...mockNode.attributes, favorite: 0 } }
		const info = buildNodeInfo(node)
		expect(info.favorite).toBe('false')
	})

	it('defaults missing share attributes to null', () => {
		const node = {
			...mockNode,
			attributes: {
				...mockNode.attributes,
				shareTypes: undefined,
				shareAttributes: undefined,
				sharePermissions: undefined,
				ownerDisplayName: undefined,
				ownerId: undefined,
			},
		}
		const info = buildNodeInfo(node)
		expect(info.shareTypes).toBeNull()
		expect(info.shareAttributes).toBeNull()
		expect(info.sharePermissions).toBeNull()
		expect(info.shareOwner).toBeNull()
		expect(info.shareOwnerId).toBeNull()
	})

	it('converts size to number', () => {
		const node = { ...mockNode, size: '2048' }
		const info = buildNodeInfo(node)
		expect(info.size).toBe(2048)
	})

	it('converts mtime to Unix timestamp in seconds', () => {
		const info = buildNodeInfo(mockNode)
		expect(info.mtime).toBe(new Date('2025-01-15T12:00:00Z').getTime() / 1000)
	})
})

describe('loadStaticAppAPIInlineSvgIcon', () => {
	it('returns a valid SVG string', () => {
		const svg = loadStaticAppAPIInlineSvgIcon()
		expect(svg).toContain('<svg')
		expect(svg).toContain('</svg>')
	})

	it('includes dark mode filter', () => {
		const svg = loadStaticAppAPIInlineSvgIcon()
		expect(svg).toContain('var(--background-invert-if-dark)')
	})
})

describe('loadExAppInlineSvgIcon', () => {
	beforeEach(() => {
		vi.mocked(axios.get).mockReset()
	})

	it('returns SVG data when response has SVG content-type', async () => {
		const svgData = '<svg xmlns="http://www.w3.org/2000/svg"><circle r="10"/></svg>'
		vi.mocked(axios.get).mockResolvedValue({
			data: svgData,
			headers: { 'content-type': 'image/svg+xml' },
			status: 200,
		})

		const result = await loadExAppInlineSvgIcon('my_app', 'icon/main')
		expect(result).toBe(svgData)
	})

	it('calls the correct proxy URL', async () => {
		vi.mocked(axios.get).mockResolvedValue({
			data: '<svg></svg>',
			headers: { 'content-type': 'image/svg+xml' },
			status: 200,
		})

		await loadExAppInlineSvgIcon('my_app', 'icon/main')
		expect(axios.get).toHaveBeenCalledWith(
			'/nextcloud/apps/app_api/proxy/my_app/icon/main',
			expect.objectContaining({ responseType: 'text' }),
		)
	})

	it('uses validateStatus that allows 200-299 and 304', async () => {
		vi.mocked(axios.get).mockResolvedValue({
			data: '<svg></svg>',
			headers: { 'content-type': 'image/svg+xml' },
			status: 200,
		})

		await loadExAppInlineSvgIcon('my_app', 'icon/main')
		const config = vi.mocked(axios.get).mock.calls[0][1]
		expect(config.validateStatus(200)).toBe(true)
		expect(config.validateStatus(299)).toBe(true)
		expect(config.validateStatus(304)).toBe(true)
		expect(config.validateStatus(400)).toBe(false)
		expect(config.validateStatus(500)).toBe(false)
	})

	it('returns null when content-type is not SVG', async () => {
		vi.mocked(axios.get).mockResolvedValue({
			data: '<html></html>',
			headers: { 'content-type': 'text/html' },
			status: 200,
		})

		const result = await loadExAppInlineSvgIcon('my_app', 'icon/main')
		expect(result).toBeNull()
	})

	it('returns null when response body is empty', async () => {
		vi.mocked(axios.get).mockResolvedValue({
			data: '',
			headers: { 'content-type': 'image/svg+xml' },
			status: 304,
		})

		const result = await loadExAppInlineSvgIcon('my_app', 'icon/main')
		expect(result).toBeNull()
	})

	it('returns null when response body is null', async () => {
		vi.mocked(axios.get).mockResolvedValue({
			data: null,
			headers: { 'content-type': 'image/svg+xml' },
			status: 304,
		})

		const result = await loadExAppInlineSvgIcon('my_app', 'icon/main')
		expect(result).toBeNull()
	})

	it('returns null on network error', async () => {
		vi.mocked(axios.get).mockRejectedValue({
			response: { status: 500, headers: { 'content-type': 'text/plain' } },
		})

		const result = await loadExAppInlineSvgIcon('my_app', 'icon/main')
		expect(result).toBeNull()
	})
})

describe('registerFileAction33', () => {
	const mockNode = {
		fileid: 42,
		basename: 'photo.jpg',
		dirname: '/Photos',
		attributes: {
			etag: 'abc123',
			favorite: 1,
			shareTypes: [0],
			shareAttributes: null,
			sharePermissions: 31,
			ownerDisplayName: 'Test User',
			ownerId: 'testuser',
		},
		mime: 'image/jpeg',
		permissions: 27,
		type: 'file',
		size: 1024,
		mtime: new Date('2025-01-15T12:00:00Z'),
	}

	const mockNode2 = {
		...mockNode,
		fileid: 43,
		basename: 'photo2.jpg',
	}

	let originalLocation

	function getRegisteredAction(fileActionConfig) {
		vi.mocked(registerFileAction).mockClear()
		registerFileAction33(fileActionConfig, () => '<svg>icon</svg>')
		return vi.mocked(registerFileAction).mock.calls[0][0]
	}

	beforeEach(() => {
		vi.mocked(axios.post).mockReset()
		originalLocation = window.location
		delete window.location
		window.location = { assign: vi.fn() }
	})

	afterEach(() => {
		window.location = originalLocation
	})

	describe('action registration', () => {
		it('registers an action with correct shape', () => {
			const config = {
				appid: 'testapp',
				action_handler: 'handler/path',
				name: 'test_action',
				display_name: 'Test Action',
				mime: 'image/jpeg',
				order: 10,
			}

			const action = getRegisteredAction(config)
			expect(registerFileAction).toHaveBeenCalledOnce()
			expect(action.id).toBe('test_action')
			expect(action.order).toBe(10)
			expect(typeof action.exec).toBe('function')
			expect(typeof action.execBatch).toBe('function')
			expect(typeof action.enabled).toBe('function')
		})

		it('enabled returns true when node mime matches', () => {
			const action = getRegisteredAction({
				appid: 'testapp',
				action_handler: 'handler',
				name: 'test',
				display_name: 'Test',
				mime: 'image/jpeg',
				order: 0,
			})

			expect(action.enabled({ nodes: [mockNode] })).toBe(true)
		})

		it('enabled returns false for non-matching mime', () => {
			const action = getRegisteredAction({
				appid: 'testapp',
				action_handler: 'handler',
				name: 'test',
				display_name: 'Test',
				mime: 'video/mp4',
				order: 0,
			})

			expect(action.enabled({ nodes: [mockNode] })).toBe(false)
		})

		it('enabled returns false for empty nodes', () => {
			const action = getRegisteredAction({
				appid: 'testapp',
				action_handler: 'handler',
				name: 'test',
				display_name: 'Test',
				mime: 'image/jpeg',
				order: 0,
			})

			expect(action.enabled({ nodes: [] })).toBe(false)
			expect(action.enabled({ nodes: null })).toBe(false)
		})
	})

	describe('execSingle - v1', () => {
		const v1Config = {
			appid: 'testapp',
			action_handler: 'handler/path',
			name: 'test_v1',
			display_name: 'Test V1',
			mime: 'image/jpeg',
			order: 0,
		}

		it('sends buildNodeInfo directly as POST body', async () => {
			vi.mocked(axios.post).mockResolvedValue({ data: {} })
			const action = getRegisteredAction(v1Config)

			await action.exec({ nodes: [mockNode] })

			expect(axios.post).toHaveBeenCalledWith(
				'/nextcloud/apps/app_api/proxy/testapp/handler/path',
				buildNodeInfo(mockNode),
			)
		})

		it('returns true on success', async () => {
			vi.mocked(axios.post).mockResolvedValue({ data: {} })
			const action = getRegisteredAction(v1Config)

			const result = await action.exec({ nodes: [mockNode] })
			expect(result).toBe(true)
		})

		it('returns false on error', async () => {
			vi.mocked(axios.post).mockRejectedValue(new Error('Network error'))
			const action = getRegisteredAction(v1Config)

			const result = await action.exec({ nodes: [mockNode] })
			expect(result).toBe(false)
		})
	})

	describe('execSingle - v2', () => {
		const v2Config = {
			appid: 'testapp',
			action_handler: 'handler/path',
			name: 'test_v2',
			display_name: 'Test V2',
			mime: 'image/jpeg',
			order: 0,
			version: '2.0',
		}

		it('sends { files: [buildNodeInfo(node)] } as POST body', async () => {
			vi.mocked(axios.post).mockResolvedValue({ data: {} })
			const action = getRegisteredAction(v2Config)

			await action.exec({ nodes: [mockNode] })

			expect(axios.post).toHaveBeenCalledWith(
				'/nextcloud/apps/app_api/proxy/testapp/handler/path',
				{ files: [buildNodeInfo(mockNode)] },
			)
		})

		it('returns true on success', async () => {
			vi.mocked(axios.post).mockResolvedValue({ data: {} })
			const action = getRegisteredAction(v2Config)

			const result = await action.exec({ nodes: [mockNode] })
			expect(result).toBe(true)
		})

		it('handles redirect_handler response', async () => {
			vi.mocked(axios.post).mockResolvedValue({
				data: { redirect_handler: 'results/page' },
			})
			const action = getRegisteredAction(v2Config)

			await action.exec({ nodes: [mockNode] })

			expect(window.location.assign).toHaveBeenCalledWith(
				'/nextcloud/apps/app_api/embedded/testapp/results/page?fileIds=42',
			)
		})

		it('does not redirect when response has no redirect_handler', async () => {
			vi.mocked(axios.post).mockResolvedValue({ data: { status: 'ok' } })
			const action = getRegisteredAction(v2Config)

			await action.exec({ nodes: [mockNode] })

			expect(window.location.assign).not.toHaveBeenCalled()
		})

		it('returns false on error', async () => {
			vi.mocked(axios.post).mockRejectedValue(new Error('Network error'))
			const action = getRegisteredAction(v2Config)

			const result = await action.exec({ nodes: [mockNode] })
			expect(result).toBe(false)
		})
	})

	describe('execBatch - v2', () => {
		const v2Config = {
			appid: 'testapp',
			action_handler: 'handler/path',
			name: 'test_v2_batch',
			display_name: 'Test V2 Batch',
			mime: 'image/jpeg',
			order: 0,
			version: '2.0',
		}

		it('sends all nodes in a single POST', async () => {
			vi.mocked(axios.post).mockResolvedValue({ data: {} })
			const action = getRegisteredAction(v2Config)

			await action.execBatch({ nodes: [mockNode, mockNode2] })

			expect(axios.post).toHaveBeenCalledOnce()
			expect(axios.post).toHaveBeenCalledWith(
				'/nextcloud/apps/app_api/proxy/testapp/handler/path',
				{ files: [buildNodeInfo(mockNode), buildNodeInfo(mockNode2)] },
			)
		})

		it('returns array of true on success', async () => {
			vi.mocked(axios.post).mockResolvedValue({ data: {} })
			const action = getRegisteredAction(v2Config)

			const result = await action.execBatch({ nodes: [mockNode, mockNode2] })
			expect(result).toEqual([true, true])
		})

		it('handles redirect_handler with multiple fileIds', async () => {
			vi.mocked(axios.post).mockResolvedValue({
				data: { redirect_handler: 'results/page' },
			})
			const action = getRegisteredAction(v2Config)

			await action.execBatch({ nodes: [mockNode, mockNode2] })

			expect(window.location.assign).toHaveBeenCalledWith(
				'/nextcloud/apps/app_api/embedded/testapp/results/page?fileIds=42,43',
			)
		})

		it('returns array of false on error', async () => {
			vi.mocked(axios.post).mockRejectedValue(new Error('Network error'))
			const action = getRegisteredAction(v2Config)

			const result = await action.execBatch({ nodes: [mockNode, mockNode2] })
			expect(result).toEqual([false, false])
		})
	})

	describe('execBatch - v1', () => {
		const v1Config = {
			appid: 'testapp',
			action_handler: 'handler/path',
			name: 'test_v1_batch',
			display_name: 'Test V1 Batch',
			mime: 'image/jpeg',
			order: 0,
		}

		it('calls POST individually for each node', async () => {
			vi.mocked(axios.post).mockResolvedValue({ data: {} })
			const action = getRegisteredAction(v1Config)

			await action.execBatch({ nodes: [mockNode, mockNode2] })

			expect(axios.post).toHaveBeenCalledTimes(2)
			expect(axios.post).toHaveBeenCalledWith(
				'/nextcloud/apps/app_api/proxy/testapp/handler/path',
				buildNodeInfo(mockNode),
			)
			expect(axios.post).toHaveBeenCalledWith(
				'/nextcloud/apps/app_api/proxy/testapp/handler/path',
				buildNodeInfo(mockNode2),
			)
		})

		it('returns array of results per node', async () => {
			vi.mocked(axios.post).mockResolvedValue({ data: {} })
			const action = getRegisteredAction(v1Config)

			const result = await action.execBatch({ nodes: [mockNode, mockNode2] })
			expect(result).toEqual([true, true])
		})

		it('returns mixed results when some nodes fail', async () => {
			vi.mocked(axios.post)
				.mockResolvedValueOnce({ data: {} })
				.mockRejectedValueOnce(new Error('Failed'))
			const action = getRegisteredAction(v1Config)

			const result = await action.execBatch({ nodes: [mockNode, mockNode2] })
			expect(result).toEqual([true, false])
		})
	})
})
