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

/**
 * Server internals that arrive inside upstream failure text.
 *
 * A WordPress plugin fatal comes back as prose containing the query it tried to run, the file it
 * died in and the frames above it, and `handleErrorResponse` puts that text straight into the
 * error a caller sees. Redacting credential-shaped KEYS never touched it, because none of this is
 * a key — it is the message itself. Two live examples from this store:
 *
 *   Table 'wordpress.wp_fct_licenses' doesn't exist (SQL: select count(*) from `wp_fct_licenses`)
 *   {"code":"plugin_exception","data":{"file":"/var/www/html/wp-content/plugins/fluent-cart/…"}}
 *
 * None of it helps a caller — an agent cannot act on our filesystem layout or our SQL — and all of
 * it describes the inside of somebody's server. The human-meaningful part of the message survives;
 * only these spans are replaced.
 *
 * Ordered longest-construct first: a stack frame contains a path, and a SQL statement may contain
 * one too, so replacing paths first would leave the surrounding wreckage behind.
 */
const SERVER_INTERNALS: readonly RegExp[] = [
	// A PHP fatal announces itself and then describes the inside of the server; the whole notice
	// goes, markup and all.
	/(?:<br\s*\/?>\s*)?<b>\s*Fatal error\s*<\/b>[\s\S]*/gi,
	/\bFatal error:[\s\S]*/gi,
	// `Uncaught PDOException: …` names the layer that failed and nothing a caller can use.
	/\bUncaught\s+\w*(?:Exception|Error)\b[^\n]*/g,
	// PHP stack frames: `#0 /path/file.php(12): Class->method()`.
	/#\d+\s+\S*\.php\(\d+\)[^\n]*/g,
	// `Stack trace:` and everything after it.
	/Stack trace:[\s\S]*/gi,
	// A bare source reference with no surrounding path: `OrderController.php:88`, `… on line 88`.
	/\b[\w/.-]+\.php(?::\d+|\s+on\s+line\s+\d+)/gi,
	// A SQL statement, recognised by shape rather than by any single keyword.
	/\b(?:select\s[\s\S]{0,300}?\sfrom\s|insert\s+into\s|update\s+\S+\s+set\s|delete\s+from\s)[\s\S]{0,300}/gi,
	// Absolute filesystem paths, POSIX and Windows.
	/(?:\/(?:var|home|usr|srv|opt|www|Users|private|tmp|etc)\/[^\s"'),\]}]+|[A-Za-z]:\\[^\s"'),\]}]+)/g,
	// A fully-qualified PHP class, which names the internal structure and nothing a caller can act
	// on. Asking for a product that does not exist answered "No query results for model
	// [FluentCart\App\Models\Product]" — the ORM, the namespace layout and the model name, handed
	// over on a 404. Three or more backslash-separated segments, so ordinary prose and a lone
	// escape sequence are both left alone.
]

/**
 * A fully-qualified PHP class, reduced to the class itself.
 *
 * Asking for a product that does not exist answered "No query results for model
 * [FluentCart\App\Models\Product]" — the ORM, the namespace layout and the model name. The
 * namespace is internal structure and goes; the FINAL segment is the only word in that sentence
 * saying WHAT was not found, and blanking the whole thing left a message no caller could act on:
 * a bad product id, a bad customer id and a bad password all read identically. Keeping `Product`
 * leaks no layout and restores the distinction.
 *
 * Three or more backslash-separated segments, so ordinary prose and a lone escape sequence are
 * both left alone.
 */
const PHP_CLASS_PATH = /\b[A-Za-z_][A-Za-z0-9_]*(?:\\{1,2}[A-Za-z_][A-Za-z0-9_]*){2,}/g

function isSensitiveKey(key: string): boolean {
	const normalised = key.toLowerCase().replace(/[^a-z0-9]/g, '')
	return SENSITIVE_KEY_FRAGMENTS.some((fragment) => normalised.includes(fragment))
}

function redactText(value: string): string {
	let text = value.replace(CREDENTIAL_IN_TEXT, (_match, scheme: string) => `${scheme} ${REDACTED}`)
	for (const pattern of SERVER_INTERNALS) text = text.replace(pattern, REDACTED)
	text = text.replace(PHP_CLASS_PATH, (match) => match.split(/\\+/).pop() ?? REDACTED)
	return text
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
