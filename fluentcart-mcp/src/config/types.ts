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
	return {
		...config,
		adminBase: `${base}/wp-json/fluent-cart/v2`,
		publicBase: `${base}/wp-json/fluent-cart-public/v2`,
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
