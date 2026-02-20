/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { DAEMON_TEMPLATES, DAEMON_COMPUTE_DEVICES } from './daemonTemplates.js'

describe('DAEMON_TEMPLATES', () => {
	it('has 8 templates', () => {
		expect(DAEMON_TEMPLATES).toHaveLength(8)
	})

	it('each template has required fields', () => {
		const requiredKeys = ['name', 'displayName', 'acceptsDeployId', 'host', 'deployConfig']
		for (const template of DAEMON_TEMPLATES) {
			for (const key of requiredKeys) {
				expect(template, `template "${template.name}" missing "${key}"`).toHaveProperty(key)
			}
		}
	})

	it('each template has a unique name', () => {
		const names = DAEMON_TEMPLATES.map(t => t.name)
		expect(new Set(names).size).toBe(names.length)
	})

	it('each deployConfig has required structure', () => {
		for (const template of DAEMON_TEMPLATES) {
			const dc = template.deployConfig
			expect(dc).toHaveProperty('net')
			expect(dc).toHaveProperty('computeDevice')
			expect(dc).toHaveProperty('resourceLimits')
			expect(dc.computeDevice).toHaveProperty('id')
			expect(dc.computeDevice).toHaveProperty('label')
		}
	})

	it('acceptsDeployId is docker-install or manual-install', () => {
		const validIds = ['docker-install', 'manual-install']
		for (const template of DAEMON_TEMPLATES) {
			expect(validIds, `invalid acceptsDeployId in "${template.name}"`).toContain(template.acceptsDeployId)
		}
	})

	it('matches snapshot', () => {
		expect(DAEMON_TEMPLATES).toMatchSnapshot()
	})
})

describe('DAEMON_COMPUTE_DEVICES', () => {
	it('has 3 devices', () => {
		expect(DAEMON_COMPUTE_DEVICES).toHaveLength(3)
	})

	it('each device has id and label', () => {
		for (const device of DAEMON_COMPUTE_DEVICES) {
			expect(device).toHaveProperty('id')
			expect(device).toHaveProperty('label')
			expect(typeof device.id).toBe('string')
			expect(typeof device.label).toBe('string')
		}
	})

	it('includes cpu, cuda, and rocm', () => {
		const ids = DAEMON_COMPUTE_DEVICES.map(d => d.id)
		expect(ids).toContain('cpu')
		expect(ids).toContain('cuda')
		expect(ids).toContain('rocm')
	})
})
