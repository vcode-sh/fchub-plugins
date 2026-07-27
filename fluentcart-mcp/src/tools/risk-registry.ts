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
const REVERSIBLE_WRITES = [
	'fluentcart_attribute_group_create',
	'fluentcart_attribute_group_update',
	'fluentcart_attribute_term_create',
	'fluentcart_attribute_term_update',
	'fluentcart_coupon_create',
	'fluentcart_coupon_update',
	'fluentcart_customer_create',
	'fluentcart_customer_update',
	'fluentcart_order_bump_create',
	'fluentcart_order_bump_update',
	'fluentcart_product_create',
	'fluentcart_product_update_detail',
	'fluentcart_product_upgrade_path_save',
	'fluentcart_product_upgrade_path_update',
	'fluentcart_shipping_class_create',
	'fluentcart_shipping_class_update',
	'fluentcart_shipping_method_create',
	'fluentcart_shipping_method_update',
	'fluentcart_shipping_zone_create',
	'fluentcart_shipping_zone_update',
	'fluentcart_tax_class_create',
	'fluentcart_tax_rate_create',
	'fluentcart_tax_rate_update',
	'fluentcart_tax_shipping_override_create',
	'fluentcart_variant_create',
	'fluentcart_variant_update',
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
	'fluentcart_product_taxonomy_delete',
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
	'fluentcart_product_inventory_update',
	'fluentcart_product_manage_stock_update',
	'fluentcart_product_pricing_update',
	'fluentcart_product_shipping_class_update',
	'fluentcart_product_tax_class_update',
	'fluentcart_product_taxonomy_sync',
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
	'fluentcart_tax_settings_save',
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
	...rows(REVERSIBLE_WRITES, {
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
