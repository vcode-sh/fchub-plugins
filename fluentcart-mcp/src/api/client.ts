import type { ResolvedConfig } from '../config/types.js'

export interface ApiError {
	status: number
	message: string
	body?: unknown
}

export interface ApiResponse<T = unknown> {
	data: T
	status: number
}

const DEFAULT_TIMEOUT = 30_000

const ERROR_MAP: Record<number, [string, (err: ApiError) => string]> = {
	401: [
		'Authentication failed',
		() =>
			'Check your WordPress username and Application Password. Generate one at: WordPress Admin → Users → Application Passwords.',
	],
	403: ['Permission denied', () => 'Your WordPress user lacks permission for this operation.'],
	404: ['Resource not found', (err) => err.message],
	422: ['Validation error', (err) => JSON.stringify(err.body)],
}

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

async function handleErrorResponse(response: Response): Promise<never> {
	const body = await response.json().catch(() => null)
	const apiError: ApiError = {
		status: response.status,
		message: (body as Record<string, string> | null)?.message ?? response.statusText,
		body,
	}

	const mapping = ERROR_MAP[response.status]
	if (mapping) {
		const [label, formatter] = mapping
		throw new Error(`${label}: ${formatter(apiError)}`)
	}

	throw new Error(`API error ${response.status}: ${apiError.message}`)
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
				headers,
				body: method !== 'GET' && options?.body ? JSON.stringify(options.body) : undefined,
				signal: controller.signal,
			})

			if (!response.ok) {
				await handleErrorResponse(response)
			}

			const data = (await response.json()) as T
			return { data, status: response.status }
		} catch (error) {
			if (error instanceof DOMException && error.name === 'AbortError') {
				throw new Error(`Request timed out after ${timeout}ms: ${method} ${path}`)
			}
			throw error
		} finally {
			clearTimeout(timer)
		}
	}

	return {
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
