// Live principal-boundary lane. Reachable only through scripts/run-live-tests.mjs, which owns
// credential loading, target policy and the run identity.
//
// The property under test is narrow and load-bearing: when the store refuses a request, the
// refusal must reach the caller as an error carrying its status. An agent that receives an empty
// success cannot tell "you may not see this" from "there is nothing here", and will tell a
// merchant they have no orders when in truth it was never allowed to look.
//
// Nothing here creates, updates or deletes anything. Every request is a GET.
import { beforeAll, describe, expect, it } from 'vitest'
import { createClient, type FluentCartClient } from '../../src/api/client.js'
import { FluentCartApiError } from '../../src/api/errors.js'
import { resolveApiUrls } from '../../src/config/types.js'
import { createAllTools } from '../../src/tools/index.js'
import { getLiveClient } from './support/live-client.js'
import { getLiveRun } from './support/live-run.js'

getLiveRun()

/**
 * A principal the store has never issued a credential to.
 *
 * Deliberately a fictitious user rather than the real account with a wrong password: repeatedly
 * failing authentication against the owner's administrator would tick real failure counters and,
 * with a security plugin installed, could lock the only credential this suite has.
 */
const UNKNOWN_PRINCIPAL = {
	username: 'fluentcart-mcp-acceptance-absent-user',
	appPassword: 'wxyz wxyz wxyz wxyz wxyz wxyz',
}

/** Admin-namespace reads used as probes. All are GETs and none returns a body we keep. */
const ADMIN_READS = ['/app/init', '/orders', '/settings/store'] as const

/**
 * Routes that may refuse even an administrator, because they answer for a logged-in shopper
 * rather than for a store operator. Probed rather than assumed: the test reports what the store
 * actually does instead of asserting a 403 it may never produce.
 */
const CUSTOMER_SESSION_READS = [
	'/customer-profile/profile',
	'/customer-profile/subscriptions',
	'/customer-profile/orders',
] as const

interface Outcome {
	route: string
	status: number | null
	code: string | null
	/** True when the call returned 2xx. */
	succeeded: boolean
	/** Body shape, recorded only to prove a refusal never arrived as an empty success. */
	emptyish: boolean
}

function isEmptyish(value: unknown): boolean {
	if (value === null || value === undefined) return true
	if (Array.isArray(value)) return value.length === 0
	if (typeof value !== 'object') return false

	const record = value as Record<string, unknown>
	const keys = Object.keys(record)
	if (keys.length === 0) return true
	for (const key of ['data', 'items', 'orders', 'products', 'customers']) {
		if (Array.isArray(record[key]) && (record[key] as unknown[]).length === 0) return true
	}
	return false
}

async function probe(client: FluentCartClient, route: string): Promise<Outcome> {
	try {
		const response = await client.get(route)
		return {
			route,
			status: response.status,
			code: null,
			succeeded: true,
			emptyish: isEmptyish(response.data),
		}
	} catch (error) {
		if (error instanceof FluentCartApiError) {
			return {
				route,
				status: error.status ?? null,
				code: error.code,
				succeeded: false,
				emptyish: false,
			}
		}
		throw error
	}
}

function unauthorisedClient(): FluentCartClient {
	// The target comes from the shared run harness rather than process.env directly: the
	// repository contract keeps scripts/run-live-tests.mjs as the single credential-loading
	// entry point, and a second reader of the environment is how that boundary erodes. Only the
	// principal is replaced here — deliberately unknown, so the store answers 401.
	const url = getLiveRun().target.href
	return createClient(resolveApiUrls({ url, ...UNKNOWN_PRINCIPAL }))
}

describe('the provisioned principal can perform its intended reads', () => {
	let outcomes: Outcome[] = []

	beforeAll(async () => {
		const client = getLiveClient()
		outcomes = []
		for (const route of ADMIN_READS) outcomes.push(await probe(client, route))
	})

	// Positive control. Without it, every refusal below could equally be a broken client, and the
	// suite would "prove" authorisation while actually proving nothing reaches the store at all.
	it('reads at least one admin route successfully', () => {
		const succeeded = outcomes.filter((outcome) => outcome.succeeded)
		expect(
			succeeded.length,
			`no admin read succeeded: ${JSON.stringify(outcomes)}`,
		).toBeGreaterThan(0)
	})

	it('never reports a refusal as a successful read', () => {
		for (const outcome of outcomes) {
			if (outcome.succeeded) continue
			expect([401, 403, 404], `${outcome.route} failed with an unexpected status`).toContain(
				outcome.status,
			)
		}
	})
})

