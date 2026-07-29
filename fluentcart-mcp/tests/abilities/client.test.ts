import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	AUDITED_READ_ABILITIES,
	createAbilitiesClient,
	discoverAuditedReadAbilities,
} from '../../src/abilities/client.js'

const originalFetch = globalThis.fetch
const APPROVED_STORE_CONTEXT =
	'sha256:1bd792c89fcc7373bb959c970bb00d2ad7ea03c8f61ae56de3a6859e82159be6'
const REST_METHODS = ['DELETE', 'GET', 'PATCH', 'POST', 'PUT']

afterEach(() => {
	globalThis.fetch = originalFetch
	vi.restoreAllMocks()
})

function response(status: number, body: unknown): Response {
	return new Response(JSON.stringify(body), {
		status,
		headers: { 'Content-Type': 'application/json' },
	})
}

const readAbility = {
	name: 'fluent-cart/get-store-context',
	label: 'Get Store Context',
	description: 'Store context',
	category: 'fluent-cart',
	input_schema: { type: 'object', properties: {} },
	output_schema: [],
	meta: {
		annotations: {
			readonly: null,
			destructive: null,
			idempotent: null,
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: null,
			openWorldHint: null,
		},
	},
	_links: {
		self: [
			{ href: 'https://shop.test/wp-json/wp-abilities/v1/abilities/fluent-cart/get-store-context' },
		],
		'wp:action-run': [
			{
				href: 'https://shop.test/wp-json/wp-abilities/v1/abilities/fluent-cart/get-store-context/run',
			},
		],
	},
}

const optionsBody = {
	namespace: 'wp-abilities/v1',
	methods: REST_METHODS,
	endpoints: [{ methods: REST_METHODS, args: { input: { required: false } } }],
	_links: {
		self: [
			{
				href: 'https://shop.test/wp-json/wp-abilities/v1/abilities/fluent-cart/get-store-context/run',
			},
		],
	},
}

function client(approvedFallbackFingerprints: ReadonlySet<string> = new Set()) {
	return createAbilitiesClient({
		url: 'https://shop.test',
		username: 'ability-user',
		appPassword: 'ability-password',
		approvedFallbackFingerprints,
	})
}

function fetchFor(
	ability: unknown,
	execute: () => Response | Promise<Response> = () =>
		response(200, { result: { store_name: 'Example' } }),
) {
	return vi.fn(async (url: Parameters<typeof fetch>[0], init: RequestInit = {}) => {
		const rawUrl = typeof url === 'string' ? url : url instanceof URL ? url.href : url.url
		const path = new URL(rawUrl).pathname
		if (path.endsWith('/wp-abilities/v1/abilities')) return response(200, [ability])
		if (init.method === 'OPTIONS') return response(200, optionsBody)
		return execute()
	})
}

