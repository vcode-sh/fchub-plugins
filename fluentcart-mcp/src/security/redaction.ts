/** Replacement written wherever a sensitive value was found. */
export const REDACTED = '[REDACTED]'

/** Depth beyond which we stop descending. Real payloads are nowhere near this deep. */
const MAX_DEPTH = 64

/**
 * Key fragments that make a value sensitive regardless of where it appears. Compared against
 * the key with separators removed, so `app_password`, `appPassword` and `APP-PASSWORD` all match.
 */
const SENSITIVE_KEY_FRAGMENTS = [
	'password',
	'passwd',
	'secret',
	'token',
	'apikey',
	'authorization',
	'auth',
	'credential',
	'privatekey',
	'idempotencykey',
	'nonce',
	'signature',
]

const CREDENTIAL_IN_TEXT = /\b(Basic|Bearer)\s+[A-Za-z0-9._~+/=-]{8,}/gi

function isSensitiveKey(key: string): boolean {
	const normalised = key.toLowerCase().replace(/[^a-z0-9]/g, '')
	return SENSITIVE_KEY_FRAGMENTS.some((fragment) => normalised.includes(fragment))
}

function redactText(value: string): string {
	return value.replace(CREDENTIAL_IN_TEXT, (_match, scheme: string) => `${scheme} ${REDACTED}`)
}

/**
 * Return a copy of `value` with credentials removed.
 *
 * Applied at every output boundary — logs, MCP errors and tool content — so a secret cannot
 * escape merely because some upstream payload nested it somewhere unexpected. Cycle-safe and
 * depth-bounded; never mutates the input.
 */
export function redactSensitive(value: unknown): unknown {
	return redact(value, new WeakSet(), 0)
}

function redact(value: unknown, seen: WeakSet<object>, depth: number): unknown {
	if (typeof value === 'string') return redactText(value)
	if (value === null || typeof value !== 'object') return value

	if (depth >= MAX_DEPTH) return '[MaxDepth]'
	if (seen.has(value)) return '[Circular]'
	seen.add(value)

	try {
		if (value instanceof Error) {
			return {
				name: value.name,
				message: redactText(value.message),
			}
		}

		if (Array.isArray(value)) {
			return value.map((entry) => redact(entry, seen, depth + 1))
		}

		const output: Record<string, unknown> = {}
		for (const [key, entry] of Object.entries(value as Record<string, unknown>)) {
			output[key] = isSensitiveKey(key) ? REDACTED : redact(entry, seen, depth + 1)
		}
		return output
	} finally {
		// Allow the same object to appear again on a sibling branch; only true cycles are cut.
		seen.delete(value)
	}
}
