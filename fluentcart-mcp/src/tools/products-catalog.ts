import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import {
	createTool,
	deleteTool,
	getTool,
	postTool,
	putTool,
	type ToolDefinition,
} from './_factory.js'

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
			description: 'Get the download URL for a downloadable file.',
			schema: z.object({
				downloadable_id: z.number().describe('Downloadable file ID'),
			}),
			endpoint: '/products/getDownloadableUrl/:downloadable_id',
		}),

		getTool(client, {
			name: 'fluentcart_product_upgrade_settings',
			title: 'Get Upgrade Settings',
			description: 'Get upgrade path settings for a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/upgrade-paths',
		}),

		postTool(client, {
			name: 'fluentcart_product_upgrade_path_save',
			title: 'Save Upgrade Path',
			description:
				'Create a new upgrade path for a product. ' +
				'Defines which variant can upgrade to which target variants, with optional proration and discount.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				from_variant: z.number().describe('Source variant ID (the variant being upgraded from)'),
				to_variants: z
					.array(z.number())
					.describe('Target variant IDs (variants the customer can upgrade to)'),
				is_prorate: z
					.boolean()
					.optional()
					.describe('Prorate remaining value of current subscription (default: false)'),
				discount_amount: z
					.number()
					.optional()
					.describe('Fixed discount amount applied to the upgrade price'),
			}),
			endpoint: '/products/:product_id/upgrade-path',
		}),

		postTool(client, {
			name: 'fluentcart_product_upgrade_path_update',
			title: 'Update Upgrade Path',
			description: 'Update an existing upgrade path.',
			schema: z.object({
				upgrade_path_id: z.number().describe('Upgrade path ID'),
				from_variant: z.number().describe('Source variant ID'),
				to_variants: z.array(z.number()).describe('Target variant IDs'),
				is_prorate: z
					.boolean()
					.optional()
					.describe('Prorate remaining value of current subscription'),
				discount_amount: z.number().optional().describe('Fixed discount amount'),
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
			description: 'Get product terms (categories, tags) list.',
			schema: z.object({}),
			endpoint: '/products/fetch-term',
		}),

		postTool(client, {
			name: 'fluentcart_product_terms_by_parent',
			title: 'Get Product Terms by Parent',
			description: 'Get product terms filtered by parent term. Uses POST but is a read-only operation.',
			annotations: { readOnlyHint: true },
			schema: z.object({
				parent_id: z.number().optional().describe('Parent term ID'),
				taxonomy: z.string().optional().describe('Taxonomy name'),
			}),
			endpoint: '/products/fetch-term-by-parent',
		}),

		createTool(client, {
			name: 'fluentcart_product_terms_add',
			title: 'Create Taxonomy Terms',
			description:
				'Create new taxonomy terms (categories or brands). Returns created term IDs. ' +
				'Comma-separate multiple names. Existing terms are returned without duplication.',
			schema: z.object({
				names: z.string().describe('Term names to create, comma-separated (e.g. "Pants,Shoes")'),
				taxonomy: z.string().describe('Taxonomy: product-categories or product-brands'),
				parent: z.number().optional().describe('Parent term ID for nested categories'),
			}),
			handler: async (client, input) => {
				const body = {
					term: {
						name: input.names as string,
						taxonomy: input.taxonomy as string,
						parent: input.parent != null ? String(input.parent) : '',
					},
				}
				const response = await client.post('/products/add-product-terms', body)
				return response.data
			},
		}),

		postTool(client, {
			name: 'fluentcart_product_taxonomy_sync',
			title: 'Sync Taxonomy Terms',
			description:
				'Sync taxonomy terms for a product (replaces all existing terms for that taxonomy).',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				terms: z.array(z.number()).describe('Term IDs to sync'),
				taxonomy: z.string().describe('Taxonomy: product-categories or product-brands'),
			}),
			endpoint: '/products/sync-taxonomy-term/:product_id',
		}),

		postTool(client, {
			name: 'fluentcart_product_taxonomy_delete',
			title: 'Delete Taxonomy Term',
			description:
				'Remove a single taxonomy term from a product. ' +
				'Call multiple times to remove several terms.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				term: z.number().describe('Term ID to remove'),
				taxonomy: z.string().describe('Taxonomy: product-categories or product-brands'),
			}),
			endpoint: '/products/delete-taxonomy-term/:product_id',
		}),

		getTool(client, {
			name: 'fluentcart_product_integrations',
			title: 'Get Product Integrations',
			description: 'Get integration feeds configured for a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/integrations',
		}),

		getTool(client, {
			name: 'fluentcart_product_integration_settings',
			title: 'Get Product Integration Settings',
			description: 'Get settings for a specific integration on a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				integration_name: z.string().describe('Integration provider name'),
			}),
			endpoint: '/products/:product_id/integrations/:integration_name/settings',
		}),

		postTool(client, {
			name: 'fluentcart_product_integration_save',
			title: 'Save Product Integration',
			description:
				'Save an integration feed for a product. ' +
				'The integration field must be a JSON string of the feed configuration. ' +
				'Fetch integration settings first to get the expected field schema for each provider.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				integration_name: z.string().describe('Integration provider name (e.g. "fluentcrm", "fluentcommunity")'),
				integration_id: z
					.number()
					.optional()
					.describe('Existing feed ID to update (omit to create new)'),
				integration: z
					.string()
					.describe(
						'JSON string of the feed configuration. Structure varies by provider. ' +
							'Use fluentcart_product_integration_settings to get the expected schema.',
					),
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
				notification_id: z.number().describe('Integration feed ID (required)'),
				status: z.enum(['yes', 'no']).describe('New status: yes to enable, no to disable'),
			}),
			endpoint: '/products/:product_id/integrations/feed/change-status',
		}),
	]
}
