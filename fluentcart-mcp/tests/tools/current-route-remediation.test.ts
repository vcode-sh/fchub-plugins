import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { type ApiCapabilities, capabilitiesFromRestIndex } from '../../src/api/capabilities.js'
import type { FluentCartClient } from '../../src/api/client.js'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { emailNotificationTools } from '../../src/tools/email-notifications.js'
import { productOptionTermTools } from '../../src/tools/product-options-terms.js'
import { reportCoreTools } from '../../src/tools/reports-core.js'
import { taxClassTools } from '../../src/tools/tax-classes.js'
import { taxEuVatTools } from '../../src/tools/tax-eu-vat.js'

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')

/** Turn canonical `{method, path}` pairs into the REST index shape discovery consumes. */
function capabilitiesFor(operations: readonly { method: string; path: string }[]): ApiCapabilities {
	const routes: Record<string, { endpoints: { methods: string[] }[] }> = {}
	for (const { method, path } of operations) {
		const key = `/fluent-cart/v2${path === '/' ? '' : path}`
		routes[key] ??= { endpoints: [{ methods: [] }] }
		routes[key].endpoints[0]?.methods.push(method)
	}
	return capabilitiesFromRestIndex({ namespaces: ['fluent-cart/v2'], routes })
}

/**
 * The current runtime, from the captured core+Pro registry — measured evidence, not a changelog.
 */
const currentCapabilities = capabilitiesFor(
	JSON.parse(
		readFileSync(
			resolve(packageRoot, 'tests/fixtures/routes/fluentcart-1.6.0-core-pro-1.6.0.json'),
			'utf8',
		),
	).operations,
)

/**
 * The 1.3.9 shape, transcribed from the documented OpenAPI contract.
 *
 * Only the routes this table turns on are listed, and it is a documentation contract rather
 * than runtime proof — no 1.3.9 store was booted to confirm it. It exists so the legacy column
 * of the table is exercised, not to advertise legacy runtime support.
 */
const legacyCapabilities = capabilitiesFor([
	{ method: 'POST', path: '/email-notification/get-template' },
	{ method: 'POST', path: '/options/attr/group/{param}/term' },
	{ method: 'POST', path: '/options/attr/group/{param}/term/{param}/serial' },
	{ method: 'GET', path: '/reports/get-unfulfilled-orders' },
	{ method: 'GET', path: '/reports/cart-report' },
	{ method: 'PUT', path: '/tax/classes/{param}' },
	{ method: 'DELETE', path: '/tax/classes/{param}' },
	{ method: 'GET', path: '/tax/configuration/settings/eu-vat/rates' },
])

const client = {} as FluentCartClient

function names(tools: ToolDefinition[]): string[] {
	return tools.map((tool) => tool.name)
}

function allTools(capabilities?: ApiCapabilities): ToolDefinition[] {
	return [
		...emailNotificationTools(client, capabilities),
		...productOptionTermTools(client, capabilities),
		...reportCoreTools(client, capabilities),
		...taxClassTools(client, capabilities),
		...taxEuVatTools(client, capabilities),
	]
}

function find(capabilities: ApiCapabilities | undefined, name: string): ToolDefinition | undefined {
	return allTools(capabilities).find((tool) => tool.name === name)
}

describe('row 1: fluentcart_email_template_preview', () => {
	it('is registered on both versions', () => {
		expect(names(emailNotificationTools(client, currentCapabilities))).toContain(
			'fluentcart_email_template_preview',
		)
		expect(names(emailNotificationTools(client, legacyCapabilities))).toContain(
			'fluentcart_email_template_preview',
		)
	})

	it('requires a template key and no longer advertises a free-form body', () => {
		const tool = find(currentCapabilities, 'fluentcart_email_template_preview')
		const parsed = tool?.schema.safeParse({})
		expect(parsed?.success).toBe(false)
		expect(tool?.schema.safeParse({ template: 'order.paid.admin' }).success).toBe(true)
	})

	it('rejects a custom body rather than rendering the default template', async () => {
		const tool = find(currentCapabilities, 'fluentcart_email_template_preview')
		const result = await tool?.handler({ template: 'order.paid.admin', body: '<p>hi</p>' })

		expect(result?.isError).toBe(true)
		expect(result?.content[0]?.text).toMatch(/body/i)
	})

	it('is omitted when neither route exists', () => {
		const bare = capabilitiesFor([{ method: 'GET', path: '/x' }])
		expect(names(emailNotificationTools(client, bare))).not.toContain(
			'fluentcart_email_template_preview',
		)
	})
})

