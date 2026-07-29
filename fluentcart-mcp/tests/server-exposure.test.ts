import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createAbilityBridgeTools } from '../src/abilities/tools.js'
import {
	purgeAuthorizationCaches,
	resolveServerContext,
	resolveWritePolicy,
} from '../src/server.js'

const ENV_KEYS = [
	'FLUENTCART_URL',
	'FLUENTCART_USERNAME',
	'FLUENTCART_APP_PASSWORD',
	'FLUENTCART_WRITE_MODE',
	'FLUENTCART_ABILITIES_MODE',
	'FLUENTCART_ABILITIES_USERNAME',
	'FLUENTCART_ABILITIES_APP_PASSWORD',
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
	it('does not expose the optional ability bridge in the synchronous/default context', () => {
		const names = exposedNames()
		expect(names.has('fluentcart_search_abilities')).toBe(false)
		expect(names.has('fluentcart_execute_read_ability')).toBe(false)
	})

	it('includes only explicitly injected, already-discovered ability bridge tools', () => {
		const bridgeTools = createAbilityBridgeTools({
			abilities: [
				{
					name: 'fluent-cart/get-store-context',
					label: 'Get Store Context',
					description: 'Context',
					category: 'fluent-cart',
					inputSchema: { type: 'object', properties: {} },
					outputSchema: [],
					annotations: {
						abilitiesReadonly: true,
						abilitiesDestructive: false,
						abilitiesIdempotent: null,
						mcpReadOnlyHint: true,
						mcpDestructiveHint: false,
						mcpIdempotentHint: null,
						mcpOpenWorldHint: null,
					},
					rest: {
						discoveryPath: '/wp-abilities/v1/abilities/fluent-cart/get-store-context',
						runPath: '/wp-abilities/v1/abilities/fluent-cart/get-store-context/run',
						methods: ['GET'],
					},
				},
			],
			execute: async () => ({ ok: true }),
		})
		const names = new Set(resolveServerContext(null, bridgeTools).tools.map((tool) => tool.name))

		expect(names.has('fluentcart_search_abilities')).toBe(true)
		expect(names.has('fluentcart_describe_abilities')).toBe(true)
		expect(names.has('fluentcart_execute_read_ability')).toBe(true)
	})

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

	it('keeps audited real-money rows absent in reversible mode', () => {
		process.env.FLUENTCART_WRITE_MODE = 'reversible'
		const names = exposedNames()
		expect(names.has('fluentcart_order_refund')).toBe(false)
		expect(names.has('fluentcart_subscription_cancel')).toBe(false)
	})

	it('never exposes a destructive, control-plane or credential tool in any write mode', () => {
		for (const mode of ['disabled', 'reversible']) {
			process.env.FLUENTCART_WRITE_MODE = mode

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
		process.env.FLUENTCART_WRITE_MODE = 'guarded'
		expect(() => resolveWritePolicy()).toThrow(/Invalid FLUENTCART_WRITE_MODE/)
	})
})

describe('authorisation cache revocation', () => {
	it('purges every authorised response held by the production cache', async () => {
		const context = resolveServerContext()
		await context.resourceDeps.cache.getOrLoad(
			context.resourceDeps.scope,
			'fixture',
			{},
			60_000,
			async () => 'authorised-data',
		)

		expect(context.resourceDeps.cache.peek(context.resourceDeps.scope, 'fixture')).toBe(
			'authorised-data',
		)
		purgeAuthorizationCaches()
		expect(context.resourceDeps.cache.peek(context.resourceDeps.scope, 'fixture')).toBeUndefined()
	})
})
