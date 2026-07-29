// Read-only proof for FluentCart 1.5.5 routes added after the original MCP inventory.
//
// The target, credentials and run identity are owned by scripts/run-live-tests.mjs. Values are
// never snapshotted: the assertions cover status and the deliberately narrow output contracts.

import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import {
	acceptanceContext,
	callTool,
	createOwnedShippingClass,
	type OwnedShippingClass,
} from './support/acceptance-fixture.js'
import { CleanupLedger } from './support/cleanup-ledger.js'
import { getLiveRun } from './support/live-run.js'

getLiveRun()

let ctx: Awaited<ReturnType<typeof acceptanceContext>>
let shippingClass: OwnedShippingClass
const ledger = new CleanupLedger()

beforeAll(async () => {
	ctx = await acceptanceContext('disabled')
	shippingClass = await createOwnedShippingClass(ledger)
}, 120_000)

afterAll(async () => {
	await ledger.cleanup()
})

function text(value: unknown): string {
	return JSON.stringify(value)
}

describe('latest FluentCart 1.5.5 read tools', () => {
	it('reads compact bulk-edit data without editor, media, or internal variant payloads', async () => {
		const outcome = await callTool(ctx, 'fluentcart_product_bulk_edit_data', {
			page: 1,
			per_page: 2,
		})
		expect(outcome.isError).toBe(false)
		const serialised = text(outcome.json)
		for (const forbidden of ['post_content', '"gallery"', '"media"', '"other_info"']) {
			expect(serialised).not.toContain(forbidden)
		}
	})

	it('reports seller-detail readiness without returning seller values', async () => {
		const outcome = await callTool(ctx, 'fluentcart_pdf_seller_details_status')
		expect(outcome.isError).toBe(false)
		const body = outcome.json as Record<string, unknown>
		expect(Object.keys(body).sort()).toEqual([
			'configured',
			'e_invoice_enabled',
			'e_invoice_profile',
			'store_country_configured',
		])
		const serialised = text(body)
		for (const forbidden of ['seller_contact_', 'seller_bank_', 'seller_vat_', 'seller_tax_']) {
			expect(serialised).not.toContain(forbidden)
		}
	})

	it('reads the exact shipping-class profile this run created', async () => {
		const outcome = await callTool(ctx, 'fluentcart_shipping_class_profile', {
			class_id: shippingClass.id,
		})
		expect(outcome.isError).toBe(false)
		expect(outcome.json).toMatchObject({
			shipping_class: { id: shippingClass.id, name: shippingClass.name },
		})
		expect(text(outcome.json)).not.toContain('created_at')
	})

	it('reads product tax overrides for the store country without guessing the country', async ({
		skip,
	}) => {
		const settings = await callTool(ctx, 'fluentcart_settings_get_store')
		expect(settings.isError).toBe(false)
		const country = (settings.json as { settings?: { store_country?: unknown } } | null)?.settings
			?.store_country
		if (typeof country !== 'string' || country.length !== 2) {
			skip('the live store does not expose a two-letter country')
		}

		const outcome = await callTool(ctx, 'fluentcart_tax_product_overrides', {
			country_code: country,
		})
		expect(outcome.isError).toBe(false)
		expect(outcome.json).toBeDefined()
		expect(text(outcome.json)).not.toContain('created_at')
	})
})
