/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { Timer, delay } from './utils.js'

describe('Timer', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('calls the callback after the specified delay', () => {
		const callback = vi.fn()
		new Timer(callback, 1000) // eslint-disable-line no-new

		expect(callback).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1000)
		expect(callback).toHaveBeenCalledOnce()
	})

	it('pause prevents callback from firing', () => {
		const callback = vi.fn()
		const timer = new Timer(callback, 1000)

		vi.advanceTimersByTime(500)
		timer.pause()
		vi.advanceTimersByTime(1000)
		expect(callback).not.toHaveBeenCalled()
	})

	it('resume continues with remaining time after pause', () => {
		const callback = vi.fn()
		const timer = new Timer(callback, 1000)

		vi.advanceTimersByTime(600)
		timer.pause()
		vi.advanceTimersByTime(2000)
		expect(callback).not.toHaveBeenCalled()

		timer.resume()
		vi.advanceTimersByTime(399)
		expect(callback).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(callback).toHaveBeenCalledOnce()
	})
})

describe('delay', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('delays execution of the callback', () => {
		const callback = vi.fn()
		const debounced = delay(callback, 200)

		debounced()
		expect(callback).not.toHaveBeenCalled()

		vi.advanceTimersByTime(200)
		expect(callback).toHaveBeenCalledOnce()
	})

	it('resets timer on repeated calls (debounce behavior)', () => {
		const callback = vi.fn()
		const debounced = delay(callback, 200)

		debounced()
		vi.advanceTimersByTime(150)
		debounced()
		vi.advanceTimersByTime(150)
		expect(callback).not.toHaveBeenCalled()

		vi.advanceTimersByTime(50)
		expect(callback).toHaveBeenCalledOnce()
	})

	it('only fires the last call when called multiple times', () => {
		const callback = vi.fn()
		const debounced = delay(callback, 100)

		debounced()
		debounced()
		debounced()

		vi.advanceTimersByTime(100)
		expect(callback).toHaveBeenCalledOnce()
	})
})
