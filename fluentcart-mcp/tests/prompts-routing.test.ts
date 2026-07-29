// A prompt may only name a tool the running server has actually registered.
//
// This is the regression guard for a defect that shipped in every mode: the prompts hardcoded a
// numbered list of concrete tool names, and `dynamic` — the default mode — registers none of
// them. It exposes discovery and risk-matched execution tools and discovers the rest at call time. So "Analyze Store
// Performance", the prompt a store owner is most likely to click, told the model to call four
// tools that did not exist, in the configuration most people run. Six of the fifteen names were
// missing from `curated` as well.
//
// The prompt text is now generated against the registered set, so this test renders every prompt
// in every mode and fails if any tool name in the output is not registered in that mode.
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

interface Rendered {
	name: string
	text: string
}

/** Connect a client, list the prompts, and render each one with placeholder arguments. */
async function renderPrompts(mode: string): Promise<{ tools: Set<string>; prompts: Rendered[] }> {
	const { Client } = await import('@modelcontextprotocol/client')
	const { InMemoryTransport } = await import('@modelcontextprotocol/server')

	const server = await createServerFromContextAsync(
		resolveServerContext(),
		mode as Parameters<typeof createServerFromContextAsync>[1],
	)
	const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair()
	const client = new Client({ name: 'prompt-routing', version: '1' }, { capabilities: {} })
	await Promise.all([server.connect(serverTransport), client.connect(clientTransport)])

	const tools = new Set<string>()
	let toolCursor: string | undefined
	do {
		const page = await client.listTools(toolCursor === undefined ? {} : { cursor: toolCursor })
		for (const tool of page.tools) tools.add(tool.name)
		toolCursor = page.nextCursor
	} while (toolCursor !== undefined)

	const listed = await client.listPrompts({})
	const prompts: Rendered[] = []
	for (const prompt of listed.prompts) {
		// Supply every declared argument, so rendering never fails for a missing one.
		const args: Record<string, string> = {}
		for (const argument of prompt.arguments ?? []) {
			args[argument.name] = argument.name.toLowerCase().includes('date') ? '2026-01-01' : '1'
		}
		const result = await client.getPrompt({ name: prompt.name, arguments: args })
		const text = result.messages
			.map((message) => (message.content.type === 'text' ? message.content.text : ''))
			.join('\n')
		prompts.push({ name: prompt.name, text })
	}

	await client.close()
	await server.close()
	return { tools, prompts }
}

describe('prompts never name a tool the mode does not register', () => {
	for (const mode of TOOLSET_MODES) {
		it(`${mode} mode`, async () => {
			const { tools, prompts } = await renderPrompts(mode)
			expect(prompts.length, `${mode} registered no prompts`).toBeGreaterThan(0)

			for (const prompt of prompts) {
				const named = [...new Set(prompt.text.match(/fluentcart_[a-z0-9_]+/g) ?? [])]
				for (const toolName of named) {
					expect(
						tools.has(toolName),
						`prompt "${prompt.name}" names ${toolName}, which ${mode} mode does not register`,
					).toBe(true)
				}
			}
		}, 30_000)
	}

	it('still gives every prompt an actionable body when no concrete tool is named', async () => {
		// Dynamic mode names no entity tools, so the prompt must carry the goal and point at the
		// discovery route. An empty or tool-less prompt would be worse than a wrong one.
		const { prompts } = await renderPrompts('dynamic')
		for (const prompt of prompts) {
			expect(prompt.text.length, `${prompt.name} rendered almost nothing`).toBeGreaterThan(200)
			expect(prompt.text, `${prompt.name} does not tell the caller how to find its tools`).toMatch(
				/fluentcart_search_tools/,
			)
		}
	}, 30_000)

	it('names the contract-backed reports when curated mode registers them', async () => {
		const { prompts } = await renderPrompts('curated')
		const performance = prompts.find((p) => p.name === 'analyze-store-performance')
		expect(performance).toBeDefined()
		expect(performance?.text).toContain('fluentcart_report_sales_summary')
	}, 30_000)

	it('recommends no report that was rejected as a metric', async () => {
		// future_renewals ignores the caller's dates and sums across currencies; sales_growth
		// answers HTTP 500; top_products_sold is deprecated and returns nothing. None of them
		// belongs in a suggested workflow.
		const rejected = [
			'fluentcart_report_future_renewals',
			'fluentcart_report_sales_growth',
			'fluentcart_report_top_products_sold',
		]
		for (const mode of TOOLSET_MODES) {
			const { prompts } = await renderPrompts(mode)
			for (const prompt of prompts) {
				for (const name of rejected) {
					expect(
						prompt.text.includes(name),
						`prompt "${prompt.name}" recommends ${name} in ${mode} mode`,
					).toBe(false)
				}
			}
		}
	}, 60_000)
})
