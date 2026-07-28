import { readFileSync } from 'node:fs'
import { type FluentCartConfig, getConfigPath } from './types.js'

interface ConfigFile {
	url?: string
	username?: string
	appPassword?: string
	timeout?: number
}

const MAX_TIMEOUT_MS = 300_000

function validTimeout(value: unknown, label: string): number {
	const numeric = typeof value === 'string' && /^\d+$/.test(value) ? Number(value) : value
	if (
		typeof numeric !== 'number' ||
		!Number.isSafeInteger(numeric) ||
		numeric < 1 ||
		numeric > MAX_TIMEOUT_MS
	) {
		throw new Error(`${label} must be a whole number from 1 to ${MAX_TIMEOUT_MS} milliseconds`)
	}
	return numeric
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
			timeout: timeout === undefined ? undefined : validTimeout(timeout, 'FLUENTCART_TIMEOUT'),
		}
	}
	return undefined
}

function fromFile(): FluentCartConfig | undefined {
	let parsed: ConfigFile
	try {
		const raw = readFileSync(getConfigPath(), 'utf-8')
		parsed = JSON.parse(raw) as ConfigFile
	} catch {
		// Config file doesn't exist or is invalid — that's fine
		return undefined
	}

	if (parsed.url && parsed.username && parsed.appPassword) {
		return {
			url: parsed.url,
			username: parsed.username,
			appPassword: parsed.appPassword,
			timeout:
				parsed.timeout === undefined ? undefined : validTimeout(parsed.timeout, 'config timeout'),
		}
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
