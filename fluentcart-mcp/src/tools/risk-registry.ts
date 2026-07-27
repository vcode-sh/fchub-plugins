import type { ToolRisk, ToolSafety } from './risk.js'
import { READ_SAFETY, UNREVIEWED_WRITE_SAFETY } from './risk.js'

/**
 * Reviewed business-risk rows for every write this server can name.
 *
 * A write is `reversible-write` only when FluentCart 1.5.5 offers BOTH an exact read-back and
 * a supported way to delete or restore the record. Two verified counter-examples keep that bar
 * honest: `/labels` has no DELETE route at all, and a customer's first address is primary and
 * cannot be deleted, so neither creation is reversible however ordinary it looks.
 *
 * Anything absent from these lists resolves to `unreviewed-write` and is hidden everywhere.
 * Adding a tool without classifying it therefore makes it invisible, never accidentally live.
 */

/**
 * Endpoints FluentCart serves over POST that only read. The verb is an artefact of needing a
 * request body; nothing in the store changes, so they are classified as reads.
 */
const POST_SHAPED_READS = [
	'fluentcart_coupon_check_eligibility',
	'fluentcart_integration_get_chained_data',
	'fluentcart_order_bump_list',
	'fluentcart_order_calculate_shipping',
	'fluentcart_product_bundle_info',
	'fluentcart_subscription_fetch',
]

/** Create/update pairs with a verified delete (or restore) and a verified read-back. */
/**
 * Creates with a verified delete and a verified read-back.
 *
 * Reversible, but NOT idempotent: FluentCart accepts no idempotency key on create, so a second
 * POST makes a second record. They are listed separately from the updates so the annotation can
 * tell the truth — `idempotentHint` is exactly what a client consults before retrying a timed-out
 * call, and advertising it here invited silent duplicates.
 */
const REVERSIBLE_CREATES = [
	'fluentcart_attribute_group_create',
	'fluentcart_attribute_term_create',
	'fluentcart_coupon_create',
	'fluentcart_customer_create',
	'fluentcart_order_bump_create',
	'fluentcart_product_create',
	// Saved views carry full CRUD in 1.5.5 — GET, POST, PUT and DELETE /saved-views/{id} — so a
	// created view has both an exact read-back and a supported removal.
	'fluentcart_saved_view_create',
	'fluentcart_shipping_class_create',
	'fluentcart_shipping_method_create',
	'fluentcart_shipping_zone_create',
	'fluentcart_tax_class_create',
	'fluentcart_tax_rate_create',
	'fluentcart_tax_shipping_override_create',
	'fluentcart_variant_create',
]

/**
 * Updates and saves with a verified read-back.
 *
 * Genuinely idempotent: writing the same field values twice lands the same row, so a client may
 * safely retry one that timed out.
 */
const REVERSIBLE_UPDATES = [
	'fluentcart_attribute_group_update',
	'fluentcart_attribute_term_update',
	'fluentcart_coupon_update',
	'fluentcart_customer_update',
	'fluentcart_order_bump_update',
	'fluentcart_product_update_detail',
	'fluentcart_product_upgrade_path_save',
	'fluentcart_product_upgrade_path_update',
	// Proven reversible on a live store: GET returns the complete settings blob, POST replaces it
	// wholesale, and writing the captured blob back restored it byte-identically. The tool is
	// read-merge-write with local enum validation, so it cannot send a partial payload or let an
	// out-of-range value be silently coerced by the controller.
	'fluentcart_tax_settings_save',
	'fluentcart_shipping_class_update',
	'fluentcart_shipping_method_update',
	'fluentcart_shipping_zone_update',
	'fluentcart_tax_rate_update',
	'fluentcart_variant_update',
	// ── Stock and taxonomy, promoted 2026-07-27 on a proven round trip ──
	//
	// All four have an exact read-back through GET /products/{id}/pricing, which carries every
	// variant's manage_stock, total_stock, available and stock_status alongside the product's
	// assigned categories and brands, and all four can be set back to what that read reported.
	// Proven live on a run-owned fixture in tests/integration/product-stock-taxonomy.test.ts:
	// captured state, changed it, restored it, and compared field by field.
	//
	// Neither taxonomy tool deletes a term. They change which terms a product is assigned, and the
	// terms themselves survive for other products — which is why they are reversible where
	// fluentcart_product_terms_add, which creates a term nothing can delete, is not.
	'fluentcart_product_inventory_update',
	'fluentcart_product_manage_stock_update',
	'fluentcart_product_taxonomy_delete',
	'fluentcart_product_taxonomy_sync',
]

