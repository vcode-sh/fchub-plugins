// What the server tells a client about itself before any tool is listed.
//
// Two things live here. The first is the `instructions` string, which the SDK returns in the
// initialize result and clients prepend to a model's context: it is the only place to say that a
// missing write tool is policy rather than an error, and that report money and record money are
// not in the same units. The second is the `listChanged` question: all three registries are
// complete at construction, so the server declares the flags false and never sends a change.

import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { Client } from '@modelcontextprotocol/client'
import { InMemoryTransport } from '@modelcontextprotocol/server'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import {
	createServerFromContextAsync,
	resolveServerContext,
	TOOLSET_MODES,
	type ToolsetMode,
} from '../src/server.js'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const ENV_KEYS = ['FLUENTCART_URL', 'FLUENTCART_USERNAME', 'FLUENTCART_APP_PASSWORD']
const original: Record<string, string | undefined> = {}

/** Instructions ride on every session, so their length is a per-session cost, not a one-off. */
const INSTRUCTIONS_BUDGET = 600

beforeEach(() => {
	for (const key of ENV_KEYS) original[key] = process.env[key]
	process.env.FLUENTCART_URL = 'https://fixture.invalid'
	process.env.FLUENTCART_USERNAME = 'fixture'
	process.env.FLUENTCART_APP_PASSWORD = 'fixture-app-password'
})

afterEach(() => {
	for (const key of ENV_KEYS) {
		if (original[key] === undefined) delete process.env[key]
		else process.env[key] = original[key]
	}
})

interface Session {
	client: Client
	close: () => Promise<void>
}

async function connect(mode: ToolsetMode): Promise<Session> {
	const server = await createServerFromContextAsync(resolveServerContext(), mode)
	const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair()
	const client = new Client({ name: 'protocol-surface-test', version: '1' }, { capabilities: {} })

	await Promise.all([server.connect(serverTransport), client.connect(clientTransport)])

	return {
		client,
		close: async () => {
			await client.close()
			await server.close()
		},
	}
}

function sourceFiles(directory = join(PACKAGE_ROOT, 'src')): string[] {
	const files: string[] = []
	for (const entry of readdirSync(directory, { withFileTypes: true })) {
		const path = join(directory, entry.name)
		if (entry.isDirectory()) files.push(...sourceFiles(path))
		else if (entry.name.endsWith('.ts')) files.push(path)
	}
	return files
}

describe('server instructions', () => {
	it('is delivered in the initialize result of every mode', async () => {
		for (const mode of TOOLSET_MODES) {
			const session = await connect(mode)
			try {
				const instructions = session.client.getInstructions()
				expect(instructions, `${mode} delivered no instructions`).toBeTruthy()
				expect(instructions!.length).toBeLessThanOrEqual(INSTRUCTIONS_BUDGET)
			} finally {
				await session.close()
			}
		}
	}, 30_000)

	it('states the things a caller gets wrong without being told', async () => {
		const session = await connect('dynamic')
		try {
			const instructions = session.client.getInstructions() ?? ''

			// Exposure: a hidden write tool is policy, so an agent must not treat its absence as a
			// transient failure and retry. The variable is named so the operator can act on it.
			expect(instructions).toContain('FLUENTCART_WRITE_MODE')

			// Reports: the parameters `assertValidRequest` refuses to do without, and the warnings
			// array every report envelope carries.
			expect(instructions).toMatch(/currency/i)
			expect(instructions).toMatch(/warnings/i)
			expect(instructions).toMatch(/timezone is optional/i)
			expect(instructions).toMatch(/echoed/i)
			expect(instructions).not.toContain('require from, to, currency and timezone')

			// Units: records are integer minor units, the sales reports are decimals. Confusing the
			// two misreports revenue by a factor of a hundred.
			expect(instructions).toMatch(/minor units/i)
			expect(instructions).toMatch(/decimals/i)
		} finally {
			await session.close()
		}
	}, 30_000)
})

describe('list-changed notifications', () => {
	it('never sends one, because the registry is frozen at construction', () => {
		// Every tool, resource and prompt is registered inside createServerFromContext and nothing
		// adds, removes, enables or disables one afterwards, so there is no moment at which a list
		// changes and therefore nothing to notify. If a future change makes registration dynamic,
		// this assertion is the reminder that the notification has to be fired too.
		const senders = /send(Tool|Resource|Prompt)ListChanged/
		const offenders = sourceFiles().filter((path) => senders.test(readFileSync(path, 'utf8')))

		expect(offenders).toEqual([])
	})

	it('advertises immutable lists and no client-directed logging in every mode', async () => {
		for (const mode of TOOLSET_MODES) {
			const session = await connect(mode)
			try {
				const capabilities = session.client.getServerCapabilities()
				expect(capabilities?.logging, mode).toBeUndefined()
				expect(capabilities?.tools?.listChanged, mode).toBe(false)
				expect(capabilities?.resources?.listChanged, mode).toBe(false)
				expect(capabilities?.prompts?.listChanged, mode).toBe(false)
			} finally {
				await session.close()
			}
		}
	}, 30_000)
})
