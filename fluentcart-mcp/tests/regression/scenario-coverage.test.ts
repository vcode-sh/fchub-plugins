import { describe, expect, it } from 'vitest'
import { createClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

/**
 * Durable replacement for the deleted live-QA scenario programs.
 *
 * Seventeen loose programs used to live in `tests/`: `_cleanup.ts`, `_tiger-pants-flow.ts`,
 * `live-qa.ts` and fourteen `_scenarios-*.ts` files. Every one of them loaded store credentials
 * directly, ran against whatever data the store happened to contain, and was invoked by hand.
 * Their disposition:
 *
 * | Programme | Mutation targets | Ownership defect | Disposition |
 * |---|---|---|---|
 * | `_cleanup.ts` | deletes hard-coded numeric ids | deletes records no run created | dropped |
 * | `_scenarios-orders-audit.ts` | refunds a DISCOVERED charge, mutates an existing order | real-money action on someone else's record | dropped; refund is now a guarded action |
 * | `_scenarios-reports-new.ts` | order status of discovered orders | mutates pre-existing records | dropped |
 * | `_scenarios-complex-1.ts`, `-complex-2.ts` | creates products/coupons/customers | creates without registering or deleting | names preserved below |
 * | `_scenarios-simple-1.ts`, `-simple-2.ts` | read-mostly tool sweeps | loads `.env` directly | names preserved below |
 * | `_scenarios-untested-tools.ts` | broad tool sweep | loads `.env` directly | names preserved below |
 * | `_scenarios-settings.ts`, `-tax.ts`, `-shipping.ts` | configuration writes | no restore of prior values | names preserved below |
 * | `_scenarios-round3-admin.ts`, `-round4-remediation.ts` | admin/role writes | control-plane writes without review | names preserved below |
 * | `_scenarios-email-roles-files.ts` | sends email, mutates roles | external side effects | names preserved below |
 * | `_scenarios-perf-audit.ts` | timing sweep over reads | loads `.env` directly | names preserved below |
 * | `_tiger-pants-flow.ts` | end-to-end purchase flow | creates orders it cannot remove | dropped |
 * | `live-qa.ts` | broad manual QA incl. cancellation | mutates discovered subscriptions | dropped |
 *
 * The only deterministic, network-free value those programmes carried was the set of public
 * tool names they exercised. That set is asserted here, so a tool cannot vanish silently. A
 * tool that is deliberately withdrawn must be moved to WITHDRAWN_TOOLS with a reason — the
 * point is to force a decision, not to freeze the registry.
 */

/** Public names exercised by the removed live-QA programmes. */
const SCENARIO_COVERED_TOOLS = [
	'fluentcart_activity_list',
	'fluentcart_app_get_attachments',
	'fluentcart_app_get_widgets',
	'fluentcart_app_init',
	'fluentcart_attribute_group_create',
	'fluentcart_attribute_group_delete',
	'fluentcart_attribute_group_get',
	'fluentcart_attribute_group_list',
	'fluentcart_attribute_term_create',
	'fluentcart_attribute_term_list',
	'fluentcart_coupon_create',
	'fluentcart_coupon_delete',
	'fluentcart_coupon_get',
	'fluentcart_coupon_list',
	'fluentcart_coupon_list_alt',
	'fluentcart_coupon_settings_get',
	'fluentcart_customer_addresses',
	'fluentcart_customer_attachable_users',
	'fluentcart_customer_get',
	'fluentcart_customer_list',
	'fluentcart_customer_orders_simple',
	'fluentcart_customer_recalculate_ltv',
	'fluentcart_customer_stats',
	'fluentcart_dashboard_onboarding',
	'fluentcart_dashboard_overview',
	'fluentcart_email_get',
	'fluentcart_email_list',
	'fluentcart_email_settings_get',
	'fluentcart_email_settings_save',
	'fluentcart_email_shortcodes',
	'fluentcart_email_toggle',
	'fluentcart_file_bucket_list',
	'fluentcart_file_delete',
	'fluentcart_file_list',
	'fluentcart_integration_get_feed_settings',
	'fluentcart_integration_get_global_feeds',
	'fluentcart_integration_get_global_settings',
	'fluentcart_integration_list_addons',
	'fluentcart_label_create',
	'fluentcart_label_list',
	'fluentcart_misc_countries',
	'fluentcart_misc_country_info',
	'fluentcart_misc_filter_options',
	'fluentcart_misc_form_search_options',
	'fluentcart_note_attach',
	'fluentcart_order_bulk_action',
	'fluentcart_order_bump_list',
	'fluentcart_order_calculate_shipping',
	'fluentcart_order_change_customer',
	'fluentcart_order_create',
	'fluentcart_order_create_and_change_customer',
	'fluentcart_order_create_custom',
	'fluentcart_order_customer_orders',
	'fluentcart_order_delete',
	'fluentcart_order_get',
	'fluentcart_order_list',
	'fluentcart_order_mark_paid',
	'fluentcart_order_refund',
	'fluentcart_order_shipping_methods',
	'fluentcart_order_transaction_get',
	'fluentcart_order_transactions',
	'fluentcart_order_update',
	'fluentcart_order_update_address',
	'fluentcart_order_update_statuses',
	'fluentcart_payment_get_all',
	'fluentcart_payment_get_settings',
	'fluentcart_product_bulk_action',
	'fluentcart_product_bundle_info',
	'fluentcart_product_create',
	'fluentcart_product_delete',
	'fluentcart_product_fetch_by_ids',
	'fluentcart_product_find_subscription_variants',
	'fluentcart_product_get',
	'fluentcart_product_integration_settings',
	'fluentcart_product_integrations',
	'fluentcart_product_inventory_update',
	'fluentcart_product_list',
	'fluentcart_product_manage_stock_update',
	'fluentcart_product_pricing_get',
	'fluentcart_product_pricing_update',
	'fluentcart_product_search_by_name',
	'fluentcart_product_search_variant_by_name',
	'fluentcart_product_search_variant_options',
	'fluentcart_product_shipping_class_remove',
	'fluentcart_product_shipping_class_update',
	'fluentcart_product_suggest_sku',
	'fluentcart_product_tax_class_remove',
	'fluentcart_product_tax_class_update',
	'fluentcart_product_taxonomy_sync',
	'fluentcart_product_terms',
	'fluentcart_product_terms_add',
	'fluentcart_product_terms_by_parent',
	'fluentcart_public_product_search',
	'fluentcart_public_product_views',
	'fluentcart_public_products',
	'fluentcart_public_user_login',
	'fluentcart_report_cart',
	'fluentcart_report_country_heat_map',
	'fluentcart_report_customer',
	'fluentcart_report_daily_signups',
	'fluentcart_report_dashboard_stats',
	'fluentcart_report_dashboard_summary',
	'fluentcart_report_day_and_hour',
	'fluentcart_report_future_renewals',
	'fluentcart_report_item_count_distribution',
	'fluentcart_report_license_chart',
	'fluentcart_report_license_pie_chart',
	'fluentcart_report_license_summary',
	'fluentcart_report_meta',
	'fluentcart_report_new_vs_returning',
	'fluentcart_report_order_chart',
	'fluentcart_report_order_completion_time',
	'fluentcart_report_order_value_distribution',
	'fluentcart_report_orders_by_group',
	'fluentcart_report_overview',
	'fluentcart_report_product',
	'fluentcart_report_product_performance',
	'fluentcart_report_quick_order_stats',
	'fluentcart_report_recent_activities',
	'fluentcart_report_recent_orders',
	'fluentcart_report_refund_by_group',
	'fluentcart_report_refund_chart',
	'fluentcart_report_repeat_customers',
	'fluentcart_report_retention_chart',
	'fluentcart_report_retention_snapshots_generate',
	'fluentcart_report_retention_snapshots_status',
	'fluentcart_report_revenue',
	'fluentcart_report_revenue_by_group',
	'fluentcart_report_sales',
	'fluentcart_report_sales_growth',
	'fluentcart_report_sales_growth_chart',
	'fluentcart_report_sources',
	'fluentcart_report_subscription_chart',
	'fluentcart_report_subscription_cohorts',
	'fluentcart_report_subscription_retention',
	'fluentcart_report_summary',
	'fluentcart_report_top_products_sold',
	'fluentcart_report_top_sold_products',
	'fluentcart_report_top_sold_variants',
	'fluentcart_report_unfulfilled_orders',
	'fluentcart_report_weeks_between_refund',
	'fluentcart_role_create',
	'fluentcart_role_delete',
	'fluentcart_role_get',
	'fluentcart_role_list',
	'fluentcart_role_managers',
	'fluentcart_role_update',
	'fluentcart_role_user_list',
	'fluentcart_settings_get_confirmation_shortcodes',
	'fluentcart_settings_get_modules',
	'fluentcart_settings_get_permissions',
	'fluentcart_settings_get_store',
	'fluentcart_settings_print_templates_get',
	'fluentcart_settings_print_templates_save',
	'fluentcart_settings_reorder_payment_methods',
	'fluentcart_settings_save_confirmation',
	'fluentcart_settings_save_modules',
	'fluentcart_settings_save_permissions',
	'fluentcart_settings_save_store',
	'fluentcart_shipping_class_create',
	'fluentcart_shipping_class_delete',
	'fluentcart_shipping_class_list',
	'fluentcart_shipping_class_update',
	'fluentcart_shipping_method_create',
	'fluentcart_shipping_method_delete',
	'fluentcart_shipping_zone_create',
	'fluentcart_shipping_zone_delete',
	'fluentcart_shipping_zone_get',
	'fluentcart_shipping_zone_list',
	'fluentcart_shipping_zone_reorder',
	'fluentcart_shipping_zone_states',
	'fluentcart_shipping_zone_update',
	'fluentcart_subscription_fetch',
	'fluentcart_subscription_get',
	'fluentcart_subscription_list',
	'fluentcart_tax_class_create',
	'fluentcart_tax_class_delete',
	'fluentcart_tax_class_list',
	'fluentcart_tax_class_update',
	'fluentcart_tax_config_rates',
	'fluentcart_tax_eu_rates',
	'fluentcart_tax_rate_country',
	'fluentcart_tax_rate_create',
	'fluentcart_tax_rate_delete',
	'fluentcart_tax_rate_list',
	'fluentcart_tax_rate_update',
	'fluentcart_tax_records_list',
	'fluentcart_tax_settings_get',
	'fluentcart_tax_settings_save',
	'fluentcart_variant_create',
	'fluentcart_variant_fetch_by_ids',
	'fluentcart_variant_list',
	'fluentcart_variant_list_all',
	'fluentcart_variant_pricing_table_update',
	'fluentcart_variant_update',
] as const

/**
 * Tools deliberately withdrawn since the scenarios were written. Each needs a reason; the
 * assertion below fails if a withdrawn name is still registered, so this list cannot rot.
 */
const WITHDRAWN_TOOLS: Record<string, string> = {
	fluentcart_customer_address_select:
		'Never existed in the direct registry. The scenario invoked a name the server does not register; the underlying route is GET /customers/{id}/update-address-select.',
	fluentcart_report_cart:
		'FluentCart 1.5.5 does not register the cart report route. Plan 03 Task 4 omits it rather than shipping a tool that 404s; no replacement analytics were invented.',
	fluentcart_report_unfulfilled_orders:
		'/reports/get-unfulfilled-orders is absent from the 1.5.5 registry and returns 404. Plan 03 Task 4 omits it pending a semantically tested alternative.',
	fluentcart_tax_class_update:
		'The 1.5.5 registry serves only DELETE at /tax/classes/{id}. Registering an update there would advertise an edit the store cannot perform.',
}

/**
 * Dynamic-mode meta tools. They are registered by registerDynamicTools(), not createAllTools(),
 * so they are asserted separately from the REST-backed registry.
 */
const DYNAMIC_META_TOOLS = [
	'fluentcart_search_tools',
	'fluentcart_describe_tools',
	'fluentcart_execute_tool',
] as const

const registry = createAllTools(
	createClient({
		url: 'https://fixture.invalid',
		username: 'fixture',
		appPassword: 'fixture',
		adminBase: 'https://fixture.invalid/wp-json/fluent-cart/v2',
		publicBase: 'https://fixture.invalid/wp-json/fluent-cart-public/v2',
	}),
)
const registeredNames = new Set(registry.map((tool) => tool.name))

describe('scenario coverage regression', () => {
	it('still registers every tool the removed live-QA programmes exercised', () => {
		const missing = SCENARIO_COVERED_TOOLS.filter(
			(name) => !(registeredNames.has(name) || name in WITHDRAWN_TOOLS),
		)

		expect(missing).toEqual([])
	})

	it('does not register a tool that was deliberately withdrawn', () => {
		const resurrected = Object.keys(WITHDRAWN_TOOLS).filter((name) => registeredNames.has(name))
		expect(resurrected).toEqual([])
	})

	it('gives every withdrawn tool a non-empty reason', () => {
		for (const [name, reason] of Object.entries(WITHDRAWN_TOOLS)) {
			expect(reason, `${name} must record why it was withdrawn`).not.toBe('')
		}
	})

	it('keeps the dynamic meta tools out of the REST-backed registry', () => {
		for (const name of DYNAMIC_META_TOOLS) {
			expect(registeredNames.has(name)).toBe(false)
		}
	})

	it('keeps the fluentcart_{resource}_{action} naming convention', () => {
		for (const tool of registry) {
			expect(tool.name).toMatch(/^fluentcart_[a-z0-9]+(_[a-z0-9]+)+$/)
		}
	})

	it('registers no duplicate public tool name', () => {
		expect(registeredNames.size).toBe(registry.length)
	})
})