/**
 * Moves money through a gateway. Shipped UNAVAILABLE in 2.0.0.
 *
 * The guard itself is complete and unit-tested — signed state-pinned previews, a durable
 * single-writer ledger, replay and conflict detection, and ambiguous-crash handling that never
 * auto-retries. What does not exist is acceptance evidence, and for a money-moving action that
 * distinction is the whole argument.
 *
 * Proving a refund end to end needs an order this run created, refunded and then removed. Four
 * verified FluentCart 1.5.5 limits make that impossible: there is no DELETE route for a
 * transaction; deleting an order does not cascade to its transactions; `canPurchase()` rejects
 * any non-published product, so no hidden draft fixture; and a run cannot create a subscription
 * at all. Any fixture capable of being refunded therefore leaves rows the API cannot remove.
 *
 * Plan 08's rule is that a guarded capability either passes both acceptance lanes or ships
 * unavailable. It cannot pass here, so `execution: 'none'` is the honest setting and the tools
 * are absent from every write mode. Restore `guarded-rest` when FluentCart exposes a way to
 * remove a test-mode charge and the guarded lanes can run for real.
 */
const GUARDED_REAL_MONEY = ['fluentcart_order_refund', 'fluentcart_subscription_cancel']

/** Money-adjacent actions with no safe repeatable contract. Never executable. */
const UNSUPPORTED_REAL_MONEY = [
	'fluentcart_order_accept_dispute',
	'fluentcart_order_mark_paid',
	'fluentcart_order_transaction_update_status',
	'fluentcart_order_generate_licenses',
]

/** Removes data, or overwrites it in bulk, with no supported restore. */
const DESTRUCTIVE_WRITES = [
	'fluentcart_activity_delete',
	'fluentcart_attribute_group_delete',
	'fluentcart_attribute_term_delete',
	'fluentcart_coupon_delete',
	'fluentcart_customer_address_delete',
	'fluentcart_customer_bulk_action',
	'fluentcart_integration_delete_feed',
	'fluentcart_order_bulk_action',
	'fluentcart_order_bump_delete',
	'fluentcart_order_delete',
	'fluentcart_order_update_statuses',
	'fluentcart_order_sync_statuses',
	'fluentcart_product_bulk_action',
	'fluentcart_product_delete',
	'fluentcart_product_downloadable_delete',
	'fluentcart_product_integration_delete',
	'fluentcart_product_shipping_class_remove',
	'fluentcart_product_tax_class_remove',
	'fluentcart_product_upgrade_path_delete',
	'fluentcart_shipping_class_delete',
	'fluentcart_shipping_method_delete',
	'fluentcart_shipping_zone_delete',
	'fluentcart_tax_class_delete',
	'fluentcart_tax_country_delete_all',
	'fluentcart_tax_rate_delete',
	'fluentcart_tax_shipping_override_delete',
	'fluentcart_variant_delete',
	// Irreversible in the weaker sense: they overwrite or add state with no supported restore.
	// FluentCart offers no route to undo any of these, so none may be called reversible.
	'fluentcart_activity_mark_read',
	'fluentcart_attribute_term_reorder',
	'fluentcart_customer_address_create',
	'fluentcart_customer_address_make_primary',
	'fluentcart_customer_address_update',
	'fluentcart_customer_recalculate_ltv',
	'fluentcart_customer_update_additional_info',
	'fluentcart_label_create',
	'fluentcart_label_update_selections',
	'fluentcart_note_attach',
	'fluentcart_product_bundle_save',
	'fluentcart_product_downloadable_update',
	'fluentcart_product_pricing_update',
	'fluentcart_product_shipping_class_update',
	'fluentcart_product_tax_class_update',
	// Creates vocabulary terms, and FluentCart 1.5.5 registers no route that deletes one: the only
	// term DELETE in api.php is attr/group/{id}/term/{id}, which belongs to the attribute library,
	// a different taxonomy. A created category therefore cannot be removed through the API, so this
	// stays irreversible however ordinary it looks. Assigning existing terms to a product IS
	// reversible and is handled by fluentcart_product_taxonomy_sync.
	'fluentcart_product_terms_add',
	'fluentcart_product_variant_option_update',
	'fluentcart_shipping_zone_reorder',
	'fluentcart_variant_pricing_table_update',
	'fluentcart_variant_set_media',
]