describe('row 2: fluentcart_attribute_term_create', () => {
	it('is registered on both versions', () => {
		expect(names(productOptionTermTools(client, currentCapabilities))).toContain(
			'fluentcart_attribute_term_create',
		)
		expect(names(productOptionTermTools(client, legacyCapabilities))).toContain(
			'fluentcart_attribute_term_create',
		)
	})

	it('accepts a bulk terms array on the current route', () => {
		const tool = find(currentCapabilities, 'fluentcart_attribute_term_create')
		expect(
			tool?.schema.safeParse({ group_id: 1, terms: [{ title: 'Red', color: '#ff0000' }] }).success,
		).toBe(true)
	})

	it('refuses a legacy slug on the current route instead of dropping it', async () => {
		const tool = find(currentCapabilities, 'fluentcart_attribute_term_create')
		const result = await tool?.handler({ group_id: 1, title: 'Red', slug: 'crimson' })

		expect(result?.isError).toBe(true)
		expect(result?.content[0]?.text).toMatch(/slug/i)
		expect(result?.content[0]?.text).toMatch(/derives/i)
	})

	it('rejects more than ten terms without calling the store', async () => {
		const tool = find(currentCapabilities, 'fluentcart_attribute_term_create')
		const terms = Array.from({ length: 11 }, (_, index) => ({ title: `Term ${index}` }))
		const result = await tool?.handler({ group_id: 1, terms })

		expect(result?.isError).toBe(true)
		expect(result?.content[0]?.text).toMatch(/more than 10/i)
	})

	it('rejects a malformed colour and a relative image URL', async () => {
		const tool = find(currentCapabilities, 'fluentcart_attribute_term_create')
		const badColour = await tool?.handler({ group_id: 1, terms: [{ title: 'Red', color: 'red' }] })
		const badImage = await tool?.handler({
			group_id: 1,
			terms: [{ title: 'Red', image: '/a.png' }],
		})

		expect(badColour?.isError).toBe(true)
		expect(badColour?.content[0]?.text).toMatch(/hex/i)
		expect(badImage?.isError).toBe(true)
		expect(badImage?.content[0]?.text).toMatch(/url/i)
	})
})

describe('row 3: fluentcart_attribute_term_reorder', () => {
	it('is registered on both versions', () => {
		expect(names(productOptionTermTools(client, currentCapabilities))).toContain(
			'fluentcart_attribute_term_reorder',
		)
		expect(names(productOptionTermTools(client, legacyCapabilities))).toContain(
			'fluentcart_attribute_term_reorder',
		)
	})

	it('takes an ordered id list on the current route', () => {
		const tool = find(currentCapabilities, 'fluentcart_attribute_term_reorder')
		expect(tool?.schema.safeParse({ group_id: 1, ids: [3, 1, 2] }).success).toBe(true)
	})

	it('refuses an empty ordering rather than sending a no-op', async () => {
		const tool = find(currentCapabilities, 'fluentcart_attribute_term_reorder')
		const result = await tool?.handler({ group_id: 1, ids: [] })

		expect(result?.isError).toBe(true)
		expect(result?.content[0]?.text).toMatch(/ids/i)
	})

	it('requires term_id and serial on the legacy route', async () => {
		const tool = find(legacyCapabilities, 'fluentcart_attribute_term_reorder')
		const result = await tool?.handler({ group_id: 1, ids: [1, 2] })

		expect(result?.isError).toBe(true)
		expect(result?.content[0]?.text).toMatch(/term_id/i)
	})
})

