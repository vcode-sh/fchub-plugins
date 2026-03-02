import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { deleteTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

export function productCatalogTools(client: FluentCartClient): ToolDefinition[] {
	return [
		postTool(client, {
			name: 'fluentcart_product_sync_downloadable_files',
			title: 'Sync Downloadable Files',
			description: 'Synchronise downloadable files for a digital product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/sync-downloadable-files',
		}),

		putTool(client, {
			name: 'fluentcart_product_downloadable_update',
			title: 'Update Downloadable File',
			description: 'Update a downloadable file record.',
			schema: z.object({
				downloadable_id: z.number().describe('Downloadable file ID'),
				name: z.string().optional().describe('File display name'),
				file_url: z.string().optional().describe('File URL'),
			}),
			endpoint: '/products/:downloadable_id/update',
		}),

		deleteTool(client, {
			name: 'fluentcart_product_downloadable_delete',
			title: 'Delete Downloadable File',
			description: 'Delete a downloadable file from a product.',
			schema: z.object({
				downloadable_id: z.number().describe('Downloadable file ID'),
			}),
			endpoint: '/products/:downloadable_id/delete',
		}),

		getTool(client, {
			name: 'fluentcart_product_downloadable_url',
			title: 'Get Downloadable URL',
			description: 'Retrieve the download URL for a downloadable file.',
			schema: z.object({
				downloadable_id: z.number().describe('Downloadable file ID'),
			}),
			endpoint: '/products/getDownloadableUrl/:downloadable_id',
		}),

		getTool(client, {
			name: 'fluentcart_product_upgrade_settings',
			title: 'Get Upgrade Settings',
			description: 'Retrieve upgrade path settings for a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/upgrade-paths',
		}),

		postTool(client, {
			name: 'fluentcart_product_upgrade_path_save',
			title: 'Save Upgrade Path',
			description: 'Create a new upgrade path setting for a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/upgrade-path',
		}),

		postTool(client, {
			name: 'fluentcart_product_upgrade_path_update',
			title: 'Update Upgrade Path',
			description: 'Update an existing upgrade path.',
			schema: z.object({
				upgrade_path_id: z.number().describe('Upgrade path ID'),
			}),
			endpoint: '/products/upgrade-path/:upgrade_path_id/update',
		}),

		deleteTool(client, {
			name: 'fluentcart_product_upgrade_path_delete',
			title: 'Delete Upgrade Path',
			description: 'Delete an upgrade path.',
			schema: z.object({
				upgrade_path_id: z.number().describe('Upgrade path ID'),
			}),
			endpoint: '/products/upgrade-path/:upgrade_path_id/delete',
		}),

		getTool(client, {
			name: 'fluentcart_product_terms',
			title: 'Get Product Terms',
			description: 'Retrieve product terms (categories, tags) list.',
			schema: z.object({}),
			endpoint: '/products/fetch-term',
		}),

		postTool(client, {
			name: 'fluentcart_product_terms_by_parent',
			title: 'Get Product Terms by Parent',
			description: 'Retrieve product terms filtered by parent term.',
			schema: z.object({
				parent_id: z.number().optional().describe('Parent term ID'),
				taxonomy: z.string().optional().describe('Taxonomy name'),
			}),
			endpoint: '/products/fetch-term-by-parent',
		}),

		postTool(client, {
			name: 'fluentcart_product_terms_add',
			title: 'Add Product Terms',
			description: 'Add terms (categories, tags) to a product.',
			schema: z.object({
				product_id: z.number().optional().describe('Product ID'),
				term_ids: z.array(z.number()).optional().describe('Term IDs to add'),
				taxonomy: z.string().optional().describe('Taxonomy name'),
			}),
			endpoint: '/products/add-product-terms',
		}),

		postTool(client, {
			name: 'fluentcart_product_taxonomy_sync',
			title: 'Sync Taxonomy Terms',
			description: 'Synchronise taxonomy terms for a product (replaces existing).',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				term_ids: z.array(z.number()).optional().describe('Term IDs to sync'),
				taxonomy: z.string().optional().describe('Taxonomy name'),
			}),
			endpoint: '/products/sync-taxonomy-term/:product_id',
		}),

		postTool(client, {
			name: 'fluentcart_product_taxonomy_delete',
			title: 'Delete Taxonomy Terms',
			description: 'Delete taxonomy terms from a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				term_ids: z.array(z.number()).optional().describe('Term IDs to remove'),
				taxonomy: z.string().optional().describe('Taxonomy name'),
			}),
			endpoint: '/products/delete-taxonomy-term/:product_id',
		}),

		getTool(client, {
			name: 'fluentcart_product_integrations',
			title: 'Get Product Integrations',
			description: 'Retrieve integration feeds configured for a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/integrations',
		}),

		getTool(client, {
			name: 'fluentcart_product_integration_settings',
			title: 'Get Product Integration Settings',
			description: 'Retrieve settings for a specific integration on a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				integration_name: z.string().describe('Integration provider name'),
			}),
			endpoint: '/products/:product_id/integrations/:integration_name/settings',
		}),

		postTool(client, {
			name: 'fluentcart_product_integration_save',
			title: 'Save Product Integration',
			description: 'Save an integration feed for a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/integrations',
		}),

		deleteTool(client, {
			name: 'fluentcart_product_integration_delete',
			title: 'Delete Product Integration',
			description: 'Delete an integration feed from a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				integration_id: z.number().describe('Integration feed ID'),
			}),
			endpoint: '/products/:product_id/integrations/:integration_id',
		}),

		postTool(client, {
			name: 'fluentcart_product_integration_feed_status',
			title: 'Change Product Integration Feed Status',
			description: 'Enable or disable an integration feed on a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				feed_id: z.number().optional().describe('Feed ID'),
				status: z.string().optional().describe('New status: active, inactive'),
			}),
			endpoint: '/products/:product_id/integrations/feed/change-status',
		}),
	]
}
