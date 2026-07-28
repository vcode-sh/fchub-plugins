import type { ApiCapabilities } from '../api/capabilities.js'
import type { FluentCartClient } from '../api/client.js'
import type { GuardRuntime } from '../security/guard-config.js'
import { configureToolCache, type ToolCacheDeps, type ToolDefinition } from './_factory.js'
import { activityTools } from './activity.js'
import { applicationTools } from './application.js'
import { commerceReportingTools } from './commerce-reporting.js'
import { commerceSearchTools } from './commerce-search.js'
import { couponTools } from './coupons.js'
import { couponWriteTools } from './coupons-writes.js'
import { customerTools } from './customers.js'
import { customerWriteTools } from './customers-writes.js'
import { dashboardTools } from './dashboard.js'
import { emailNotificationTools } from './email-notifications.js'
import { fileTools } from './files.js'
import { integrationTools } from './integrations.js'
import { labelTools } from './labels.js'
import { miscTools } from './misc.js'
import { noteTools } from './notes.js'
import { orderBumpTools } from './order-bumps.js'
import { orderCoreTools } from './orders-core.js'
import { orderCustomerTools } from './orders-customer.js'
import { orderLifecycleTools } from './orders-lifecycle.js'
import { orderRefundTools } from './orders-refunds.js'
import { orderTransactionTools } from './orders-transactions.js'
import { pdfTemplateTools } from './pdf-templates.js'
import { productOptionTools } from './product-options.js'
import { productOptionTermTools } from './product-options-terms.js'
import { productBulkEditTools } from './products-bulk-edit.js'
import { productCatalogTools } from './products-catalog.js'
import { productCoreTools } from './products-core.js'
import { productPricingTools } from './products-pricing.js'
import { productPricingWriteTools } from './products-pricing-writes.js'
import { productVariantWriteTools } from './products-variant-writes.js'
import { productVariantTools } from './products-variants.js'
import { publicTools } from './public.js'
import { reportCoreTools } from './reports-core.js'
import { reportInsightTools } from './reports-insights.js'
import { roleTools } from './roles.js'
import { savedViewTools } from './saved-views.js'
import { settingsCoreTools } from './settings-core.js'
import { shippingTools } from './shipping.js'
import { shippingProfileTools } from './shipping-profile.js'
import { subscriptionTools } from './subscriptions.js'
import { subscriptionCancellationTools } from './subscriptions-cancellation.js'
import { taxTools } from './tax.js'
import { taxClassTools } from './tax-classes.js'
import { taxConfigurationTools } from './tax-configuration.js'
import { taxEuVatTools } from './tax-eu-vat.js'
import { taxProductOverrideTools } from './tax-product-overrides.js'

export function createAllTools(
	client: FluentCartClient,
	options: {
		guard?: GuardRuntime | null
		capabilities?: ApiCapabilities
		cache?: ToolCacheDeps
	} = {},
): ToolDefinition[] {
	const guard = options.guard ?? null
	const { capabilities } = options
	configureToolCache(client, options.cache)

	return [
		...orderRefundTools(client, guard),
		...subscriptionCancellationTools(client, guard),
		// Split out of tax.ts; both select their route from live capability evidence, so a store
		// that only serves DELETE at /tax/classes/{id} never sees an update tool it cannot honour.
		...taxClassTools(client, capabilities),
		...taxConfigurationTools(client),
		...taxEuVatTools(client, capabilities),
		// Split out of customers.ts, orders-core.ts and products-pricing.ts along the read/write
		// boundary to keep every module inside the 280-line limit. Registering them here is not
		// optional: a split-out factory that nobody imports silently removes its tools.
		...customerWriteTools(client),
		...orderCustomerTools(client),
		...orderLifecycleTools(client),
		...productPricingWriteTools(client),
		...couponWriteTools(client),
		...productVariantWriteTools(client),
		...productOptionTermTools(client, capabilities),
		...commerceSearchTools(client),
		...commerceReportingTools(client),
		// Plan 06 read candidates: dynamic-only on arrival, never straight into curated.
		...savedViewTools(client),
		...pdfTemplateTools(client),
		...productBulkEditTools(client),
		...shippingProfileTools(client),
		...taxProductOverrideTools(client),
		...subscriptionTools(client),
		...couponTools(client),
		...orderCoreTools(client),
		...orderTransactionTools(client),
		...customerTools(client),
		...productCoreTools(client),
		...productPricingTools(client),
		...productVariantTools(client),
		...productCatalogTools(client),
		...reportCoreTools(client),
		...reportInsightTools(client),
		...orderBumpTools(client),
		...productOptionTools(client),
		...integrationTools(client),
		...settingsCoreTools(client),
		...labelTools(client),
		...activityTools(client),
		...noteTools(client),
		...dashboardTools(client),
		...applicationTools(client),
		...publicTools(client),
		...miscTools(client),
		...shippingTools(client),
		...taxTools(client),
		...emailNotificationTools(client),
		...roleTools(client),
		...fileTools(client),
	]
}
