import { describe, expect, it } from 'vitest'
import { createClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'
import {
	resolveToolSafety,
	reviewedRisk,
	reviewedToolNames,
} from '../../src/tools/risk-registry.js'

const client = createClient({
	url: 'https://fixture.invalid',
	username: 'fixture',
	appPassword: 'fixture',
	adminBase: 'https://fixture.invalid/wp-json/fluent-cart/v2',
	publicBase: 'https://fixture.invalid/wp-json/fluent-cart-public/v2',
})

const registry = createAllTools(client)
const names = new Set(registry.map((tool) => tool.name))

describe('risk registry completeness', () => {
	it('gives every registered tool a safety row', () => {
		for (const tool of registry) {
			expect(tool.safety, `${tool.name} must carry safety metadata`).toBeDefined()
		}
	})

	it('classifies every non-read tool explicitly', () => {
		const unreviewed = registry
			.filter((tool) => tool.safety.risk === 'unreviewed-write')
			.map((tool) => tool.name)

		expect(unreviewed).toEqual([])
	})

	it('never marks a write as read', () => {
		for (const tool of registry) {
			if (tool.safety.risk !== 'read') continue
			expect(
				tool.annotations?.readOnlyHint,
				`${tool.name} is classified read but is not annotated read-only`,
			).toBe(true)
		}
	})

	it('carries no stale row for a tool that no longer exists', () => {
		const stale = reviewedToolNames().filter((name) => !names.has(name))
		expect(stale).toEqual([])
	})

	it('classifies refund and subscription cancellation as real money needing a guard', () => {
		for (const name of ['fluentcart_order_refund', 'fluentcart_subscription_cancel']) {
			const safety = resolveToolSafety(name, false)
			expect(safety.risk).toBe('real-money')
			expect(safety.idempotency).toBe('guard-required')
		}
	})

	it('ships refund and cancellation unavailable pending acceptance evidence', () => {
		// Classified real-money and guard-required so the classification survives, but execution
		// is 'none' for 2.0.0: the guard is built and unit-tested yet never acceptance-proven,
		// because no run-owned refundable order can be created and then removed on this store.
		for (const name of ['fluentcart_order_refund', 'fluentcart_subscription_cancel']) {
			const safety = resolveToolSafety(name, false)
			expect(safety.risk).toBe('real-money')
			expect(safety.idempotency).toBe('guard-required')
			expect(safety.execution).toBe('none')
		}
	})

	it('leaves dispute acceptance unsupported and non-executable', () => {
		const safety = resolveToolSafety('fluentcart_order_accept_dispute', false)
		expect(safety.risk).toBe('real-money')
		expect(safety.idempotency).toBe('unsupported')
		expect(safety.execution).toBe('none')
	})

	it('classifies the named high-impact operations exactly', () => {
		const expected: Record<string, string> = {
			fluentcart_order_bulk_action: 'destructive-write',
			fluentcart_customer_bulk_action: 'destructive-write',
			fluentcart_product_bulk_action: 'destructive-write',
			fluentcart_order_delete: 'destructive-write',
			fluentcart_order_update_statuses: 'destructive-write',
			fluentcart_role_create: 'control-plane',
			fluentcart_role_update: 'control-plane',
			fluentcart_role_delete: 'control-plane',
			fluentcart_settings_save_permissions: 'control-plane',
			fluentcart_integration_install_plugin: 'control-plane',
			fluentcart_settings_save_payment_method: 'credential-bearing',
			fluentcart_integration_save_global_settings: 'credential-bearing',
			fluentcart_file_upload: 'infrastructure',
			fluentcart_file_delete: 'infrastructure',
			fluentcart_email_update: 'external-side-effect',
		}

		for (const [name, risk] of Object.entries(expected)) {
			expect(reviewedRisk(name), `${name} risk classification`).toBe(risk)
		}
	})

	it('classifies ordinary coupon and product edits as reversible', () => {
		for (const name of [
			'fluentcart_coupon_create',
			'fluentcart_coupon_update',
			'fluentcart_product_create',
			'fluentcart_customer_create',
		]) {
			expect(reviewedRisk(name)).toBe('reversible-write')
		}
	})

	it('refuses to call label or address creation reversible', () => {
		// FluentCart exposes no DELETE for a label, and a customer's first address is primary
		// and undeletable. Neither creation can be undone, so neither may be reversible.
		expect(reviewedRisk('fluentcart_label_create')).not.toBe('reversible-write')
		expect(reviewedRisk('fluentcart_customer_address_create')).not.toBe('reversible-write')
	})

	it('never annotates a tool as both read-only and destructive', () => {
		const conflicted = registry
			.filter(
				(tool) =>
					tool.annotations.readOnlyHint === true && tool.annotations.destructiveHint === true,
			)
			.map((tool) => tool.name)

		expect(conflicted).toEqual([])
	})

	it('hides the quiet writes that read like harmless reads', () => {
		// Each of these was invoked by a deleted live-QA script as though it were a read. They
		// mutate a discovered record or persist server-side state, so none may be executable.
		for (const name of [
			'fluentcart_customer_recalculate_ltv',
			'fluentcart_report_retention_snapshots_generate',
			'fluentcart_label_create',
			'fluentcart_product_terms_add',
		]) {
			const safety = resolveToolSafety(name, false)
			expect(safety.risk, `${name} must not be classified read`).not.toBe('read')
			expect(safety.execution, `${name} must not be executable`).toBe('none')
		}
	})

	it('keeps a POST-shaped lookup classified as a genuine read', () => {
		// FluentCart serves several pure lookups over POST. Classifying by verb would hide them
		// as writes; classifying by semantics keeps them available and honestly annotated.
		const lookup = registry.find((tool) => tool.name === 'fluentcart_product_terms_by_parent')
		expect(lookup?.safety.risk).toBe('read')
		expect(lookup?.annotations.readOnlyHint).toBe(true)
		expect(lookup?.annotations.destructiveHint).toBe(false)
	})

	it('gives an unknown write the hidden default', () => {
		const safety = resolveToolSafety('fluentcart_brand_new_write', false)
		expect(safety.risk).toBe('unreviewed-write')
		expect(safety.execution).toBe('none')
	})

	it('gives an unknown read the plain read default', () => {
		expect(resolveToolSafety('fluentcart_brand_new_read', true).risk).toBe('read')
	})
})