describe('WordPress Abilities REST client', () => {
	it('uses the dedicated Basic principal for discovery and captures run-route methods', async () => {
		const fetchMock = fetchFor(readAbility)
		globalThis.fetch = fetchMock

		await client(new Set([APPROVED_STORE_CONTEXT])).discover()

		const calls = fetchMock.mock.calls as unknown as Array<[URL | string, RequestInit]>
		expect(String(calls[0][0])).toBe(
			'https://shop.test/wp-json/wp-abilities/v1/abilities?category=fluent-cart&per_page=100',
		)
		expect(calls[0][1]?.headers).toMatchObject({
			Authorization: `Basic ${Buffer.from('ability-user:ability-password').toString('base64')}`,
		})
		expect(String(calls[1][0])).toBe(
			'https://shop.test/wp-json/wp-abilities/v1/abilities/fluent-cart/get-store-context/run',
		)
		expect(calls[1][1]?.method).toBe('OPTIONS')
	})

	it('fails closed when authenticated discovery is rejected', async () => {
		globalThis.fetch = vi.fn(async () =>
			response(401, { code: 'rest_not_logged_in', message: 'Not logged in' }),
		)

		await expect(client().discover()).rejects.toThrow(/discovery failed with HTTP 401/)
	})

	it('keeps only the exact audited read allowlist', async () => {
		const write = {
			...readAbility,
			name: 'fluent-cart/refund-order',
			meta: { annotations: { ...readAbility.meta.annotations, readOnlyHint: false } },
		}
		const deceptivelyAnnotatedWrite = {
			...readAbility,
			name: 'fluent-cart/change-subscription-status',
		}
		const futureRead = { ...readAbility, name: 'fluent-cart/future-read' }

		const allowed = discoverAuditedReadAbilities(
			[readAbility, write, deceptivelyAnnotatedWrite, futureRead],
			new Set([APPROVED_STORE_CONTEXT]),
			new Map([[readAbility.name, REST_METHODS]]),
		)

		expect(allowed.map((ability) => ability.name)).toEqual(['fluent-cart/get-store-context'])
		expect(AUDITED_READ_ABILITIES).toHaveLength(26)
	})

	it('omits a duplicated name even when only one duplicate matches the approved fingerprint', () => {
		const changed = {
			...readAbility,
			input_schema: {
				type: 'object',
				properties: { changed: { type: 'boolean' } },
			},
		}

		const allowed = discoverAuditedReadAbilities(
			[readAbility, changed],
			new Set([APPROVED_STORE_CONTEXT]),
			new Map([[readAbility.name, REST_METHODS]]),
		)

		expect(allowed).toEqual([])
	})

	it('executes a standard WordPress readonly ability with canonical GET input and no body', async () => {
		const standardRead = {
			...readAbility,
			meta: {
				annotations: {
					...readAbility.meta.annotations,
					readonly: true,
				},
			},
		}
		const fetchMock = fetchFor(standardRead)
		globalThis.fetch = fetchMock
		const abilities = client()
		await abilities.discover()

		const input = { z: 1, a: { y: 2, x: 3 } }
		const result = await abilities.execute(readAbility.name, input)

		expect(result).toEqual({ result: { store_name: 'Example' } })
		const [url, init] = (fetchMock.mock.calls as unknown as Array<[URL | string, RequestInit]>).at(
			-1,
		) as [URL | string, RequestInit]
		expect(String(url)).toBe(
			'https://shop.test/wp-json/wp-abilities/v1/abilities/fluent-cart/get-store-context/run?input=%7B%22a%22%3A%7B%22x%22%3A3%2C%22y%22%3A2%7D%2C%22z%22%3A1%7D',
		)
		expect(init.method).toBe('GET')
		expect(init.body).toBeUndefined()
	})

	it('executes only an exact approved missing-readonly fingerprint with POST { input }', async () => {
		const fetchMock = fetchFor(readAbility)
		globalThis.fetch = fetchMock
		const abilities = client(new Set([APPROVED_STORE_CONTEXT]))
		await abilities.discover()

		await abilities.execute(readAbility.name, { customer_id: 7 })

		const [url, init] = (fetchMock.mock.calls as unknown as Array<[URL | string, RequestInit]>).at(
			-1,
		) as [URL | string, RequestInit]
		expect(String(url)).toBe(
			'https://shop.test/wp-json/wp-abilities/v1/abilities/fluent-cart/get-store-context/run',
		)
		expect(init.method).toBe('POST')
		expect(JSON.parse(String(init.body))).toEqual({ input: { customer_id: 7 } })
	})

	it('omits a changed missing-readonly fingerprint before execution', async () => {
		const changed = {
			...readAbility,
			input_schema: {
				type: 'object',
				properties: { changed: { type: 'boolean' } },
			},
		}
		const fetchMock = fetchFor(changed)
		globalThis.fetch = fetchMock
		const abilities = client(new Set([APPROVED_STORE_CONTEXT]))

		expect(await abilities.discover()).toEqual([])
		await expect(abilities.execute(changed.name, {})).rejects.toThrow(
			/not an audited, discovered read/,
		)
		expect(fetchMock).toHaveBeenCalledTimes(2)
	})

	it.each([
		{ annotation: 'readonly', value: 'true' },
		{ annotation: 'destructiveHint', value: 'false' },
	])(
		'omits malformed string annotation $annotation without execution',
		async ({ annotation, value }) => {
			const malformed = {
				...readAbility,
				meta: {
					annotations: {
						...readAbility.meta.annotations,
						[annotation]: value,
					},
				},
			}
			const fetchMock = fetchFor(malformed)
			globalThis.fetch = fetchMock
			const abilities = client(new Set([APPROVED_STORE_CONTEXT]))

			expect(await abilities.discover()).toEqual([])
			await expect(abilities.execute(malformed.name, {})).rejects.toThrow(
				/not an audited, discovered read/,
			)
			expect(fetchMock).toHaveBeenCalledTimes(2)
		},
	)

	it.each([
		{ name: 'GET 405', readonly: true, approved: false, failure: () => response(405, {}) },
		{ name: 'POST 405', readonly: null, approved: true, failure: () => response(405, {}) },
		{ name: 'POST 500', readonly: null, approved: true, failure: () => response(500, {}) },
		{
			name: 'POST timeout',
			readonly: null,
			approved: true,
			failure: () => Promise.reject(new DOMException('Timed out', 'TimeoutError')),
		},
		{
			name: 'POST network failure',
			readonly: null,
			approved: true,
			failure: () => Promise.reject(new TypeError('Network failed')),
		},
	] as const)(
		'does not retry with another method after $name',
		async ({ readonly, approved, failure }) => {
			const ability = {
				...readAbility,
				meta: {
					annotations: {
						...readAbility.meta.annotations,
						readonly,
					},
				},
			}
			const fetchMock = fetchFor(ability, failure)
			globalThis.fetch = fetchMock
			const abilities = client(approved ? new Set([APPROVED_STORE_CONTEXT]) : new Set())
			await abilities.discover()

			await expect(abilities.execute(ability.name, {})).rejects.toThrow()

			const executionCalls = (
				fetchMock.mock.calls as unknown as Array<[URL | string, RequestInit]>
			).filter(([, init]) => init.method !== 'OPTIONS' && init.method !== 'GET')
			const runCalls = (
				fetchMock.mock.calls as unknown as Array<[URL | string, RequestInit]>
			).filter(
				([url, init]) =>
					new URL(String(url)).pathname.endsWith('/run') && init.method !== 'OPTIONS',
			)
			expect(runCalls).toHaveLength(1)
			expect(runCalls[0][1].method).toBe(readonly === true ? 'GET' : 'POST')
			if (readonly === true) expect(executionCalls).toHaveLength(0)
		},
	)

	it('combines caller cancellation with the execution timeout and aborts fetch', async () => {
		let executionSignal: AbortSignal | undefined
		const fetchMock = fetchFor(
			readAbility,
			() =>
				new Promise<Response>((resolve, reject) => {
					const executionCall = (
						fetchMock.mock.calls as unknown as Array<[URL | string, RequestInit]>
					).at(-1)
					executionSignal = executionCall?.[1].signal ?? undefined
					executionSignal?.addEventListener(
						'abort',
						() => reject(new DOMException('The operation was aborted', 'AbortError')),
						{ once: true },
					)
					setTimeout(() => resolve(response(200, { result: { late: true } })), 30)
				}),
		)
		globalThis.fetch = fetchMock
		const abilities = client(new Set([APPROVED_STORE_CONTEXT]))
		await abilities.discover()
		const caller = new AbortController()

		const pending = abilities.execute(readAbility.name, {}, caller.signal)
		await vi.waitFor(() => expect(executionSignal).toBeDefined())
		caller.abort(new Error('client cancelled'))

		await expect(pending).rejects.toMatchObject({ name: 'AbortError' })
		expect(executionSignal).not.toBe(caller.signal)
		expect(executionSignal?.aborted).toBe(true)
	})

	it('keeps the timeout active when a non-aborted caller signal is present', async () => {
		const listTimeout = new AbortController()
		const optionsTimeout = new AbortController()
		const executionTimeout = new AbortController()
		vi.spyOn(AbortSignal, 'timeout')
			.mockReturnValueOnce(listTimeout.signal)
			.mockReturnValueOnce(optionsTimeout.signal)
			.mockReturnValueOnce(executionTimeout.signal)
		let fetchSignal: AbortSignal | undefined
		const fetchMock = fetchFor(
			readAbility,
			() =>
				new Promise<Response>((_resolve, reject) => {
					const executionCall = (
						fetchMock.mock.calls as unknown as Array<[URL | string, RequestInit]>
					).at(-1)
					fetchSignal = executionCall?.[1].signal ?? undefined
					fetchSignal?.addEventListener(
						'abort',
						() => reject(new DOMException('The operation was aborted', 'AbortError')),
						{ once: true },
					)
				}),
		)
		globalThis.fetch = fetchMock
		const abilities = client(new Set([APPROVED_STORE_CONTEXT]))
		await abilities.discover()
		const caller = new AbortController()

		const pending = abilities.execute(readAbility.name, {}, caller.signal)
		await vi.waitFor(() => expect(fetchSignal).toBeDefined())
		executionTimeout.abort(new DOMException('Timed out', 'TimeoutError'))

		await expect(pending).rejects.toMatchObject({ name: 'AbortError' })
		expect(caller.signal.aborted).toBe(false)
		expect(fetchSignal).not.toBe(caller.signal)
		expect(fetchSignal?.aborted).toBe(true)
	})

	it('keeps the execution timeout active when no caller signal is supplied', async () => {
		const listTimeout = new AbortController()
		const optionsTimeout = new AbortController()
		const executionTimeout = new AbortController()
		vi.spyOn(AbortSignal, 'timeout')
			.mockReturnValueOnce(listTimeout.signal)
			.mockReturnValueOnce(optionsTimeout.signal)
			.mockReturnValueOnce(executionTimeout.signal)
		let fetchSignal: AbortSignal | undefined
		const fetchMock = fetchFor(
			readAbility,
			() =>
				new Promise<Response>((_resolve, reject) => {
					const executionCall = (
						fetchMock.mock.calls as unknown as Array<[URL | string, RequestInit]>
					).at(-1)
					fetchSignal = executionCall?.[1].signal ?? undefined
					fetchSignal?.addEventListener(
						'abort',
						() => reject(new DOMException('The operation was aborted', 'AbortError')),
						{ once: true },
					)
				}),
		)
		globalThis.fetch = fetchMock
		const abilities = client(new Set([APPROVED_STORE_CONTEXT]))
		await abilities.discover()

		const pending = abilities.execute(readAbility.name, {})
		await vi.waitFor(() => expect(fetchSignal).toBe(executionTimeout.signal))
		executionTimeout.abort(new DOMException('Timed out', 'TimeoutError'))

		await expect(pending).rejects.toMatchObject({ name: 'AbortError' })
		expect(fetchSignal?.aborted).toBe(true)
	})

	it('refuses an undiscovered or write ability without making an execution request', async () => {
		const fetchMock = fetchFor(readAbility)
		globalThis.fetch = fetchMock
		const abilities = client(new Set([APPROVED_STORE_CONTEXT]))
		await abilities.discover()

		await expect(abilities.execute('fluent-cart/refund-order', {})).rejects.toThrow(
			/not an audited, discovered read/,
		)
		expect(fetchMock).toHaveBeenCalledTimes(2)
	})
})
