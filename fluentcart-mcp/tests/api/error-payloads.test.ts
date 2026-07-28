// What an error costs, and what it gives away.
//
// Asking for a subscription that does not exist cost 573 characters to say four words, and the
// scenario harness caught it as a budget failure rather than a defect — which is what it was.
// Three faults compounded:
//
//  - FluentCart nests the sentence at `data.message`, so reading only the top level fell through
//    to a 220-character preview of the raw body AS the message.
//  - `formatError` then appended the same body again as the detail.
//  - That body carried `wpfluent`: env, HTTP method, the FULL store request URL and the parsed
//    route, query and body parameters. On any 404, to any caller.
//
// A fourth came out of the same probe: a missing product answered "Permission denied", because
// FluentCart resolves the model inside its permission callback and the ORM's ModelNotFoundException
// escapes there as HTTP 403. An agent reads that as a credentials failure and sends the merchant to
// check their application password over a mistyped id.
//
// Live after: 573 -> 103 characters, and a missing product says NOT_FOUND.
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createClient } from '../../src/api/client.js'
import { FluentCartApiError } from '../../src/api/errors.js'
import { resolveApiUrls } from '../../src/config/types.js'

const CONFIG = resolveApiUrls({
	url: 'https://store.example',
	username: 'admin',
	appPassword: 'never-sent-anywhere-here',
})

function stubFetch(status: number, body: unknown): void {
	vi.stubGlobal(
		'fetch',
		vi.fn(async () => ({
			ok: status < 400,
			status,
			statusText: 'Error',
			text: async () => JSON.stringify(body),
		})) as unknown as typeof fetch,
	)
}

afterEach(() => {
	vi.unstubAllGlobals()
})

/** The not-found body FluentCart really sends, plumbing and all. */
const NOT_FOUND = {
	data: {
		message: 'Subscription not found',
		buttonText: 'Back to Subscription list',
		route: '/subscriptions',
		wpfluent: {
			env: 'dev',
			method: 'GET',
			request_url: 'http://store.invalid/wp-json/fluent-cart/v2/subscriptions/999999',
			route_params: { subscriptionOrderId: '999999' },
			query_params: [],
			body_params: [],
		},
	},
	code: 'fluent_cart_entity_not_found',
}

async function failureOf(status: number, body: unknown): Promise<FluentCartApiError> {
	stubFetch(status, body)
	try {
		await createClient(CONFIG).get('/anything')
	} catch (error) {
		if (error instanceof FluentCartApiError) return error
		throw error
	}
	throw new Error('the request did not fail')
}

describe('an error says what went wrong, once', () => {
	it('reads the sentence FluentCart nests under data', async () => {
		const error = await failureOf(404, NOT_FOUND)

		expect(error.message).toContain('Subscription not found')
		// Not a preview of the raw body, which is what the fallback produced.
		expect(error.message).not.toContain('wpfluent')
		expect(error.message.length).toBeLessThan(80)
	})

	it('drops the debug block, which carries the store URL and the environment', async () => {
		const detail = JSON.stringify((await failureOf(404, NOT_FOUND)).detail)

		for (const leak of ['wpfluent', 'request_url', 'store.invalid', '"dev"', 'route_params']) {
			expect(detail, `${leak} travelled with the error`).not.toContain(leak)
		}
	})

	it('drops the admin UI strings, which no caller here can act on', async () => {
		const detail = JSON.stringify((await failureOf(404, NOT_FOUND)).detail)

		expect(detail).not.toContain('buttonText')
		expect(detail).not.toContain('Back to Subscription list')
	})

	it('does not repeat the message it is printed beside', async () => {
		const error = await failureOf(404, NOT_FOUND)
		const detail = JSON.stringify(error.detail)

		expect(detail).not.toContain('Subscription not found')
		// The code survives, because it is the one part a caller can branch on.
		expect(detail).toContain('fluent_cart_entity_not_found')
	})

	it('keeps a detail that is not a copy of the message', async () => {
		const error = await failureOf(422, {
			message: 'Validation failed',
			data: { errors: { title: 'title is required' } },
		})

		expect(JSON.stringify(error.detail)).toContain('title is required')
	})
})

describe('a missing record is not a permissions problem', () => {
	it('reclassifies the ORM not-found that arrives as 403', async () => {
		const error = await failureOf(403, {
			code: 'Permission Callback Error',
			message: 'No query results for model [FluentCart\\App\\Models\\Product].',
			data: { status: 403 },
		})

		expect(error.code).toBe('NOT_FOUND')
		expect(error.message).not.toMatch(/permission denied/i)
	})

	it('leaves a genuine permission failure alone', async () => {
		const error = await failureOf(403, { message: 'Sorry, you are not allowed to do that.' })

		expect(error.code).toBe('FORBIDDEN')
		expect(error.message).toMatch(/permission denied/i)
	})
})
