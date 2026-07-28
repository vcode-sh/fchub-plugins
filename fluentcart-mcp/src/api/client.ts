import { randomUUID } from 'node:crypto'
import type { ResolvedConfig } from '../config/types.js'
import { errorFromStatus, FluentCartApiError } from './errors.js'

export interface ApiResponse<T = unknown> {
	data: T
	status: number
}

const DEFAULT_TIMEOUT = 30_000

function buildUrl(base: string, path: string, params?: Record<string, unknown>): URL {
	const url = new URL(`${base}${path}`)
	if (params) {
		for (const [key, value] of Object.entries(params)) {
			if (value !== undefined && value !== null) {
				url.searchParams.set(key, String(value))
			}
		}
	}
	return url
}

function parseJsonLenient(raw: string): unknown | undefined {
	const trimmed = raw.trim()
	if (!trimmed) return {}

	try {
		return JSON.parse(trimmed)
	} catch {
		// Some FluentCart responses prepend HTML warnings before a JSON object.
		// Try to recover trailing JSON payload to avoid false parse failures.
		const trailingJson = trimmed.match(/(\{[\s\S]*\}|\[[\s\S]*\])\s*$/)
		const candidate = trailingJson?.[1]
		if (candidate) {
			try {
				return JSON.parse(candidate)
			} catch {
				// Fall through.
			}
		}
	}

	return undefined
}

async function readResponseText(response: Response): Promise<string> {
	const maybeText = response as unknown as { text?: () => Promise<string> }
	if (typeof maybeText.text === 'function') {
		return maybeText.text()
	}

	// Test mocks may only define json(); convert that shape into text for parser reuse.
	const maybeJson = response as unknown as { json?: () => Promise<unknown> }
	if (typeof maybeJson.json === 'function') {
		const body = await maybeJson.json().catch(() => null)
		return body == null ? '' : JSON.stringify(body)
	}

	return ''
}

function previewRaw(raw: string): string | null {
	const compact = raw.trim().replace(/\s+/g, ' ')
	if (!compact) return null
	return compact.slice(0, 220)
}

/**
 * The human sentence in a FluentCart error, wherever it put it.
 *
 * `sendError` nests it: a missing subscription answers
 * `{"data":{"message":"Subscription not found",…},"code":"fluent_cart_entity_not_found"}`, with no
 * top-level `message`. Reading only the top level meant falling through to a 220-character preview
 * of the raw body — which was then rendered beside the same body again as the error detail. One
 * not-found cost 573 characters to say four words.
 */
function errorMessageFrom(parsed: Record<string, unknown> | undefined): string | null {
	if (typeof parsed?.message === 'string') return parsed.message
	const data = parsed?.data
	if (data !== null && typeof data === 'object' && !Array.isArray(data)) {
		const nested = (data as Record<string, unknown>).message
		if (typeof nested === 'string') return nested
	}
	return null
}

/**
 * Drop the framework's debug block from an error before it travels.
 *
 * `wpfluent` carries `env`, the HTTP method, the FULL request URL and the parsed route, query and
 * body parameters — the store's own hostname and its environment name, echoed back on any 404.
 * None of it helps a caller fix the call, and `env: "dev"` plus a request URL is not something to
 * hand a model by default. `buttonText` and `route` are admin UI strings for a screen no caller
 * here is looking at.
 */
function stripErrorPlumbing(value: unknown, message: string | null): unknown {
	if (value === null || typeof value !== 'object' || Array.isArray(value)) return value
	const {
		wpfluent: _debug,
		buttonText: _button,
		route: _route,
		...rest
	} = value as Record<string, unknown>

	const kept: Record<string, unknown> = {}
	for (const [key, nested] of Object.entries(rest)) {
		// The detail is rendered beside the message, never instead of it, so a copy of the message is
		// the one thing it can never usefully carry. Dropping it — and then dropping whatever that
		// leaves empty — turns a not-found from two recitals of the same four words into a code.
		if (message !== null && nested === message) continue
		if (nested !== null && typeof nested === 'object' && !Array.isArray(nested)) {
			const inner = stripErrorPlumbing(nested, message)
			if (inner !== null && typeof inner === 'object' && Object.keys(inner).length === 0) continue
			kept[key] = inner
			continue
		}
		kept[key] = nested
	}
	return kept
}

/**
 * A missing record is not a permissions problem, whatever status it arrived under.
 *
 * FluentCart resolves the model inside its permission callback, so an ORM
 * `ModelNotFoundException` escapes there and WordPress reports the whole thing as HTTP 403 with
 * `code: "Permission Callback Error"`. Asking for a product that does not exist therefore answered
 * "Permission denied" — which an agent reads as a credentials failure, and which would send a
 * merchant to check their application password over a mistyped id.
 *
 * Only this one shape is reclassified, matched on the framework's own wording before redaction
 * touches it. A 403 that is genuinely about permissions still says so.
 */
function reclassify(status: number, message: string): number {
	if (status === 403 && /no query results for model/i.test(message)) return 404
	return status
}

