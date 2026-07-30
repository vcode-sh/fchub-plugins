import { readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import type { ContextInput, RuntimeProfile } from '../../src/commerce/context.js'
import {
	buildCommerceContext,
	ENTITY_PROBES,
	REPORT_PROBES,
	routeProfileDigest,
	SAFE_SHOP_KEYS,
	storeOrigin,
} from '../../src/commerce/context.js'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')

const routeFixture = JSON.parse(
	readFileSync(
		join(PACKAGE_ROOT, 'tests/fixtures/routes/fluentcart-1.6.0-core-pro-1.6.0.json'),
		'utf8',
	),
) as { profile: RuntimeProfile; operations: { method: string; path: string }[] }

const readContracts = JSON.parse(
	readFileSync(
		join(PACKAGE_ROOT, 'tests/fixtures/rest/fluentcart-1.6.0-all-active-read-contracts.json'),
		'utf8',
	),
) as { profileDigest: string; profile: RuntimeProfile }

const CORE_ONLY: RuntimeProfile = {
	wordpress: '7.0.2',
	activeComponents: [{ slug: 'fluent-cart', version: '1.6.0' }],
}

const SHOP = { store_name: 'Vibe Goods', currency: 'PLN', timezone: 'Europe/Warsaw' }

const EXPOSED = [
	'fluentcart_order_list',
	'fluentcart_product_list',
	'fluentcart_customer_list',
	'fluentcart_subscription_list',
	'fluentcart_report_overview',
	'fluentcart_report_revenue',
	'fluentcart_product_get',
]

function input(overrides: Partial<ContextInput> = {}): ContextInput {
	return {
		origin: 'http://localhost:9081',
		shop: SHOP,
		profile: routeFixture.profile,
		operations: routeFixture.operations,
		exposedToolNames: EXPOSED,
		writeMode: 'disabled',
		...overrides,
	}
}

describe('store context from a complete current runtime', () => {
	const context = buildCommerceContext(input())

	it('reports the store identity it was given, and no more', () => {
		expect(context.store).toEqual({
			origin: 'http://localhost:9081',
			name: 'Vibe Goods',
			currency: 'PLN',
			timezone: 'Europe/Warsaw',
		})
	})

	it('reports the runtime proven by the route fixture', () => {
		expect(context.runtime.wordpress).toBe('7.0.2')
		expect(context.runtime.fluentcartCore).toBe('1.6.0')
		expect(context.runtime.fluentcartPro).toBe('1.6.0')
	})

	it('warns about nothing when nothing is missing', () => {
		expect(context.warnings).toEqual([])
	})

	it('reports the default read-only write mode', () => {
		expect(context.capabilities.writeMode).toBe('disabled')
	})
})

describe('capability names come from the filtered registry', () => {
	it('lists only entities whose reviewed probe tool is exposed', () => {
		const context = buildCommerceContext(input())
		expect(context.capabilities.entities).toEqual([
			'customers',
			'orders',
			'products',
			'subscriptions',
		])
	})

	it('lists only reports whose reviewed probe tool is exposed', () => {
		const context = buildCommerceContext(input())
		expect(context.capabilities.reports).toEqual(['overview', 'revenue'])
	})

	it('reports nothing at all when the policy exposed nothing', () => {
		const context = buildCommerceContext(input({ exposedToolNames: [] }))
		expect(context.capabilities.entities).toEqual([])
		expect(context.capabilities.reports).toEqual([])
	})

	it('never invents a capability from a tool it was not given', () => {
		const context = buildCommerceContext(input())
		for (const name of [...context.capabilities.entities, ...context.capabilities.reports]) {
			const probe = ENTITY_PROBES[name] ?? REPORT_PROBES[name]
			expect(EXPOSED).toContain(probe)
		}
	})

	it('returns sorted names so two runs of one store read identically', () => {
		const shuffled = [...EXPOSED].reverse()
		const context = buildCommerceContext(input({ exposedToolNames: shuffled }))
		expect(context.capabilities.entities).toEqual([...context.capabilities.entities].sort())
	})
})

describe('core-only store without FluentCart Pro', () => {
	const context = buildCommerceContext(input({ profile: CORE_ONLY, operations: null }))

	it('reports Pro as absent rather than guessing a version', () => {
		expect(context.runtime.fluentcartCore).toBe('1.6.0')
		expect(context.runtime.fluentcartPro).toBeNull()
	})

	it('does not warn, because a core-only install is a configuration and not a gap', () => {
		expect(context.warnings).toEqual([])
	})

	it('digests differently from the core plus Pro runtime', () => {
		const full = buildCommerceContext(input())
		expect(context.runtime.routeProfileDigest).not.toBe(full.runtime.routeProfileDigest)
	})
})

describe('store whose runtime versions are not exposed', () => {
	it('returns useful capability context without inventing component versions', () => {
		const context = buildCommerceContext(input({ profile: null }))

		expect(context.runtime).toEqual({
			wordpress: null,
			fluentcartCore: null,
			fluentcartPro: null,
			routeProfileDigest: routeProfileDigest(null, routeFixture.operations),
		})
		expect(context.capabilities.entities).toContain('products')
		expect(context.warnings).toContain(
			'Runtime versions are not exposed by FluentCart; route capabilities are verified independently.',
		)
	})
})

describe('missing optional store settings', () => {
	it('returns null and one short warning for an unconfigured currency', () => {
		const context = buildCommerceContext(
			input({ shop: { store_name: 'Vibe Goods', timezone: 'UTC' } }),
		)
		expect(context.store.currency).toBeNull()
		expect(context.warnings).toEqual([
			'Store currency is not configured; monetary values have no unit.',
		])
	})

	it('returns null and one short warning for an unexposed timezone', () => {
		const context = buildCommerceContext(
			input({ shop: { store_name: 'Vibe Goods', currency: 'PLN' } }),
		)
		expect(context.store.timezone).toBeNull()
		expect(context.warnings).toEqual([
			'Store timezone is not exposed; date boundaries are unverified.',
		])
	})

	it('warns once per missing value when the shop block is absent entirely', () => {
		const context = buildCommerceContext(input({ shop: null }))
		expect(context.store).toEqual({
			origin: 'http://localhost:9081',
			name: null,
			currency: null,
			timezone: null,
		})
		expect(context.warnings).toHaveLength(3)
	})

	it('treats an empty string as absent rather than as a configured value', () => {
		const context = buildCommerceContext(
			input({ shop: { store_name: '   ', currency: '', timezone: '' } }),
		)
		expect(context.store.name).toBeNull()
		expect(context.store.currency).toBeNull()
	})
})

describe('route profile drift', () => {
	it('changes the digest when a component version moves', () => {
		const drifted: RuntimeProfile = {
			wordpress: '7.0.2',
			activeComponents: routeFixture.profile.activeComponents.map((component) =>
				component.slug === 'fluent-cart' ? { ...component, version: '1.5.6' } : component,
			),
		}
		const before = buildCommerceContext(input())
		const after = buildCommerceContext(input({ profile: drifted }))
		expect(after.runtime.routeProfileDigest).not.toBe(before.runtime.routeProfileDigest)
		expect(after.runtime.fluentcartCore).toBe('1.5.6')
	})

	it('changes the digest when the operation set moves', () => {
		const fewer = routeFixture.operations.slice(0, -1)
		const before = buildCommerceContext(input())
		const after = buildCommerceContext(input({ operations: fewer }))
		expect(after.runtime.routeProfileDigest).not.toBe(before.runtime.routeProfileDigest)
	})

	it('is stable against component ordering, which is not evidence of anything', () => {
		const reordered: RuntimeProfile = {
			wordpress: routeFixture.profile.wordpress,
			activeComponents: [...routeFixture.profile.activeComponents].reverse(),
		}
		expect(routeProfileDigest(reordered, routeFixture.operations)).toBe(
			routeProfileDigest(routeFixture.profile, routeFixture.operations),
		)
	})

	/**
	 * These two fixtures come from deliberately different runtimes and neither is wrong for it.
	 * Route evidence is captured in isolation, with only FluentCart and Pro installed, so every
	 * route it records is attributable to those components. Read contracts need the opposite: a
	 * live store with orders and products in it, which on this machine means every plugin active.
	 * Requiring one profile would force one of them to misreport where it came from.
	 *
	 * So each fixture states its own runtime, and the digest binds the read contracts to the exact
	 * route set they were validated against. That still stops a fixture from another store passing
	 * review — its profile or its route set would differ, and either changes the digest — and it
	 * additionally proves the two independently captured runtimes agree on all 396 operations.
	 */
	it('ties the read contracts to this route set through their own runtime profile', () => {
		expect(routeProfileDigest(readContracts.profile, routeFixture.operations)).toBe(
			readContracts.profileDigest,
		)
	})
})

describe('what the context refuses to carry', () => {
	it('reads only the allowlisted shop keys', () => {
		expect([...SAFE_SHOP_KEYS]).toEqual(['store_name', 'currency', 'timezone'])
	})

	it('ignores payment configuration, identity and nonces offered alongside them', () => {
		const context = buildCommerceContext(
			input({
				shop: {
					...SHOP,
					stripe_secret_key: 'sk_live_should_never_appear',
					current_user_email: 'owner@example.com',
					rest_nonce: 'abc123',
					shipping_packages: [{ id: 1 }],
				},
			}),
		)
		const serialised = JSON.stringify(context)
		expect(serialised).not.toContain('sk_live')
		expect(serialised).not.toContain('owner@example.com')
		expect(serialised).not.toContain('abc123')
		expect(serialised).not.toContain('shipping_packages')
	})

	it('carries no route list, only a digest standing in for one', () => {
		const serialised = JSON.stringify(buildCommerceContext(input()))
		expect(serialised).not.toContain('/products')
		expect(serialised).toMatch(/sha256:[0-9a-f]{64}/)
	})

	it('keeps only the origin of a store URL carrying a path or credentials', () => {
		expect(storeOrigin('http://localhost:9081/wp-json/fluent-cart/v2?x=1')).toBe(
			'http://localhost:9081',
		)
	})
})

describe('an unusable runtime profile', () => {
	it('refuses a profile with no FluentCart in it rather than reporting an empty store', () => {
		const notAStore: RuntimeProfile = {
			wordpress: '7.0.2',
			activeComponents: [{ slug: 'fluent-crm', version: '3.1.8' }],
		}
		expect(() => buildCommerceContext(input({ profile: notAStore }))).toThrow(/not a FluentCart/)
	})
})
