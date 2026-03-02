import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, postTool, type ToolDefinition } from './_factory.js'

export function publicTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_public_product_views',
			title: 'Get Public Product Views',
			description:
				'Retrieve product view data for the storefront. No authentication required. ' +
				'Returns publicly visible product information for frontend display.',
			schema: z.object({}),
			endpoint: '/public/product-views',
			isPublic: true,
		}),

		getTool(client, {
			name: 'fluentcart_public_product_search',
			title: 'Search Public Products',
			description:
				'Search published products by name. No authentication required. ' +
				'Returns matching products visible on the storefront.',
			schema: z.object({
				search: z.string().optional().describe('Search query to filter products by name'),
			}),
			endpoint: '/public/product-search',
			isPublic: true,
		}),

		getTool(client, {
			name: 'fluentcart_public_products',
			title: 'List Public Products',
			description:
				'Retrieve publicly available products. No authentication required. ' +
				'Returns the full public product catalogue for storefront display.',
			schema: z.object({}),
			endpoint: '/public/products',
			isPublic: true,
		}),

		postTool(client, {
			name: 'fluentcart_public_user_login',
			title: 'User Login',
			description:
				'Frontend login endpoint. Authenticates a user by email and password, ' +
				'returning customer data with an authentication token.',
			schema: z.object({
				email: z.string().describe('User email address'),
				password: z.string().describe('User password'),
			}),
			endpoint: '/user/login',
			isPublic: true,
		}),

		getTool(client, {
			name: 'fluentcart_public_welcome',
			title: 'Get Welcome Message',
			description:
				'Retrieve the FluentCart welcome/status message. No authentication required. ' +
				'Useful for checking if the FluentCart API is reachable and configured.',
			schema: z.object({}),
			endpoint: '/welcome',
		}),
	]
}
