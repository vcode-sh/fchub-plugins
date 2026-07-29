import { Client, InMemoryTransport } from '@modelcontextprotocol/client'
import type { McpServerFactory } from '@modelcontextprotocol/server'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { DiscoveredAbility } from '../src/abilities/client.js'
import type { ApiCapabilities } from '../src/api/capabilities.js'
import type { PreparedCodeModeRuntime } from '../src/tools/code-mode.js'

const counters = vi.hoisted(() => ({
	routeDiscovery: 0,
	abilityDiscovery: 0,
	codePreparation: 0,
}))

const preparationControl = vi.hoisted((): { wait?: () => Promise<void> } => ({}))

const discoveredCapabilities = vi.hoisted(
	(): ApiCapabilities => ({
		operations: new Set<string>(),
		source: 'live-rest-index',
		has: () => false,
	}),
)

const discoveredAbility = vi.hoisted(
	(): DiscoveredAbility => ({
		name: 'fluent-cart/get-store-context',
		label: 'Get store context',
		description: 'Return the store context.',
		category: 'fluent-cart',
		inputSchema: { type: 'object', properties: {} },
		outputSchema: { type: 'object' },
		annotations: {
			abilitiesReadonly: true,
			abilitiesDestructive: false,
			abilitiesIdempotent: null,
			mcpReadOnlyHint: true,
			mcpDestructiveHint: false,
			mcpIdempotentHint: null,
			mcpOpenWorldHint: null,
		},
		rest: {
			discoveryPath: '/wp-abilities/v1/abilities/fluent-cart/get-store-context',
			runPath: '/wp-abilities/v1/abilities/fluent-cart/get-store-context/run',
			methods: ['GET'],
		},
	}),
)

vi.mock('../src/api/capabilities.js', async (importOriginal) => {
	const actual = await importOriginal<typeof import('../src/api/capabilities.js')>()
	return {
		...actual,
		discoverApiCapabilities: vi.fn(async () => {
			counters.routeDiscovery += 1
			return discoveredCapabilities
		}),
	}
})

vi.mock('../src/abilities/client.js', async (importOriginal) => {
	const actual = await importOriginal<typeof import('../src/abilities/client.js')>()
	return {
		...actual,
		createAbilitiesClient: vi.fn(() => ({
			discover: async () => {
				counters.abilityDiscovery += 1
				return [discoveredAbility]
			},
			execute: async () => ({}),
		})),
	}
})

vi.mock('../src/tools/code-mode.js', async (importOriginal) => {
	const actual = await importOriginal<typeof import('../src/tools/code-mode.js')>()
	return {
		...actual,
		prepareCodeModeRuntime: vi.fn(async (tools) => {
			counters.codePreparation += 1
			await preparationControl.wait?.()
			return actual.prepareCodeModeRuntime(tools, {
				sandbox: {
					execute: async () => ({ ok: true, json: '{}', callCount: 0 }),
				},
				skipSelfTest: true,
			} as never) as Promise<PreparedCodeModeRuntime>
		}),
	}
})

import {
	createMcpServerFactory,
	createServerFromContextAsync,
	resolveRuntimeContext,
	resolveServerContext,
} from '../src/server.js'

interface Surface {
	tools: string[]
	resources: string[]
	prompts: string[]
}

async function listSurface(factory: McpServerFactory, era: 'modern' | 'legacy'): Promise<Surface> {
	const server = await factory({ era })
	const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair()
	const client = new Client({ name: `factory-${era}`, version: '1' }, { capabilities: {} })

	await Promise.all([server.connect(serverTransport), client.connect(clientTransport)])
	try {
		return {
			tools: (await client.listTools()).tools.map(({ name }) => name),
			resources: (await client.listResources()).resources.map(({ name }) => name),
			prompts: (await client.listPrompts()).prompts.map(({ name }) => name),
		}
	} finally {
		await client.close()
		await server.close()
	}
}

beforeEach(() => {
	for (const key of Object.keys(counters) as (keyof typeof counters)[]) counters[key] = 0
	preparationControl.wait = undefined
	vi.stubEnv('FLUENTCART_URL', 'https://fixture.invalid')
	vi.stubEnv('FLUENTCART_USERNAME', 'fixture')
	vi.stubEnv('FLUENTCART_APP_PASSWORD', 'fixture-app-password')
	vi.stubEnv('FLUENTCART_WRITE_MODE', 'reversible')
	vi.stubEnv('FLUENTCART_ABILITIES_MODE', 'enabled')
	vi.stubEnv('FLUENTCART_ABILITIES_USERNAME', 'ability-fixture')
	vi.stubEnv('FLUENTCART_ABILITIES_APP_PASSWORD', 'ability-app-password')
})

afterEach(() => {
	vi.unstubAllEnvs()
})

describe('MCP server factory', () => {
	it('resolves store, Ability and Code Mode runtime once before serving either era', async () => {
		const runtime = await resolveRuntimeContext('code')
		const factory = createMcpServerFactory(runtime, 'code')

		await factory({ era: 'modern' })
		await factory({ era: 'legacy' })

		expect(counters).toEqual({
			routeDiscovery: 1,
			abilityDiscovery: 1,
			codePreparation: 1,
		})
	})

	it('registers identical tools, resources and prompts for modern and legacy clients', async () => {
		const runtime = await resolveRuntimeContext('code')
		const factory = createMcpServerFactory(runtime, 'code')
		const modern = await listSurface(factory, 'modern')
		const legacy = await listSurface(factory, 'legacy')

		expect(legacy).toEqual(modern)
	})

	it('shares one Code Mode preparation across concurrent compatibility calls', async () => {
		const context = resolveServerContext()
		let releasePreparation: (() => void) | undefined
		const blocked = new Promise<void>((resolve) => {
			releasePreparation = resolve
		})
		preparationControl.wait = () => blocked

		const first = createServerFromContextAsync(context, 'code')
		await vi.waitFor(() => expect(counters.codePreparation).toBe(1))
		const second = createServerFromContextAsync(context, 'code')
		await new Promise<void>((resolve) => setTimeout(resolve, 10))
		if (!releasePreparation) throw new Error('Preparation barrier did not initialise.')
		releasePreparation()
		await Promise.all([first, second])

		expect(counters.codePreparation).toBe(1)
	})
})
