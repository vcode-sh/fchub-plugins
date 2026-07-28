import { createHash } from 'node:crypto'
import type { WriteMode } from '../security/write-policy.js'

/**
 * A compact answer to "what store am I talking to, and what can I do here?".
 *
 * Everything here is either identity or capability. No raw settings, no payment configuration, no
 * user identity and no route list: an agent that needs those should ask for them explicitly, and a
 * context object that quietly carried them would leak store configuration into every conversation.
 */
export interface CommerceContext {
	store: {
		origin: string
		name: string | null
		currency: string | null
		timezone: string | null
	}
	runtime: {
		wordpress: string | null
		fluentcartCore: string | null
		fluentcartPro: string | null
		routeProfileDigest: string
	}
	capabilities: {
		entities: string[]
		reports: string[]
		writeMode: WriteMode
	}
	warnings: string[]
}

export interface RuntimeComponent {
	slug: string
	version: string
}

export interface RuntimeProfile {
	wordpress: string
	activeComponents: RuntimeComponent[]
}

/**
 * The only keys this projection will read out of `/app/init`'s `shop` block.
 *
 * `shopConfig()` merges currency settings with store settings, and the surrounding payload also
 * carries a `rest` block. Reading by allowlist means a future FluentCart release that adds a nonce,
 * a key or a customer detail to either cannot widen what this tool returns.
 */
export const SAFE_SHOP_KEYS = ['store_name', 'currency', 'timezone'] as const

/**
 * Reviewed capability probes: a stable public name paired with the tool whose presence proves it.
 *
 * Presence is decided by the already-filtered registry, so a store that hides writes, or a role
 * that cannot read customers, reports fewer capabilities rather than a cheerful list of things
 * the caller will then be refused.
 */
export const ENTITY_PROBES: Readonly<Record<string, string>> = {
	activities: 'fluentcart_activity_list',
	coupons: 'fluentcart_coupon_list',
	customers: 'fluentcart_customer_list',
	email_notifications: 'fluentcart_email_list',
	files: 'fluentcart_file_list',
	labels: 'fluentcart_label_list',
	order_bumps: 'fluentcart_order_bump_list',
	orders: 'fluentcart_order_list',
	product_attributes: 'fluentcart_attribute_group_list',
	products: 'fluentcart_product_list',
	subscriptions: 'fluentcart_subscription_list',
}

export const REPORT_PROBES: Readonly<Record<string, string>> = {
	dashboard_stats: 'fluentcart_report_dashboard_stats',
	orders_by_group: 'fluentcart_report_orders_by_group',
	overview: 'fluentcart_report_overview',
	product_performance: 'fluentcart_report_product_performance',
	quick_order_stats: 'fluentcart_report_quick_order_stats',
	refund_chart: 'fluentcart_report_refund_chart',
	revenue: 'fluentcart_report_revenue',
	revenue_by_group: 'fluentcart_report_revenue_by_group',
	sales: 'fluentcart_report_sales',
	sales_growth: 'fluentcart_report_sales_growth',
}

const CORE_SLUG = 'fluent-cart'
const PRO_SLUG = 'fluent-cart-pro'

/**
 * Identify the route profile without reproducing it.
 *
 * Deliberately the same normalisation the read-contract capture uses, so a context digest and a
 * fixture digest taken from one runtime are comparable rather than merely similar-looking.
 */
export function routeProfileDigest(
	profile: RuntimeProfile | null,
	operations: readonly unknown[] | null = null,
): string {
	const normalised = profile
		? {
				wordpress: profile.wordpress,
				activeComponents: [...profile.activeComponents]
					.map((component) => ({ slug: component.slug, version: component.version }))
					.sort((left, right) => (left.slug < right.slug ? -1 : 1)),
			}
		: null
	const digest = createHash('sha256')
		.update(JSON.stringify({ profile: normalised, operations }))
		.digest('hex')
	return `sha256:${digest}`
}

function componentVersion(profile: RuntimeProfile | null, slug: string): string | null {
	if (profile === null) return null
	return profile.activeComponents.find((component) => component.slug === slug)?.version ?? null
}

/** Trim to a non-empty string, or nothing. An empty setting is absent, not a value. */
function text(value: unknown): string | null {
	return typeof value === 'string' && value.trim() !== '' ? value.trim() : null
}

/** Origin only. A path, query or credential in the configured URL is not store identity. */
export function storeOrigin(url: string): string {
	return new URL(url).origin
}

export interface ContextInput {
	origin: string
	/** The `shop` block of `/app/init`, read by allowlist. */
	shop: Record<string, unknown> | null
	profile: RuntimeProfile | null
	/** Canonical operations backing the profile, when the caller has them. */
	operations?: readonly unknown[] | null
	/** Names already filtered by capability discovery and the write-exposure policy. */
	exposedToolNames: readonly string[]
	writeMode: WriteMode
}

function presentNames(
	probes: Readonly<Record<string, string>>,
	exposed: ReadonlySet<string>,
): string[] {
	return Object.keys(probes)
		.filter((name) => exposed.has(probes[name] as string))
		.sort()
}

/**
 * Project verified inputs into the compact context.
 *
 * Pure on purpose: every value is supplied by the caller, so a test can prove what this returns
 * for a core-only store, a store with no currency configured or a drifted route profile without
 * standing up a WordPress site to ask.
 */
export function buildCommerceContext(input: ContextInput): CommerceContext {
	const shop = input.shop ?? {}
	const exposed = new Set(input.exposedToolNames)
	const warnings: string[] = []

	const core = componentVersion(input.profile, CORE_SLUG)
	if (input.profile !== null && core === null) {
		throw new Error(`Runtime profile lists no ${CORE_SLUG}; it is not a FluentCart store.`)
	}

	const name = text(shop.store_name)
	const currency = text(shop.currency)
	const timezone = text(shop.timezone)

	// One short warning per genuinely missing optional value. Never a guessed default: a currency
	// invented here would be silently attached to every monetary answer that followed.
	if (name === null) warnings.push('Store name is not configured.')
	if (currency === null)
		warnings.push('Store currency is not configured; monetary values have no unit.')
	if (timezone === null)
		warnings.push('Store timezone is not exposed; date boundaries are unverified.')
	if (input.profile === null) {
		warnings.push(
			'Runtime versions are not exposed by FluentCart; route capabilities are verified independently.',
		)
	}

	return {
		store: { origin: input.origin, name, currency, timezone },
		runtime: {
			wordpress: input.profile?.wordpress ?? null,
			fluentcartCore: core,
			fluentcartPro: componentVersion(input.profile, PRO_SLUG),
			routeProfileDigest: routeProfileDigest(input.profile, input.operations ?? null),
		},
		capabilities: {
			entities: presentNames(ENTITY_PROBES, exposed),
			reports: presentNames(REPORT_PROBES, exposed),
			writeMode: input.writeMode,
		},
		warnings,
	}
}
