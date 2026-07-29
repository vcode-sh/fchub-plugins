import { Buffer } from 'node:buffer'

export type HttpProfile = 'local' | 'private'

export interface HttpExposureConfig {
	profile: HttpProfile
	host: string
	allowedHosts: readonly string[]
	allowedOrigins: readonly string[]
	bearerKey?: string
}

export interface HttpExposureInput {
	profile: HttpProfile
	host: string
	allowedHosts?: readonly string[]
	allowedOrigins?: readonly string[]
	bearerKey?: string
}

const LOOPBACK_HOSTS = new Set(['127.0.0.1', '::1', '[::1]', 'localhost'])
const LOOPBACK_ALLOWLIST = ['localhost', '127.0.0.1', '[::1]'] as const
const MINIMUM_KEY_BYTES = 32

export function parseHttpProfile(value: string): HttpProfile {
	if (value === 'local' || value === 'private') return value
	throw new Error(`Invalid HTTP profile "${value}". Expected local or private.`)
}

function normaliseBindHost(host: string): string {
	const normalised = host.trim().toLowerCase()
	if (normalised.length === 0) throw new Error('HTTP bind host must not be empty.')
	return normalised
}

function validateHostname(entry: string, label: string): string {
	if (entry.length === 0 || entry.trim() !== entry) {
		throw new Error(`${label} entries must be non-empty hostnames without surrounding whitespace.`)
	}
	const bracketedIpv6 = entry.startsWith('[') && entry.endsWith(']')
	if (
		/[/?#]/.test(entry) ||
		(!bracketedIpv6 && entry.includes(':')) ||
		(entry.startsWith('[') && !bracketedIpv6)
	) {
		throw new Error(`${label} entries must be hostnames without a scheme or port.`)
	}

	let parsed: URL
	try {
		parsed = new URL(`http://${entry}`)
	} catch {
		throw new Error(`${label} entries must be hostnames without a scheme or port.`)
	}
	if (
		parsed.username !== '' ||
		parsed.password !== '' ||
		parsed.port !== '' ||
		parsed.pathname !== '/' ||
		parsed.search !== '' ||
		parsed.hash !== '' ||
		parsed.hostname.includes('*')
	) {
		throw new Error(`${label} entries must be hostnames without a scheme or port.`)
	}
	return parsed.hostname.toLowerCase()
}

function validateAllowlist(entries: readonly string[] | undefined, label: string): string[] {
	if (!entries || entries.length === 0) {
		throw new Error(`Private HTTP requires explicit ${label}.`)
	}
	return entries.map((entry) => validateHostname(entry, label))
}

function validateBearerKey(key: string | undefined): string | undefined {
	if (key === undefined) return undefined
	if (key.trim().length === 0) return undefined
	if (key.trim() !== key) {
		throw new Error('FLUENTCART_MCP_API_KEY must not have surrounding whitespace.')
	}
	if (Buffer.byteLength(key, 'utf8') < MINIMUM_KEY_BYTES) {
		throw new Error(`FLUENTCART_MCP_API_KEY must be at least ${MINIMUM_KEY_BYTES} UTF-8 bytes.`)
	}
	return key
}

/**
 * Resolve one fail-closed HTTP exposure profile before a listener is created.
 *
 * Local binds use the SDK's fixed loopback hostname set. Private binds require authentication
 * plus independent Host and Origin allowlists; there is no wildcard or implicit public posture.
 */
export function resolveHttpExposure(input: HttpExposureInput): HttpExposureConfig {
	const host = normaliseBindHost(input.host)
	const bearerKey = validateBearerKey(input.bearerKey)

	if (input.profile === 'local') {
		if (!LOOPBACK_HOSTS.has(host)) {
			throw new Error(`Local HTTP profile requires a loopback bind; received ${host}.`)
		}
		return {
			profile: 'local',
			host,
			allowedHosts: [...LOOPBACK_ALLOWLIST],
			allowedOrigins: [...LOOPBACK_ALLOWLIST],
			...(bearerKey !== undefined && { bearerKey }),
		}
	}

	if (input.profile !== 'private') {
		throw new Error(`Invalid HTTP profile "${String(input.profile)}". Expected local or private.`)
	}
	if (!bearerKey) {
		throw new Error(
			`Private HTTP on ${host} requires FLUENTCART_MCP_API_KEY (at least ${MINIMUM_KEY_BYTES} UTF-8 bytes).`,
		)
	}
	return {
		profile: 'private',
		host,
		allowedHosts: validateAllowlist(input.allowedHosts, 'allowed hosts'),
		allowedOrigins: validateAllowlist(input.allowedOrigins, 'allowed origins'),
		bearerKey,
	}
}
