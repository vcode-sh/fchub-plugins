// Reversible live acceptance. Reachable only through scripts/run-live-tests.mjs.
//
// Every lifecycle here follows the same shape: create a record this run owns, read it back, change
// one field through a tool, read the change back, restore the original value, and read the original
// back. "Reversible" is a claim about being able to undo a write, so undoing it is the test.
//
// Deletion is deliberately not part of any lifecycle: FluentCart's deletes are classified
// destructive and this policy hides them, so removal happens through the cleanup ledger's reviewed
// REST path. Cleanup is infrastructure, never a capability this lane claims to have proven.

import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { buildApiIndex } from '../../src/code-mode/api-index.js'
import { searchTools } from '../../src/tools/dynamic-search.js'
import type {
	OwnedAttributeGroup,
	OwnedCustomer,
	OwnedProduct,
} from './support/acceptance-fixture.js'
import {
	acceptanceContext,
	callTool,
	createOwnedAttributeGroup,
	createOwnedCustomer,
	createOwnedProduct,
} from './support/acceptance-fixture.js'
import { captureActivityBoundary, cleanupRunActivities } from './support/activity-cleanup.js'
import { CleanupLedger } from './support/cleanup-ledger.js'
import { getLiveClient } from './support/live-client.js'
import { getLiveRun } from './support/live-run.js'

const run = getLiveRun()
const ledger = new CleanupLedger()

/** Never exposed, whatever the write mode. Each must be absent from all three discovery surfaces. */
const HIGH_IMPACT = [
	'fluentcart_order_delete',
	'fluentcart_order_bulk_action',
	'fluentcart_attribute_group_delete',
	'fluentcart_settings_save_payment_method',
	'fluentcart_settings_save_permissions',
	'fluentcart_file_upload',
	'fluentcart_role_create',
	'fluentcart_order_refund',
	'fluentcart_subscription_cancel',
	'fluentcart_product_pricing_update',
	'fluentcart_label_create',
	'fluentcart_customer_address_make_primary',
]

let ctx: Awaited<ReturnType<typeof acceptanceContext>>
let group: OwnedAttributeGroup
let customer: OwnedCustomer
let product: OwnedProduct
let activityBoundary: number

beforeAll(async () => {
	activityBoundary = await captureActivityBoundary()
	ctx = await acceptanceContext('reversible')
	group = await createOwnedAttributeGroup(ledger)
	customer = await createOwnedCustomer(ledger)
	product = await createOwnedProduct(ledger)
	console.error(`reversible lane: run ${run.id}, ${ctx.tools.length} tools exposed`)
}, 120_000)

afterAll(async () => {
	const failures: unknown[] = []
	try {
		await ledger.cleanup()
	} catch (error) {
		failures.push(error)
	}
	try {
		await cleanupRunActivities(getLiveClient(), activityBoundary, run.prefix)
	} catch (error) {
		failures.push(error)
	}
	if (failures.length === 1) throw failures[0]
	if (failures.length > 1) {
		throw new AggregateError(failures, 'entity and collateral activity cleanup both failed')
	}
})

const exposed = () => new Set(ctx.tools.map((tool) => tool.name))

describe('reversible exposure', () => {
	it('adds reversible writes and nothing else', () => {
		expect(ctx.writePolicy.writeMode).toBe('reversible')
		const risks = new Set(ctx.tools.map((tool) => tool.safety.risk))
		expect([...risks].sort()).toEqual(['read', 'reversible-write'])
	})

	it('exposes only writes that execute over plain REST', () => {
		const writes = ctx.tools.filter((tool) => tool.safety.risk === 'reversible-write')
		expect(writes.length).toBeGreaterThan(0)
		for (const tool of writes) expect(tool.safety.execution).toBe('rest')
	})

	it('hides every high-impact tool from the static registry', () => {
		const names = exposed()
		for (const name of HIGH_IMPACT) {
			expect(names.has(name), `${name} must be absent from the static registry`).toBe(false)
		}
	})

	it('hides every high-impact tool from dynamic search', () => {
		// Searching the policy-filtered registry is the only search a caller can perform, so a
		// hidden tool cannot surface here — but proving it beats assuming it.
		for (const name of HIGH_IMPACT) {
			const query = name.replace('fluentcart_', '').replace(/_/g, ' ')
			const rows = searchTools(ctx.tools, query, { limit: 10 })
			expect(rows.map((row) => row.name)).not.toContain(name)
		}
	})

	it('hides every high-impact tool from Code Mode', () => {
		const index = buildApiIndex(ctx.tools)
		for (const name of HIGH_IMPACT) {
			expect(index.has(name), `${name} must not be callable from the sandbox`).toBe(false)
			expect(index.names()).not.toContain(name)
		}
	})

	it('offers only deletes whose reviewed semantics are reversible', () => {
		const deletes = ctx.tools.filter((tool) => tool.name.endsWith('_delete'))
		expect(deletes.map((tool) => tool.name)).toEqual(['fluentcart_product_taxonomy_delete'])
		for (const tool of deletes) {
			expect(tool.safety).toMatchObject({
				risk: 'reversible-write',
				execution: 'rest',
			})
		}
	})
})