async function handleErrorResponse(response: Response): Promise<never> {
	const raw = await readResponseText(response)
	const parsed = parseJsonLenient(raw)
	const parsedObj =
		parsed && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : undefined
	const message =
		errorMessageFrom(parsedObj) ??
		(raw.trim().startsWith('<')
			? 'Received HTML instead of JSON'
			: (previewRaw(raw) ?? response.statusText))
	const cleaned = parsed === null ? null : stripErrorPlumbing(parsed, errorMessageFrom(parsedObj))
	const detail = cleaned ?? (previewRaw(raw) ? { raw_preview: previewRaw(raw) } : null)
	// The CODE becomes NOT_FOUND because that is what happened; the STATUS stays 403 because that is
	// what the wire said. Rewriting the status would trade one wrong answer for another, and anything
	// reasoning about HTTP — retries, transport logging — would then be reasoning about a fiction.
	const semantic = reclassify(response.status, message)
	const error = errorFromStatus(semantic, message, detail)
	throw semantic === response.status
		? error
		: new FluentCartApiError(error.code, error.message, response.status, detail)
}

async function parseSuccessBody<T>(response: Response, method: string, path: string): Promise<T> {
	const raw = await readResponseText(response)
	if (raw.trim().startsWith('<')) {
		const recovered = parseJsonLenient(raw)
		const recoveredObj =
			recovered && typeof recovered === 'object'
				? (recovered as Record<string, unknown>)
				: undefined
		const message =
			typeof recoveredObj?.message === 'string'
				? recoveredObj.message
				: 'Received HTML instead of JSON'
		throw new FluentCartApiError('CONNECTION_ERROR', message, response.status, {
			recovered,
			raw_preview: previewRaw(raw),
		})
	}

	const parsed = parseJsonLenient(raw)
	if (parsed !== undefined) {
		// A literal `null` body is valid JSON and meaningless as a resource: it parsed, so it
		// reached the response transforms, and the first one to destructure it failed with
		// "Cannot destructure property 'activities' of 'order' as it is null" — a bare TypeError
		// with no error code, naming this server's internals and giving an agent nothing to branch
		// on. An empty body is already normalised to `{}` a few lines above for exactly this
		// reason; `null` means the same thing and now says so the same way.
		return (parsed === null ? {} : parsed) as T
	}

	throw new FluentCartApiError(
		'CONNECTION_ERROR',
		`Expected JSON response but received non-JSON payload for ${method} ${path}`,
		response.status,
		{ raw_preview: previewRaw(raw) },
	)
}

/** Map a thrown transport failure onto the package's error type without losing the cause. */
function toApiError(
	error: unknown,
	context: { method: string; path: string; timeout: number },
): FluentCartApiError {
	if (error instanceof FluentCartApiError) return error

	if (error instanceof DOMException && error.name === 'AbortError') {
		return new FluentCartApiError(
			'TIMEOUT',
			`Request timed out after ${context.timeout}ms: ${context.method} ${context.path}`,
		)
	}

	return new FluentCartApiError(
		'CONNECTION_ERROR',
		error instanceof Error ? error.message : String(error),
	)
}

export function createClient(config: ResolvedConfig) {
	const credentials = Buffer.from(`${config.username}:${config.appPassword}`).toString('base64')
	const timeout = config.timeout ?? DEFAULT_TIMEOUT

	const commonHeaders = {
		'Content-Type': 'application/json',
		Accept: 'application/json',
	}

	// Two distinct header sets, never one set with a conditional deletion. A public storefront
	// route has no business receiving a store administrator's credentials.
	const publicHeaders = commonHeaders
	const authenticatedHeaders = {
		...commonHeaders,
		Authorization: `Basic ${credentials}`,
	}

	async function request<T = unknown>(
		method: string,
		path: string,
		options?: {
			body?: Record<string, unknown>
			params?: Record<string, unknown>
			isPublic?: boolean
		},
	): Promise<ApiResponse<T>> {
		const isPublic = options?.isPublic === true
		const base = isPublic ? config.publicBase : config.adminBase
		const url = buildUrl(base, path, options?.params)
		const headers = isPublic ? publicHeaders : authenticatedHeaders

		const controller = new AbortController()
		const timer = setTimeout(() => controller.abort(), timeout)

		try {
			const response = await fetch(url.toString(), {
				method,
				headers: { ...headers, 'X-Request-Id': randomUUID() },
				body: method !== 'GET' && options?.body ? JSON.stringify(options.body) : undefined,
				signal: controller.signal,
			})

			if (!response.ok) {
				await handleErrorResponse(response)
			}

			const data = await parseSuccessBody<T>(response, method, path)
			return { data, status: response.status }
		} catch (error) {
			throw toApiError(error, { method, path, timeout })
		} finally {
			clearTimeout(timer)
		}
	}

	return {
		request,

		get: <T = unknown>(path: string, params?: Record<string, unknown>, isPublic?: boolean) =>
			request<T>('GET', path, { params, isPublic }),

		post: <T = unknown>(path: string, body?: Record<string, unknown>, isPublic?: boolean) =>
			request<T>('POST', path, { body, isPublic }),

		put: <T = unknown>(path: string, body?: Record<string, unknown>) =>
			request<T>('PUT', path, { body }),

		delete: <T = unknown>(path: string, params?: Record<string, unknown>) =>
			request<T>('DELETE', path, { params }),
	}
}

export type FluentCartClient = ReturnType<typeof createClient>
