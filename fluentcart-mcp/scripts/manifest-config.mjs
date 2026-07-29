/** MCPB user settings and their runtime environment variables. */
export const USER_CONFIG = [
	{
		key: 'store_url',
		env: 'FLUENTCART_URL',
		type: 'string',
		title: 'WordPress URL',
		description: 'Full URL of the WordPress site running FluentCart (e.g. https://your-store.com).',
		required: true,
	},
	{
		key: 'username',
		env: 'FLUENTCART_USERNAME',
		type: 'string',
		title: 'WordPress Username',
		description: 'WordPress user whose role grants the FluentCart REST capabilities you need.',
		required: true,
	},
	{
		key: 'app_password',
		env: 'FLUENTCART_APP_PASSWORD',
		type: 'string',
		title: 'Application Password',
		description: 'Generate at WordPress Admin > Users > Profile > Application Passwords.',
		required: true,
		sensitive: true,
	},
	{
		key: 'write_mode',
		env: 'FLUENTCART_WRITE_MODE',
		type: 'string',
		title: 'Write Mode',
		description: 'disabled (read-only, the default) or reversible (writes with a verified undo).',
		required: false,
		default: 'disabled',
	},
	{
		key: 'abilities_mode',
		env: 'FLUENTCART_ABILITIES_MODE',
		type: 'string',
		title: 'Native Abilities Bridge',
		description:
			'disabled (default) or enabled. Adds only audited FluentCart native read abilities after live discovery.',
		required: false,
		default: 'disabled',
	},
	{
		key: 'abilities_username',
		env: 'FLUENTCART_ABILITIES_USERNAME',
		type: 'string',
		title: 'Abilities Username',
		description:
			'Separate WordPress user for the optional read-only Abilities bridge. Required when the bridge is enabled.',
		required: false,
	},
	{
		key: 'abilities_app_password',
		env: 'FLUENTCART_ABILITIES_APP_PASSWORD',
		type: 'string',
		title: 'Abilities Application Password',
		description:
			'Separate WordPress Application Password for the optional read-only Abilities bridge.',
		required: false,
		sensitive: true,
	},
]

export const DESCRIPTION =
	'Curated and capability-discovered tools for a FluentCart store — orders, products, customers, subscriptions, coupons and reports. Read-only unless you opt in to writes.'

export const LONG_DESCRIPTION = `Connects an MCP client to the FluentCart REST API of your own WordPress site.

Dynamic mode is the default: a handful of meta-tools let the agent search the catalogue, read a tool definition and execute it, which keeps the definition payload small no matter how large the store API grows. Curated mode advertises a small reviewed set directly, code mode exposes a sandboxed scripting pair, and full mode lists everything the current policy permits.

Exposure is decided before anything is listed. Writes are hidden entirely until write mode is raised, and what you actually get depends on what your store and your role support. FluentCart native abilities are a separate read-only opt-in with their own credentials; discovery admits only the audited read allowlist.`
