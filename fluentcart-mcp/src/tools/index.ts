import type { FluentCartClient } from '../api/client.js'
import type { ToolDefinition } from './_factory.js'

// v1.0 tool modules will be imported here as they're built:
// import { orderTools } from './orders.js'
// import { productTools } from './products.js'
// import { customerTools } from './customers.js'
// import { subscriptionTools } from './subscriptions.js'
// import { couponTools } from './coupons.js'
// import { reportTools } from './reports.js'
// import { orderBumpTools } from './order-bumps.js'
// import { productOptionTools } from './product-options.js'
// import { integrationTools } from './integrations.js'
// import { labelTools } from './labels.js'
// import { activityTools } from './activity.js'
// import { noteTools } from './notes.js'
// import { dashboardTools } from './dashboard.js'
// import { applicationTools } from './application.js'
// import { settingsCoreTools } from './settings-core.js'
// import { publicTools } from './public.js'
// import { miscTools } from './misc.js'

export function createAllTools(_client: FluentCartClient): ToolDefinition[] {
	return [
		// ...orderTools(client),
		// ...productTools(client),
		// ...customerTools(client),
		// ...subscriptionTools(client),
		// ...couponTools(client),
		// ...reportTools(client),
		// ...orderBumpTools(client),
		// ...productOptionTools(client),
		// ...integrationTools(client),
		// ...labelTools(client),
		// ...activityTools(client),
		// ...noteTools(client),
		// ...dashboardTools(client),
		// ...applicationTools(client),
		// ...settingsCoreTools(client),
		// ...publicTools(client),
		// ...miscTools(client),
	]
}
