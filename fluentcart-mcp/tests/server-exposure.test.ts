import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { resolveServerContext, resolveWritePolicy } from '../src/server.js'

const ENV_KEYS = [
	'FLUENTCART_URL',
	'FLUENTCART_USERNAME',
	'FLUENTCART_APP_PASSWORD',
	'FLUENTCART_WRITE_MODE',
	'FLUENTCART_GUARD_SECRET',
	'FLUENTCART_GUARD_STATE_DIR',
]

const original: Record<string, string | undefined> = {}

beforeEach(() => {
	for (const key of ENV_KEYS) original[key] = process.env[key]
	process.env.FLUENTCART_URL = 'https://fixture.invalid'
	process.env.FLUENTCART_USERNAME = 'fixture'
	process.env.FLUENTCART_APP_PASSWORD = 'fixture-app-password'
})

afterEach(() => {
	for (const key of ENV_KEYS) {
		if (original[key] === undefined) {
			delete process.env[key]
		} else {
			process.env[key] = original[key]
		}
	}
})

function exposedNames(): Set<string> {
	return new Set(resolveServerContext().tools.map((tool) => tool.name))
}

describe('registry exposure is filtered before registration', () => {
	it('defaults to write mode disabled', () => {
		delete process.env.FLUENTCART_WRITE_MODE
		expect(resolveWritePolicy().writeMode).toBe('disabled')
	})

	it('exposes no write at all by default', () => {
		delete process.env.FLUENTCART_WRITE_MODE
		const names = exposedNames()

		for (const name of [
			'fluentcart_coupon_create',
			'fluentcart_product_create',
			'fluentcart_order_refund',
			'fluentcart_subscription_cancel',
			'fluentcart_order_delete',
			'fluentcart_role_create',
		]) {
			expect(names.has(name), `${name} must be hidden by default`).toBe(false)
		}
	})

	it('still exposes reads by default', () => {
		delete process.env.FLUENTCART_WRITE_MODE
		const names = exposedNames()
		expect(names.has('fluentcart_order_list')).toBe(true)
		expect(names.has('fluentcart_product_get')).toBe(true)
	})

	it('exposes reversible writes only when asked', () => {
		process.env.FLUENTCART_WRITE_MODE = 'reversible'
		const names = exposedNames()

		expect(names.has('fluentcart_coupon_create')).toBe(true)
		expect(names.has('fluentcart_customer_create')).toBe(true)
		// Still no destructive or real-money exposure.
		expect(names.has('fluentcart_coupon_delete')).toBe(false)
		expect(names.has('fluentcart_order_refund')).toBe(false)
	})

	it('keeps guarded actions hidden in guarded mode without state and secret', () => {
		process.env.FLUENTCART_WRITE_MODE = 'guarded'
		delete process.env.FLUENTCART_GUARD_SECRET
		delete process.env.FLUENTCART_GUARD_STATE_DIR

		const names = exposedNames()
		expect(names.has('fluentcart_order_refund')).toBe(false)
		expect(names.has('fluentcart_subscription_cancel')).toBe(false)
	})

	it('ships refund and cancellation unavailable, even in fully configured guarded mode', () => {
		// 2.0.0 ships these unavailable. The guard is complete and unit-tested, but it was never
		// acceptance-proven: refunding an order this run owns is impossible on FluentCart 1.5.5,
		// because a transaction has no DELETE route and does not cascade from order deletion. A
		// money-moving capability that cannot be proven does not get to be present.
		process.env.FLUENTCART_WRITE_MODE = 'guarded'
		process.env.FLUENTCART_GUARD_SECRET = 'g'.repeat(32)
		process.env.FLUENTCART_GUARD_STATE_DIR = '/tmp/fluentcart-guard-fixture'

		const names = exposedNames()
		expect(names.has('fluentcart_order_refund')).toBe(false)
		expect(names.has('fluentcart_subscription_cancel')).toBe(false)
	})

	it('still exposes reversible writes in guarded mode', () => {
		process.env.FLUENTCART_WRITE_MODE = 'guarded'
		process.env.FLUENTCART_GUARD_SECRET = 'g'.repeat(32)
		process.env.FLUENTCART_GUARD_STATE_DIR = '/tmp/fluentcart-guard-fixture'

		expect(exposedNames().has('fluentcart_coupon_create')).toBe(true)
	})

	it('fails startup on a weak guard secret rather than quietly dropping the guarded tools', () => {
		process.env.FLUENTCART_WRITE_MODE = 'guarded'
		process.env.FLUENTCART_GUARD_SECRET = 'too-short'
		process.env.FLUENTCART_GUARD_STATE_DIR = '/tmp/fluentcart-guard-fixture'

		// The policy layer already refuses to advertise the guard...
		expect(resolveWritePolicy().guard.signingSecret).toBe(false)

		// ...and startup refuses outright, with an actionable message. Silently starting without
		// refund and cancellation would look like a missing feature rather than a bad secret.
		expect(() => exposedNames()).toThrow(/FLUENTCART_GUARD_SECRET must be at least 32 bytes/)
	})

	it('never exposes a destructive, control-plane or credential tool in any write mode', () => {
		for (const mode of ['disabled', 'reversible', 'guarded']) {
			process.env.FLUENTCART_WRITE_MODE = mode
			process.env.FLUENTCART_GUARD_SECRET = 'g'.repeat(32)
			process.env.FLUENTCART_GUARD_STATE_DIR = '/tmp/fluentcart-guard-fixture'

			const names = exposedNames()
			for (const hidden of [
				'fluentcart_order_delete',
				'fluentcart_order_bulk_action',
				'fluentcart_role_update',
				'fluentcart_settings_save_payment_method',
				'fluentcart_integration_save_global_settings',
				'fluentcart_file_upload',
				'fluentcart_order_accept_dispute',
			]) {
				expect(names.has(hidden), `${hidden} must stay hidden in ${mode} mode`).toBe(false)
			}
		}
	})

	it('fails startup on an invalid write mode instead of falling back to a safe-looking default', () => {
		process.env.FLUENTCART_WRITE_MODE = 'enabled'
		expect(() => resolveWritePolicy()).toThrow(/Invalid FLUENTCART_WRITE_MODE/)
	})
})
