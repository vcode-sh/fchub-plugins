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

async function handleErrorResponse(response: Response): Promise<never> {
	const raw = await readResponseText(response)
	const parsed = parseJsonLenient(raw)
	const parsedObj =
		parsed && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : undefined
	const message =
		typeof parsedObj?.message === 'string'
			? parsedObj.message
			: raw.trim().startsWith('<')
				? 'Received HTML instead of JSON'
				: previewRaw(raw) ?? response.statusText
	const detail = parsed ?? (previewRaw(raw) ? { raw_preview: previewRaw(raw) } : null)
	throw errorFromStatus(response.status, message, detail)
}

export function createClient(config: ResolvedConfig) {
	const credentials = Buffer.from(`${config.username}:${config.appPassword}`).toString('base64')
	const timeout = config.timeout ?? DEFAULT_TIMEOUT

	const headers = {
		Authorization: `Basic ${credentials}`,
		'Content-Type': 'application/json',
		Accept: 'application/json',
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
		const base = options?.isPublic ? config.publicBase : config.adminBase
		const url = buildUrl(base, path, options?.params)

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

				const raw = await readResponseText(response)
				// A 2xx HTTP status with HTML payload indicates a backend/runtime warning page.
				// Do not treat this as success, even if trailing JSON can be extracted.
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
					throw new FluentCartApiError(
						'CONNECTION_ERROR',
						message,
						response.status,
						{ recovered, raw_preview: previewRaw(raw) },
					)
				}

				const parsed = parseJsonLenient(raw)
				if (parsed !== undefined) {
					return { data: parsed as T, status: response.status }
				}

				throw new FluentCartApiError(
					'CONNECTION_ERROR',
					`Expected JSON response but received non-JSON payload for ${method} ${path}`,
					response.status,
					{ raw_preview: previewRaw(raw) },
				)
			} catch (error) {
			if (error instanceof DOMException && error.name === 'AbortError') {
				throw new FluentCartApiError(
					'TIMEOUT',
					`Request timed out after ${timeout}ms: ${method} ${path}`,
				)
			}
			if (error instanceof FluentCartApiError) {
				throw error
			}
			throw new FluentCartApiError(
				'CONNECTION_ERROR',
				error instanceof Error ? error.message : String(error),
			)
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
