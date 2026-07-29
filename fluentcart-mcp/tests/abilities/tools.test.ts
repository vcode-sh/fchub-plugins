import { describe, expect, it, vi } from 'vitest'
import type { DiscoveredAbility } from '../../src/abilities/client.js'
import { createAbilityBridgeTools } from '../../src/abilities/tools.js'

const definition: DiscoveredAbility = {
	name: 'fluent-cart/get-store-context',
	label: 'Get Store Context',
	description: 'Return store context.',
	category: 'fluent-cart',
	inputSchema: {
		type: 'object',
		properties: {
			include: { type: 'array', items: { type: 'string', enum: ['currency'] } },
			huge: { type: 'boolean' },
		},
	},
	outputSchema: [],
	annotations: {
		abilitiesReadonly: true,
		abilitiesDestructive: null,
		abilitiesIdempotent: null,
		mcpReadOnlyHint: true,
		mcpDestructiveHint: false,
		mcpIdempotentHint: null,
		mcpOpenWorldHint: null,
	},
	rest: {
		discoveryPath: '/wp-abilities/v1/abilities/fluent-cart/get-store-context',
		runPath: '/wp-abilities/v1/abilities/fluent-cart/get-store-context/run',
		methods: ['DELETE', 'GET', 'PATCH', 'POST', 'PUT'],
	},
}

describe('ability bridge tools', () => {
	it('searches and describes only discovered audited reads', async () => {
		const tools = createAbilityBridgeTools({
			abilities: [definition],
			execute: async () => ({ ok: true }),
		})

		expect(tools.map((tool) => tool.name)).toEqual([
			'fluentcart_search_abilities',
			'fluentcart_describe_abilities',
			'fluentcart_execute_read_ability',
		])
		const search = await tools[0].handler({ query: 'store' })
		expect(JSON.parse(search.content[0].text).abilities[0].name).toBe(
			'fluent-cart/get-store-context',
		)
		const describe = await tools[1].handler({
			abilities: ['fluent-cart/get-store-context'],
		})
		expect(JSON.parse(describe.content[0].text)[0].inputSchema).toEqual(definition.inputSchema)
	})

	it('validates against the discovered input schema before dispatch', async () => {
		let calls = 0
		const tools = createAbilityBridgeTools({
			abilities: [definition],
			execute: async () => {
				calls += 1
				return { ok: true }
			},
		})
		const execute = tools[2]

		const invalid = await execute.handler({
			ability_name: definition.name,
			input: { include: ['unknown'] },
		})
		expect(invalid.isError).toBe(true)
		expect(invalid.content[0].text).toMatch(/Validation error/)
		expect(calls).toBe(0)

		const valid = await execute.handler({
			ability_name: definition.name,
			input: { include: ['currency'] },
		})
		expect(valid.isError).toBeUndefined()
		expect(calls).toBe(1)
	})

	it('redacts and bounds successful upstream output', async () => {
		const huge = 'x'.repeat(41_000)
		const tools = createAbilityBridgeTools({
			abilities: [definition],
			execute: async (_name, input) =>
				input.huge === true
					? { huge }
					: { api_token: 'secret', nested: { value: 'Bearer abcdefghijklmnop' } },
		})
		const execute = tools[2]

		const safe = await execute.handler({ ability_name: definition.name, input: {} })
		expect(safe.content[0].text).not.toContain('secret')
		expect(safe.content[0].text).not.toContain('abcdefghijklmnop')

		const oversized = await execute.handler({
			ability_name: definition.name,
			input: { huge: true },
		})
		expect(oversized.isError).toBe(true)
		expect(oversized.content[0].text).toMatch(/RESPONSE_TOO_LARGE/)
	})

	it('passes the exact tool execution signal to the Ability client', async () => {
		const executeAbility = vi.fn(async () => ({ ok: true }))
		const tools = createAbilityBridgeTools({
			abilities: [definition],
			execute: executeAbility,
		})
		const requestSignal = new AbortController().signal

		await tools[2].handler({ ability_name: definition.name, input: {} }, { signal: requestSignal })

		expect(executeAbility).toHaveBeenCalledWith(definition.name, {}, requestSignal)
	})

	it('refuses an unknown Ability without dispatching it', async () => {
		let calls = 0
		const tools = createAbilityBridgeTools({
			abilities: [definition],
			execute: async () => {
				calls += 1
				return { ok: true }
			},
		})

		const result = await tools[2].handler({
			ability_name: 'fluent-cart/future-read',
			input: {},
		})

		expect(result.isError).toBe(true)
		expect(result.content[0].text).toMatch(/not discovered or is not an audited read/)
		expect(calls).toBe(0)
	})

	it('omits a changed missing-readonly Ability even when injected directly', async () => {
		let calls = 0
		const changed = {
			...definition,
			annotations: {
				...definition.annotations,
				abilitiesReadonly: null,
			},
			inputSchema: {
				type: 'object',
				properties: { changed: { type: 'boolean' } },
			},
		}
		const tools = createAbilityBridgeTools({
			abilities: [changed],
			execute: async () => {
				calls += 1
				return { ok: true }
			},
		})

		const search = await tools[0].handler({ query: 'store' })
		expect(JSON.parse(search.content[0].text).abilities).toEqual([])
		const execute = await tools[2].handler({ ability_name: changed.name, input: {} })
		expect(execute.isError).toBe(true)
		expect(calls).toBe(0)
	})

	it.each([
		{
			name: 'an unallowlisted name',
			abilities: [
				{
					...definition,
					name: 'fluent-cart/future-read',
					rest: {
						...definition.rest,
						discoveryPath: '/wp-abilities/v1/abilities/fluent-cart/future-read',
						runPath: '/wp-abilities/v1/abilities/fluent-cart/future-read/run',
					},
				},
			],
		},
		{
			name: 'a foreign category',
			abilities: [{ ...definition, category: 'foreign-cart' }],
		},
		{
			name: 'a non-canonical discovery path',
			abilities: [
				{
					...definition,
					rest: { ...definition.rest, discoveryPath: '/wp-abilities/v1/abilities/other/read' },
				},
			],
		},
		{
			name: 'a non-canonical run path',
			abilities: [
				{
					...definition,
					rest: { ...definition.rest, runPath: '/wp-abilities/v1/abilities/other/read/run' },
				},
			],
		},
		{
			name: 'missing selected-method OPTIONS evidence',
			abilities: [
				{
					...definition,
					rest: { ...definition.rest, methods: ['POST'] },
				},
			],
		},
		{
			name: 'a duplicated valid name',
			abilities: [definition, { ...definition }],
		},
	] as const)('omits $name at the tool boundary and never dispatches', async ({ abilities }) => {
		let calls = 0
		const tools = createAbilityBridgeTools({
			abilities,
			execute: async () => {
				calls += 1
				return { ok: true }
			},
		})

		const search = await tools[0].handler({ query: 'store' })
		expect(JSON.parse(search.content[0].text).abilities).toEqual([])
		const describe = await tools[1].handler({
			abilities: abilities.map((ability) => ability.name),
		})
		const descriptions = JSON.parse(describe.content[0].text)
		expect(descriptions.every((entry: { error?: string }) => typeof entry.error === 'string')).toBe(
			true,
		)
		const execute = await tools[2].handler({
			ability_name: abilities[0].name,
			input: {},
		})
		expect(execute.isError).toBe(true)
		expect(calls).toBe(0)
	})
})
