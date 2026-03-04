import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, postTool, type ToolDefinition } from './_factory.js'

export function publicTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_public_product_views',
			title: 'Get Public Product Views',
			description: 'Get product view data for the storefront. No auth required.',
			schema: z.object({}),
			endpoint: '/public/product-views',
			isPublic: true,
		}),

		getTool(client, {
			name: 'fluentcart_public_product_search',
			title: 'Search Public Products',
			description: 'Search published products by name. No auth required.',
			schema: z.object({
				search: z.string().optional().describe('Search query to filter products by name'),
			}),
			endpoint: '/public/product-search',
			isPublic: true,
		}),

		getTool(client, {
			name: 'fluentcart_public_products',
			title: 'List Public Products',
			description: 'Get public product catalogue for storefront. No auth required.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
			}),
			endpoint: '/public/products',
			isPublic: true,
		}),

		postTool(client, {
			name: 'fluentcart_public_user_login',
			title: 'User Login',
			description:
				'Frontend login: authenticate by email/password, returns customer data with token. ' +
				'WARNING: Sends credentials in plaintext. Only use over HTTPS.',
			schema: z.object({
				email: z.string().describe('User email address'),
				password: z.string().describe('User password'),
			}),
			endpoint: '/user/login',
			isPublic: true,
		}),
	]
}
