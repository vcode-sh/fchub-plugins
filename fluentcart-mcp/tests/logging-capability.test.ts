import { Client, InMemoryTransport } from '@modelcontextprotocol/client'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createServerFromContextAsync, resolveServerContext, TOOLSET_MODES } from '../src/server.js'

const ENV_KEYS = ['FLUENTCART_URL', 'FLUENTCART_USERNAME', 'FLUENTCART_APP_PASSWORD']
const original: Record<string, string | undefined> = {}

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

describe('MCP capabilities', () => {
	for (const mode of TOOLSET_MODES) {
		it(`${mode} mode exposes immutable registries without MCP logging`, async () => {
			const server = await createServerFromContextAsync(resolveServerContext(), mode)
			const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair()
			const client = new Client({ name: 'capability-test', version: '1' }, { capabilities: {} })

			await Promise.all([server.connect(serverTransport), client.connect(clientTransport)])
			try {
				const capabilities = client.getServerCapabilities()
				expect(capabilities?.logging).toBeUndefined()
				for (const absent of ['extensions', 'tasks', 'roots', 'sampling']) {
					expect(Object.hasOwn(capabilities ?? {}, absent), `${mode} advertised ${absent}`).toBe(
						false,
					)
				}
				expect(capabilities?.tools?.listChanged).toBe(false)
				expect(capabilities?.resources?.listChanged).toBe(false)
				expect(capabilities?.prompts?.listChanged).toBe(false)
			} finally {
				await client.close()
				await server.close()
			}
		}, 30_000)
	}
})
