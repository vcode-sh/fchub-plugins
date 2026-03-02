import { readFileSync } from 'node:fs'
import { type FluentCartConfig, getConfigPath } from './types.js'

interface ConfigFile {
	url?: string
	username?: string
	appPassword?: string
	timeout?: number
}

function fromEnv(): FluentCartConfig | undefined {
	const url = process.env.FLUENTCART_URL
	const username = process.env.FLUENTCART_USERNAME
	const appPassword = process.env.FLUENTCART_APP_PASSWORD
	const timeout = process.env.FLUENTCART_TIMEOUT

	if (url && username && appPassword) {
		return {
			url,
			username,
			appPassword,
			timeout: timeout ? Number.parseInt(timeout, 10) : undefined,
		}
	}
	return undefined
}

function fromFile(): FluentCartConfig | undefined {
	try {
		const raw = readFileSync(getConfigPath(), 'utf-8')
		const parsed = JSON.parse(raw) as ConfigFile
		if (parsed.url && parsed.username && parsed.appPassword) {
			return {
				url: parsed.url,
				username: parsed.username,
				appPassword: parsed.appPassword,
				timeout: parsed.timeout,
			}
		}
	} catch {
		// Config file doesn't exist or is invalid — that's fine
	}
	return undefined
}

export function resolveConfig(): FluentCartConfig {
	const config = fromEnv() ?? fromFile()

	if (!config) {
		throw new Error(
			'FluentCart MCP server is not configured.\n\n' +
				'Run: npx fluentcart-mcp setup\n\n' +
				'Or set environment variables:\n' +
				'  FLUENTCART_URL          Your WordPress site URL\n' +
				'  FLUENTCART_USERNAME     WordPress username\n' +
				'  FLUENTCART_APP_PASSWORD WordPress Application Password\n',
		)
	}

	return config
}
