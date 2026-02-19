/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import {
	buildNodeInfo,
	generateAppAPIProxyUrl,
	generateExAppUIPageUrl,
	isSvgContentType,
	loadStaticAppAPIInlineSvgIcon,
	mimeMatches,
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
