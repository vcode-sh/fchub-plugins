// Log notifications must actually reach the client.
//
// The server has always built log lines — a startup summary naming the mode and tool count, and a
// config-source line — and none of them were ever delivered. The SDK wraps `sendLoggingMessage`
// in `if (this._capabilities.logging)`, and the server declared no such capability, so every call
// returned silently. `createLogger` was dead code: the strings were assembled, redacted, and
// dropped. The Inspector's Notifications pane, one of the four surfaces it offers, stayed empty
// no matter what the server did.
//
// Declaring the capability exposed a second defect the silence had been hiding. The startup lines
// were emitted during construction, before any transport was connected, so the same call that
// used to no-op began throwing "Not connected". They are now sent from the `initialized`
// notification, which is the first moment a client exists to receive them.

import { Client } from '@modelcontextprotocol/sdk/client/index.js'
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js'
import { LoggingMessageNotificationSchema } from '@modelcontextprotocol/sdk/types.js'
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

interface Connected {
	client: Client
	messages: { level: string; data: string }[]
	close: () => Promise<void>
}

async function connect(mode: string): Promise<Connected> {
	const server = await createServerFromContextAsync(
		resolveServerContext(),
		mode as Parameters<typeof createServerFromContextAsync>[1],
	)
	const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair()
	const messages: { level: string; data: string }[] = []

	const client = new Client({ name: 'logging-test', version: '1' }, { capabilities: {} })
	client.setNotificationHandler(LoggingMessageNotificationSchema, (notification) => {
		messages.push({
			level: String(notification.params.level),
			data: String(notification.params.data),
		})
	})

	await Promise.all([server.connect(serverTransport), client.connect(clientTransport)])
	// The startup lines ride on the `initialized` notification, which is delivered asynchronously.
	await new Promise((resolve) => setTimeout(resolve, 50))

	return {
		client,
		messages,
		close: async () => {
			await client.close()
			await server.close()
		},
	}
}

describe('logging capability', () => {
	for (const mode of TOOLSET_MODES) {
		it(`${mode} mode declares logging and delivers its startup line`, async () => {
			const session = await connect(mode)
			try {
				expect(
					session.client.getServerCapabilities()?.logging,
					`${mode} must declare the logging capability or every notification is discarded`,
				).toBeDefined()

				const info = session.messages.find((message) => message.level === 'info')
				expect(info, `${mode} delivered no startup line`).toBeDefined()
				expect(info?.data).toContain('fluentcart-mcp')
				expect(info?.data).toContain('tools registered')
			} finally {
				await session.close()
			}
		}, 30_000)
	}

	it('answers logging/setLevel rather than -32601', async () => {
		// Declaring the capability is also what makes the SDK register the setLevel handler, so a
		// client that dials the level down is honoured instead of rejected.
		const session = await connect('curated')
		try {
			await expect(session.client.setLoggingLevel('error')).resolves.toBeDefined()
		} finally {
			await session.close()
		}
	}, 30_000)

	it('names the mode and a tool count the caller can sanity-check', async () => {
		const session = await connect('dynamic')
		try {
			const info = session.messages.find((message) => message.level === 'info')
			expect(info?.data).toMatch(/dynamic mode/)
			// Dynamic registers exactly the five meta-tools.
			expect(info?.data).toMatch(/\b5 tools registered\b/)
		} finally {
			await session.close()
		}
	}, 30_000)
})
