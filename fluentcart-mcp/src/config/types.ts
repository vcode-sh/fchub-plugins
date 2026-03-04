import { homedir, platform } from 'node:os'
import { join } from 'node:path'

export interface FluentCartConfig {
	url: string
	username: string
	appPassword: string
	timeout?: number
}

export interface ResolvedConfig extends FluentCartConfig {
	adminBase: string
	publicBase: string
}

export function resolveApiUrls(config: FluentCartConfig): ResolvedConfig {
	const base = config.url.replace(/\/+$/, '')
	// FluentCart registers both admin and public (frontend) routes under the same
	// REST namespace "fluent-cart/v2".  Public endpoints are distinguished by their
	// URL prefix (e.g. /public/products) rather than a separate namespace.
	// The isPublic flag on individual tools controls auth behaviour, not the base URL.
	const apiBase = `${base}/wp-json/fluent-cart/v2`
	return {
		...config,
		adminBase: apiBase,
		publicBase: apiBase,
	}
}

export function getConfigDir(): string {
	const os = platform()
	if (os === 'win32') {
		return join(process.env.APPDATA ?? join(homedir(), 'AppData', 'Roaming'), 'fluentcart-mcp')
	}
	return join(process.env.XDG_CONFIG_HOME ?? join(homedir(), '.config'), 'fluentcart-mcp')
}

export function getConfigPath(): string {
	return join(getConfigDir(), 'config.json')
}
