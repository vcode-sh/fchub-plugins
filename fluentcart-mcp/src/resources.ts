import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import type { FluentCartClient } from './api/client.js'

interface ResourceDef {
	name: string
	uri: string
	endpoint: string
	description: string
	isPublic?: boolean
}

const RESOURCES: ResourceDef[] = [
	{
		name: 'store-config',
		uri: 'fluentcart://store/config',
		endpoint: '/app/init',
		description: 'Store configuration and settings',
	},
	{
		name: 'store-countries',
		uri: 'fluentcart://store/countries',
		endpoint: '/misc/countries',
		description: 'Supported countries and their details',
	},
	{
		name: 'store-payment-methods',
		uri: 'fluentcart://store/payment-methods',
		endpoint: '/payment-methods',
		description: 'Configured payment methods',
	},
	{
		name: 'store-filter-options',
		uri: 'fluentcart://store/filter-options',
		endpoint: '/misc/filter-options',
		description: 'Available filter options for orders, products, and customers',
	},
]

export function registerResources(server: McpServer, client: FluentCartClient): void {
	for (const res of RESOURCES) {
		server.registerResource(
			res.name,
			res.uri,
			{
				description: res.description,
				mimeType: 'application/json',
			},
			async (uri) => {
				const response = await client.get(res.endpoint, undefined, res.isPublic)
				return {
					contents: [
						{
							uri: uri.href,
							mimeType: 'application/json',
							text: JSON.stringify(response.data),
						},
					],
				}
			},
		)
	}
}