describe('an unauthorised principal is refused, not answered emptily', () => {
	let outcomes: Outcome[] = []

	beforeAll(async () => {
		const client = unauthorisedClient()
		outcomes = []
		for (const route of ADMIN_READS) outcomes.push(await probe(client, route))
	})

	it('refuses every admin read', () => {
		// Recorded in the lane output so the evidence is the observed statuses, not merely a green
		// tick that could equally mean the probe never reached the store.
		console.error(
			`unauthorised principal outcomes: ${outcomes
				.map(
					(outcome) =>
						`${outcome.route}=${outcome.succeeded ? 'ok' : `${outcome.status}/${outcome.code}`}`,
				)
				.join(' ')}`,
		)
		for (const outcome of outcomes) {
			expect(outcome.succeeded, `${outcome.route} answered an unknown principal`).toBe(false)
		}
	})

	it('preserves the upstream status on every refusal', () => {
		for (const outcome of outcomes) {
			expect([401, 403], `${outcome.route} returned status ${outcome.status}`).toContain(
				outcome.status,
			)
		}
	})

	it('maps the status to a named error code rather than a generic failure', () => {
		for (const outcome of outcomes) {
			expect(['AUTH_FAILED', 'FORBIDDEN'], `${outcome.route} mapped to ${outcome.code}`).toContain(
				outcome.code,
			)
		}
	})

	// The assertion the whole file exists for.
	it('never converts a refusal into an empty success', () => {
		const coerced = outcomes.filter((outcome) => outcome.succeeded && outcome.emptyish)
		expect(
			coerced,
			'a refused read came back as a successful empty payload, which an agent cannot distinguish from an empty store',
		).toEqual([])
	})
})

describe('the tool boundary preserves the refusal', () => {
	let toolResult: { content: { type: string; text: string }[]; isError?: boolean }
	let toolName: string

	beforeAll(async () => {
		const tools = createAllTools(unauthorisedClient())
		const listTool =
			tools.find((tool) => tool.name === 'fluentcart_order_list') ??
			tools.find((tool) => tool.safety.risk === 'read')
		if (!listTool) throw new Error('no read tool is registered to exercise')
		toolName = listTool.name
		toolResult = await listTool.handler({})
	})

	it('marks the response as an MCP error', () => {
		expect(toolResult.isError, `${toolName} did not report an error`).toBe(true)
	})

	it('names the refusal in the response text', () => {
		const text = toolResult.content.map((part) => part.text).join(' ')
		expect(text).toMatch(/AUTH_FAILED|FORBIDDEN|Authentication failed|Permission denied/)
	})

	it('returns no rows that could be mistaken for an empty store', () => {
		const text = toolResult.content.map((part) => part.text).join(' ')
		// A refusal must not serialise as a readable payload at all, empty or otherwise.
		for (const shape of ['{"data":[]}', '"total":0', '{"items":[]}', '[]']) {
			expect(text, `${toolName} returned ${shape} for a refused read`).not.toBe(shape)
		}
		expect(() => JSON.parse(text)).toThrow()
	})
})

/**
 * Whether this store refuses an administrator anywhere is a property of the store, not of the
 * server, so it is discovered and reported rather than asserted. A 403 that never occurs would
 * otherwise be "proved" by a test that quietly matched nothing.
 */
describe('403 handling for routes the operator principal cannot reach', () => {
	let outcomes: Outcome[] = []

	beforeAll(async () => {
		const client = getLiveClient()
		outcomes = []
		for (const route of CUSTOMER_SESSION_READS) outcomes.push(await probe(client, route))
	})

	it('preserves the status of every refusal it does encounter', () => {
		for (const outcome of outcomes) {
			if (outcome.succeeded) continue
			expect(outcome.status, `${outcome.route} refused without a status`).not.toBeNull()
			expect(outcome.code, `${outcome.route} refused without an error code`).not.toBeNull()
		}
	})

	it('reports whether a live 403 was reachable with the provisioned identity', () => {
		const forbidden = outcomes.filter((outcome) => outcome.status === 403)
		const summary = outcomes
			.map((outcome) => `${outcome.route}=${outcome.succeeded ? 'ok' : outcome.status}`)
			.join(' ')

		// Recorded either way. The assertion is that the probe ran and every route is accounted
		// for, not that a 403 exists — the administrator may legitimately reach all of these.
		expect(outcomes.length).toBe(CUSTOMER_SESSION_READS.length)
		console.error(
			forbidden.length > 0
				? `live 403 observed with the provisioned principal: ${summary}`
				: `no live 403 reachable with the provisioned principal (administrator): ${summary}. ` +
						'A least-privilege operator account is required to prove the forbidden-read differential.',
		)
	})
})