/** Changes who may do what, or what code runs in the store. */
const CONTROL_PLANE_WRITES = [
	'fluentcart_integration_change_feed_status',
	'fluentcart_integration_install_plugin',
	'fluentcart_integration_save_feed_settings',
	'fluentcart_product_editor_mode_update',
	'fluentcart_product_integration_feed_status',
	'fluentcart_product_integration_save',
	'fluentcart_role_create',
	'fluentcart_role_delete',
	'fluentcart_role_update',
	'fluentcart_settings_save_modules',
	'fluentcart_settings_save_permissions',
	'fluentcart_customer_attach_user',
	'fluentcart_customer_detach_user',
	'fluentcart_coupon_settings_save',
	'fluentcart_settings_print_templates_save',
	'fluentcart_settings_save_confirmation',
	'fluentcart_settings_save_store',
	'fluentcart_tax_config_countries_save',
	'fluentcart_tax_country_id_save',
	'fluentcart_tax_eu_vat_save',
]

/** Touches gateway keys, provider credentials or a login session. */
const CREDENTIAL_BEARING_WRITES = [
	'fluentcart_integration_save_global_settings',
	'fluentcart_public_user_login',
	'fluentcart_settings_save_payment_method',
	'fluentcart_settings_reorder_payment_methods',
]

/** Files, storage, generated fixtures and other runtime plumbing. */
const INFRASTRUCTURE_WRITES = [
	'fluentcart_app_upload_attachment',
	'fluentcart_file_delete',
	'fluentcart_file_upload',
	'fluentcart_product_create_dummy',
	'fluentcart_product_duplicate',
	'fluentcart_product_sync_downloadable_files',
	'fluentcart_report_retention_snapshots_generate',
	'fluentcart_tax_records_mark_filed',
]

/** Leaves the store: email delivery, cart-session mutation, third-party calls. */
const EXTERNAL_SIDE_EFFECT_WRITES = [
	'fluentcart_coupon_apply',
	'fluentcart_coupon_cancel',
	'fluentcart_coupon_reapply',
	'fluentcart_email_settings_save',
	'fluentcart_email_template_preview',
	'fluentcart_email_toggle',
	'fluentcart_email_update',
	'fluentcart_order_change_customer',
	'fluentcart_order_create',
	'fluentcart_order_create_and_change_customer',
	'fluentcart_order_create_custom',
	'fluentcart_order_update',
	'fluentcart_order_update_address',
	'fluentcart_order_update_address_id',
]

function rows(names: readonly string[], safety: ToolSafety): Array<[string, ToolSafety]> {
	return names.map((name) => [name, safety])
}

const REGISTRY = new Map<string, ToolSafety>([
	...rows(POST_SHAPED_READS, READ_SAFETY),
	...rows(REVERSIBLE_CREATES, {
		risk: 'reversible-write',
		idempotency: 'unsupported',
		execution: 'rest',
	}),
	...rows(REVERSIBLE_UPDATES, {
		risk: 'reversible-write',
		idempotency: 'inherent',
		execution: 'rest',
	}),
	...rows(GUARDED_REAL_MONEY, {
		risk: 'real-money',
		idempotency: 'guard-required',
		// Unavailable in 2.0.0: built and unit-tested, never acceptance-proven. See above.
		execution: 'none',
	}),
	...rows(UNSUPPORTED_REAL_MONEY, {
		risk: 'real-money',
		idempotency: 'unsupported',
		execution: 'none',
	}),
	...rows(DESTRUCTIVE_WRITES, {
		risk: 'destructive-write',
		idempotency: 'unsupported',
		execution: 'none',
	}),
	...rows(CONTROL_PLANE_WRITES, {
		risk: 'control-plane',
		idempotency: 'unsupported',
		execution: 'none',
	}),
	...rows(CREDENTIAL_BEARING_WRITES, {
		risk: 'credential-bearing',
		idempotency: 'unsupported',
		execution: 'none',
	}),
	...rows(INFRASTRUCTURE_WRITES, {
		risk: 'infrastructure',
		idempotency: 'unsupported',
		execution: 'none',
	}),
	...rows(EXTERNAL_SIDE_EFFECT_WRITES, {
		risk: 'external-side-effect',
		idempotency: 'unsupported',
		execution: 'none',
	}),
])

/**
 * Resolve the reviewed safety row for a tool.
 *
 * `isReadOnly` only supplies the default for tools with no row: a read stays a read, and an
 * unclassified write becomes `unreviewed-write` so the exposure policy hides it.
 */
export function resolveToolSafety(name: string, isReadOnly: boolean): ToolSafety {
	const reviewed = REGISTRY.get(name)
	if (reviewed) return reviewed
	return isReadOnly ? READ_SAFETY : UNREVIEWED_WRITE_SAFETY
}

/** Exact names carrying a reviewed row, for completeness tests. */
export function reviewedToolNames(): string[] {
	return [...REGISTRY.keys()].sort()
}

export function reviewedRisk(name: string): ToolRisk | null {
	return REGISTRY.get(name)?.risk ?? null
}