describe('rows 4 and 5: withdrawn report endpoints', () => {
	it.each(['fluentcart_report_unfulfilled_orders', 'fluentcart_report_cart'])(
		'%s is omitted on the current runtime but available on 1.3.9',
		(name) => {
			expect(names(reportCoreTools(client, currentCapabilities))).not.toContain(name)
			expect(names(reportCoreTools(client, legacyCapabilities))).toContain(name)
		},
	)

	it('is omitted when no capability evidence is supplied', () => {
		const registered = names(reportCoreTools(client))
		expect(registered).not.toContain('fluentcart_report_unfulfilled_orders')
		expect(registered).not.toContain('fluentcart_report_cart')
	})

	it('substitutes no replacement analytics under a renamed tool', () => {
		// The withdrawn endpoints measured fulfilment backlog and cart abandonment. Neither has a
		// current equivalent, so nothing may reappear under an adjacent name computed from other
		// numbers — a plausible-looking abandonment rate derived from orders would be a fabrication.
		const current = names(reportCoreTools(client, currentCapabilities))
		// Anchored on purpose: every tool is named `fluentcart_*`, so a bare /cart/ would match
		// the entire registry and prove nothing.
		const substitutes = current.filter((name) =>
			/unfulfilled|abandon|fulfilment|fulfillment|_cart$/i.test(name),
		)

		expect(substitutes).toEqual([])
	})
})

describe('row 6: fluentcart_tax_class_update', () => {
	it('is omitted on a registry that offers only DELETE at that path', () => {
		expect(currentCapabilities.has('DELETE', '/tax/classes/{param}')).toBe(true)
		expect(currentCapabilities.has('PUT', '/tax/classes/{param}')).toBe(false)
		expect(names(taxClassTools(client, currentCapabilities))).not.toContain(
			'fluentcart_tax_class_update',
		)
	})

	it('is available on 1.3.9, which serves PUT', () => {
		expect(names(taxClassTools(client, legacyCapabilities))).toContain(
			'fluentcart_tax_class_update',
		)
	})

	it('keeps list, create and delete on both versions', () => {
		for (const capabilities of [currentCapabilities, legacyCapabilities]) {
			const registered = names(taxClassTools(client, capabilities))
			expect(registered).toContain('fluentcart_tax_class_list')
			expect(registered).toContain('fluentcart_tax_class_create')
			expect(registered).toContain('fluentcart_tax_class_delete')
		}
	})
})

describe('row 7: fluentcart_tax_eu_rates', () => {
	it('is registered on both versions', () => {
		expect(names(taxEuVatTools(client, currentCapabilities))).toContain('fluentcart_tax_eu_rates')
		expect(names(taxEuVatTools(client, legacyCapabilities))).toContain('fluentcart_tax_eu_rates')
	})

	it('resolves to the OSS rates route on the current runtime', () => {
		expect(currentCapabilities.has('GET', '/tax/configuration/settings/eu-vat/oss-rates')).toBe(
			true,
		)
		expect(currentCapabilities.has('GET', '/tax/configuration/settings/eu-vat/rates')).toBe(false)
	})

	it('falls back to the old rates route only where it exists', () => {
		expect(legacyCapabilities.has('GET', '/tax/configuration/settings/eu-vat/rates')).toBe(true)
		expect(legacyCapabilities.has('GET', '/tax/configuration/settings/eu-vat/oss-rates')).toBe(
			false,
		)
	})
})

describe('registry-wide guarantees', () => {
	it('registers no tool whose route the current store does not serve', () => {
		const withdrawn = [
			'fluentcart_report_unfulfilled_orders',
			'fluentcart_report_cart',
			'fluentcart_tax_class_update',
		]
		const registered = names(allTools(currentCapabilities))

		for (const name of withdrawn) expect(registered).not.toContain(name)
	})

	it('defaults to the current shape when no evidence is supplied', () => {
		const registered = names(allTools())

		expect(registered).toContain('fluentcart_email_template_preview')
		expect(registered).toContain('fluentcart_attribute_term_create')
		expect(registered).toContain('fluentcart_attribute_term_reorder')
		expect(registered).toContain('fluentcart_tax_eu_rates')
		expect(registered).not.toContain('fluentcart_tax_class_update')
	})
})
