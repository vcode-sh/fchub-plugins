import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { deleteTool, getTool, postTool, type ToolDefinition } from './_factory.js'

export function integrationTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_integration_list_addons',
			title: 'List Integration Addons',
			description:
				'Retrieve all available integration addons and their status. ' +
				'Returns addon metadata including name, description, logo, category, ' +
				'whether the required plugin is installed, and current configuration status.',
			schema: z.object({}),
			endpoint: '/integration/addons',
		}),

		getTool(client, {
			name: 'fluentcart_integration_get_global_settings',
			title: 'Get Integration Global Settings',
			description:
				'Retrieve global settings for a specific integration by its settings key. ' +
				'Returns current configuration values and field definitions (type, label, tips). ' +
				'Field types: text, password, select, link, authenticate-button.',
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
				'Update global settings for a specific integration. ' +
				'Pass settings_key plus integration-specific fields as top-level properties. ' +
				'Field names vary per integration — use get_global_settings first to discover them.',
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
				'Retrieve all global-level integration feeds. ' +
				'Global feeds run on order-level events (e.g. order_paid_done, order_refunded) ' +
				'unlike product feeds which are scoped to specific products.',
			schema: z.object({}),
			endpoint: '/integration/global-feeds',
		}),

		getTool(client, {
			name: 'fluentcart_integration_get_feed_settings',
			title: 'Get Integration Feed Settings',
			description:
				'Retrieve settings and field definitions for a specific integration feed. ' +
				'Pass integration_name to get a blank template for creating a new feed, ' +
				'or additionally pass integration_id to load an existing feed for editing.',
			schema: z.object({
				integration_name: z
					.string()
					.optional()
					.describe('Integration provider name (e.g. "fluent-crm")'),
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
				'Create or update an integration feed configuration. ' +
				'Use get_feed_settings first to discover available fields for the integration provider. ' +
				'Include integration_id in the body to update an existing feed, omit to create new.',
			schema: z.object({
				integration_id: z.number().optional().describe('Feed ID (omit to create new feed)'),
				integration_name: z.string().optional().describe('Integration provider name'),
				status: z.string().optional().describe('Feed status: "yes" (enabled), "no" (disabled)'),
				settings: z
					.record(z.string(), z.unknown())
					.optional()
					.describe('Feed-specific settings object'),
			}),
			endpoint: '/integration/global-feeds/settings',
		}),

		postTool(client, {
			name: 'fluentcart_integration_change_feed_status',
			title: 'Change Integration Feed Status',
			description: 'Enable or disable a global integration feed without modifying its settings.',
			schema: z.object({
				integration_id: z.number().describe('Integration feed ID'),
				status: z.string().describe('New status: "yes" (enabled) or "no" (disabled)'),
			}),
			endpoint: '/integration/global-feeds/change-status/:integration_id',
		}),

		deleteTool(client, {
			name: 'fluentcart_integration_delete_feed',
			title: 'Delete Integration Feed',
			description: 'Permanently delete a global integration feed. This action cannot be undone.',
			schema: z.object({
				integration_id: z.number().describe('Integration feed ID to delete'),
			}),
			endpoint: '/integration/global-feeds/:integration_id',
		}),

		getTool(client, {
			name: 'fluentcart_integration_get_feed_lists',
			title: 'Get Feed Lists',
			description:
				'Retrieve available lists for a specific integration provider. ' +
				'Returns mailing lists, contact groups, tags, or similar collections ' +
				'depending on the provider (e.g. FluentCRM lists, Mailchimp audiences).',
			schema: z.object({
				provider: z.string().describe('Integration provider name (e.g. "fluent-crm")'),
			}),
			endpoint: '/integration/feed/lists',
		}),

		getTool(client, {
			name: 'fluentcart_integration_get_dynamic_options',
			title: 'Get Dynamic Options',
			description:
				'Retrieve dynamic select options for integration feed fields. ' +
				'Used when a feed field depends on a remote data source ' +
				'(e.g. fetching CRM tags, membership levels, or course lists).',
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
				'Retrieve chained/dependent data for integration feed fields. ' +
				'Used when selecting a value in one field determines the options in another ' +
				'(e.g. selecting a list then loading its fields or segments).',
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
				'Install a required integration plugin from WordPress.org. ' +
				'Requires super admin permissions. Use list_addons first to find the addon_key.',
			schema: z.object({
				addon_key: z.string().describe('Plugin key to install (from addon metadata)'),
			}),
			endpoint: '/integration/feed/install-plugin',
		}),
	]
}
