import { FluentCartApiError } from './errors.js'

interface ConnectionSuccess {
	ok: true
	storeName: string
}

interface ConnectionFailure {
	ok: false
	error: FluentCartApiError
}

export type ConnectionResult = ConnectionSuccess | ConnectionFailure

export async function testConnection(
	url: string,
	username: string,
	appPassword: string,
): Promise<ConnectionResult> {
	const credentials = Buffer.from(`${username}:${appPassword}`).toString('base64')
	const endpoint = `${url.replace(/\/+$/, '')}/wp-json/fluent-cart/v2/app/init`

	try {
		const response = await fetch(endpoint, {
			headers: {
				Authorization: `Basic ${credentials}`,
				Accept: 'application/json',
			},
			signal: AbortSignal.timeout(15_000),
		})

		if (!response.ok) {
			const body = await response.json().catch(() => null)
			const message = (body as Record<string, string> | null)?.message ?? response.statusText

			if (response.status === 401) {
				return { ok: false, error: new FluentCartApiError('AUTH_FAILED', message, 401) }
			}
			if (response.status === 403) {
				return { ok: false, error: new FluentCartApiError('FORBIDDEN', message, 403) }
			}
			if (response.status === 404) {
				return {
					ok: false,
					error: new FluentCartApiError(
						'NOT_FOUND',
						'FluentCart REST API not found — is FluentCart installed and activated?',
						404,
					),
				}
			}
			return { ok: false, error: new FluentCartApiError('SERVER_ERROR', message, response.status) }
		}

		const data = (await response.json()) as Record<string, unknown>
		const storeName = (data.store_name as string) ?? (data.site_title as string) ?? 'Unknown Store'
		return { ok: true, storeName }
	} catch (error) {
		if (error instanceof DOMException && error.name === 'AbortError') {
			return {
				ok: false,
				error: new FluentCartApiError('TIMEOUT', 'Connection timed out after 15 seconds'),
			}
		}
		return {
			ok: false,
			error: new FluentCartApiError(
				'CONNECTION_ERROR',
				error instanceof Error ? error.message : String(error),
			),
		}
	}
}