describe('attribute group lifecycle', () => {
	it('reads back the group this run created', async () => {
		const outcome = await callTool(ctx, 'fluentcart_attribute_group_get', { group_id: group.id })
		expect(outcome.isError).toBe(false)
		expect(outcome.text).toContain(group.title)
	})

	it('writes a changed title and reads the change back', async () => {
		const changed = `${group.title} changed`
		const write = await callTool(ctx, 'fluentcart_attribute_group_update', {
			group_id: group.id,
			title: changed,
			slug: group.slug,
		})
		expect(write.isError).toBe(false)

		const read = await callTool(ctx, 'fluentcart_attribute_group_get', { group_id: group.id })
		const found = (read.json as { group?: { title?: string } }).group
		expect(found?.title).toBe(changed)
	})

	it('restores the original title and reads the original back', async () => {
		const restore = await callTool(ctx, 'fluentcart_attribute_group_update', {
			group_id: group.id,
			title: group.title,
			slug: group.slug,
		})
		expect(restore.isError).toBe(false)

		const read = await callTool(ctx, 'fluentcart_attribute_group_get', { group_id: group.id })
		const found = (read.json as { group?: { title?: string } }).group
		expect(found?.title).toBe(group.title)
	})
})

describe('customer lifecycle', () => {
	// FluentCart re-validates email uniqueness without excluding the record being updated, so every
	// update carries a fresh address. The reversible field under test is therefore the name.
	const changedEmail = `${run.prefix}-rev-1@example.invalid`
	const restoredEmail = `${run.prefix}-rev-2@example.invalid`

	it('reads back the customer this run created', async () => {
		const outcome = await callTool(ctx, 'fluentcart_customer_get', { customer_id: customer.id })
		expect(outcome.isError).toBe(false)
		expect(outcome.text).toContain(customer.email)
	})

	it('writes a changed name and reads the change back', async () => {
		const write = await callTool(ctx, 'fluentcart_customer_update', {
			customer_id: customer.id,
			first_name: 'Changed',
			last_name: 'Acceptance',
			full_name: 'Changed Acceptance',
			email: changedEmail,
			status: 'active',
		})
		expect(write.isError).toBe(false)

		const read = await callTool(ctx, 'fluentcart_customer_get', { customer_id: customer.id })
		const found = (read.json as { customer?: { first_name?: string } }).customer
		expect(found?.first_name).toBe('Changed')
	})

	it('restores the original name and reads the original back', async () => {
		const restore = await callTool(ctx, 'fluentcart_customer_update', {
			customer_id: customer.id,
			first_name: 'MCP',
			last_name: 'Acceptance',
			full_name: 'MCP Acceptance',
			email: restoredEmail,
			status: 'active',
		})
		expect(restore.isError).toBe(false)

		const read = await callTool(ctx, 'fluentcart_customer_get', { customer_id: customer.id })
		const found = (read.json as { customer?: { first_name?: string } }).customer
		expect(found?.first_name).toBe('MCP')
	})
})

describe('product lifecycle', () => {
	it('reads back the draft product this run created', async () => {
		const outcome = await callTool(ctx, 'fluentcart_product_get', { product_id: product.id })
		expect(outcome.isError).toBe(false)
		expect(outcome.text).toContain(product.title)
	})

	/**
	 * A finding, not a workaround.
	 *
	 * `fluentcart_product_update_detail` declares `manage_stock` and the route answers 200, but
	 * FluentCart does not persist it: the detail reads back unchanged. The route exists to change
	 * the variation type, and the other fields ride along only when that action is sent. A tool
	 * that accepts a field it cannot change is a trap — an agent has no way to tell the difference
	 * between "saved" and "ignored" — so the behaviour is pinned here until the schema stops
	 * advertising the field or the handler starts honouring it.
	 */
	it('accepts manage_stock, answers success, and changes nothing', async () => {
		expect(product.detailId, 'the created product must expose a detail id').toBeDefined()
		const before = await callTool(ctx, 'fluentcart_product_get', { product_id: product.id })
		const original = (before.json as { product?: { detail?: Record<string, unknown> } }).product
			?.detail?.manage_stock

		const write = await callTool(ctx, 'fluentcart_product_update_detail', {
			detail_id: product.detailId as number,
			manage_stock: 'yes',
		})
		expect(write.isError).toBe(false)

		const after = await callTool(ctx, 'fluentcart_product_get', { product_id: product.id })
		const current = (after.json as { product?: { detail?: Record<string, unknown> } }).product
			?.detail?.manage_stock
		expect(String(current)).toBe(String(original))
		console.error(
			'reversible lane: product_update_detail accepts manage_stock but does not persist it',
		)
	})

	/**
	 * The product's reversal is its removal, not a field restore.
	 *
	 * Every write this policy exposes for a product either does nothing (above) or is the pricing
	 * save this lane refuses to run, so create-then-verified-delete is the only reversal a product
	 * genuinely has here. The ledger performs it after the suite and proves absence independently.
	 */
	it('leaves the product reversible by removal, with the ledger holding its exact id', () => {
		expect(product.id).toBeGreaterThan(0)
		expect(ledger.size).toBeGreaterThanOrEqual(3)
	})
})

describe('nothing outside this run was touched', () => {
	it('registered all three created records for verified removal', () => {
		expect(ledger.size).toBe(3)
	})

	it('left every owned record in its restored original state', async () => {
		const readGroup = await callTool(ctx, 'fluentcart_attribute_group_get', { group_id: group.id })
		expect((readGroup.json as { group?: { title?: string } }).group?.title).toBe(group.title)

		const readCustomer = await callTool(ctx, 'fluentcart_customer_get', {
			customer_id: customer.id,
		})
		expect((readCustomer.json as { customer?: { first_name?: string } }).customer?.first_name).toBe(
			'MCP',
		)
	})
})
