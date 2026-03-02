import type { FluentCartClient } from '../api/client.js'
import type { ToolDefinition } from './_factory.js'
import { activityTools } from './activity.js'
import { applicationTools } from './application.js'
import { couponTools } from './coupons.js'
import { customerTools } from './customers.js'
import { dashboardTools } from './dashboard.js'
import { integrationTools } from './integrations.js'
import { labelTools } from './labels.js'
import { miscTools } from './misc.js'
import { noteTools } from './notes.js'
import { orderBumpTools } from './order-bumps.js'
import { orderCoreTools } from './orders-core.js'
import { orderTransactionTools } from './orders-transactions.js'
import { productOptionTools } from './product-options.js'
import { productCatalogTools } from './products-catalog.js'
import { productCoreTools } from './products-core.js'
import { productPricingTools } from './products-pricing.js'
import { productVariantTools } from './products-variants.js'
import { publicTools } from './public.js'
import { reportCoreTools } from './reports-core.js'
import { reportInsightTools } from './reports-insights.js'
import { settingsCoreTools } from './settings-core.js'
import { subscriptionTools } from './subscriptions.js'

export function createAllTools(client: FluentCartClient): ToolDefinition[] {
	return [
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
	]
}
