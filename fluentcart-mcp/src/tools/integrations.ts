import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { deleteTool, getTool, postTool, type ToolDefinition } from './_factory.js'

export function integrationTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_integration_list_addons',
			title: 'List Integration Addons',
			description:
				'Get all integration addons with metadata: name, category, install status, and config state.',
			schema: z.object({}),
			endpoint: '/integration/addons',
		}),

		getTool(client, {
			name: 'fluentcart_integration_get_global_settings',
			title: 'Get Integration Global Settings',
			description:
				'Get global settings for an integration by key. Field types: text, password, select, link, authenticate-button.',
			schema: z.object({
				settings_key: z
					.string()
					.describe('Integration settings key (e.g. "fakturownia", "fluent-crm")'),
			}),
			endpoint: '/integration/global-settings',
		}),

		postTool(client, {
			name: 'fluentcart_integration_save_global_settings',
			title: 'Save Integration Global Settings',
			description:
				'Update global settings for an integration. Use get_global_settings first to discover fields.',
			schema: z.object({
				settings_key: z.string().describe('Integration key (e.g. "fakturownia")'),
				settings: z
					.record(z.string(), z.unknown())
					.optional()
					.describe('Integration-specific settings as key-value pairs'),
			}),
			endpoint: '/integration/global-settings',
		}),

		getTool(client, {
			name: 'fluentcart_integration_get_global_feeds',
			title: 'Get Global Integration Feeds',
			description:
				'Get all global-level integration feeds. Global feeds run on order events, not product-scoped.',
			schema: z.object({}),
			endpoint: '/integration/global-feeds',
		}),

		getTool(client, {
			name: 'fluentcart_integration_get_feed_settings',
			title: 'Get Integration Feed Settings',
			description:
				'Get feed settings/template. integration_name is required by the backend. ' +
				'Pass integration_name alone for a blank template, add integration_id to load an existing feed.',
			schema: z.object({
				integration_name: z
					.string()
					.describe('Integration provider name (e.g. "fluent-crm") — required'),
				integration_id: z
					.number()
					.optional()
					.describe('Integration feed ID (for editing an existing feed)'),
			}),
			endpoint: '/integration/global-feeds/settings',
		}),

		postTool(client, {
			name: 'fluentcart_integration_save_feed_settings',
			title: 'Save Integration Feed Settings',
			description:
				'Create or update a global integration feed. Include integration_id to update, omit to create new. ' +
				'Backend requires integration_name and integration (JSON string of feed config). ' +
				'Use get_feed_settings first to discover the feed schema for a provider.',
			schema: z.object({
				integration_id: z.number().optional().describe('Feed ID (omit to create new feed)'),
				integration_name: z
					.string()
					.describe('Integration provider name (e.g. "fluent-crm") — required'),
				integration: z
					.string()
					.describe(
						'JSON-encoded feed configuration object. Use get_feed_settings to discover the schema — required',
					),
				status: z
					.string()
					.optional()
					.describe('Feed status: "yes" (enabled), "no" (disabled)'),
			}),
			endpoint: '/integration/global-feeds/settings',
		}),

		postTool(client, {
			name: 'fluentcart_integration_change_feed_status',
			title: 'Change Integration Feed Status',
			description: 'Enable or disable a global integration feed without modifying its settings.',
			schema: z.object({
				integration_id: z.number().describe('Integration feed ID'),
				status: z.enum(['yes', 'no']).describe('New status: "yes" (enabled) or "no" (disabled)'),
			}),
			endpoint: '/integration/global-feeds/change-status/:integration_id',
		}),

		deleteTool(client, {
			name: 'fluentcart_integration_delete_feed',
			title: 'Delete Integration Feed',
			description: 'Permanently delete a global integration feed. Cannot be undone.',
			schema: z.object({
				integration_id: z.number().describe('Integration feed ID to delete'),
			}),
			endpoint: '/integration/global-feeds/:integration_id',
		}),

		getTool(client, {
			name: 'fluentcart_integration_get_feed_lists',
			title: 'Get Feed Lists',
			description:
				'Get available lists for a provider (e.g. FluentCRM lists, Mailchimp audiences).',
			schema: z.object({
				provider: z.string().describe('Integration provider name (e.g. "fluent-crm")'),
			}),
			endpoint: '/integration/feed/lists',
		}),

		getTool(client, {
			name: 'fluentcart_integration_get_dynamic_options',
			title: 'Get Dynamic Options',
			description:
				'Get dynamic select options for feed fields that depend on remote data (e.g. CRM tags, courses).',
			schema: z.object({
				option_key: z.string().optional().describe('The option key to fetch values for'),
				search: z.string().optional().describe('Search term to filter options'),
				sub_option_key: z.string().optional().describe('Sub-option key for nested lookups'),
			}),
			endpoint: '/integration/feed/dynamic_options',
		}),

		postTool(client, {
			name: 'fluentcart_integration_get_chained_data',
			title: 'Get Chained Data',
			description:
				'Get dependent data for feed fields where one selection determines another (e.g. list then segments).',
			schema: z.object({
				data: z
					.record(z.string(), z.unknown())
					.optional()
					.describe('Context data for the chained lookup (parent field values)'),
			}),
			endpoint: '/integration/feed/chained',
		}),

		postTool(client, {
			name: 'fluentcart_integration_install_plugin',
			title: 'Install Integration Plugin',
			description:
				'Install a required plugin from WordPress.org. Requires super admin. Use list_addons to find addon_key.',
			schema: z.object({
				addon_key: z.string().describe('Plugin key to install (from addon metadata)'),
			}),
			endpoint: '/integration/feed/install-plugin',
		}),
	]
}
