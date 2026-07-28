/**
 * A scenario harness: can a merchant's actual question be answered, correctly, and for what.
 *
 * Every defect worth finding tonight was found the same way — by asking a real question end to
 * end rather than by calling a tool and checking it returned HTTP 200. `tax_rate_list` returned
 * 200 on nothing at all. `shipping_zone_states` returned 200 with an empty list for every country
 * on earth. `report_product` returns 200 with a number that is wrong by however many multi-line
 * orders the store has taken. None of those is visible from a green request.
 *
 * So a scenario scores three things, and a pass needs all three:
 *
 *  - DISCOVERY — in dynamic mode, search IS the interface. A tool the agent cannot find does not
 *    exist, however well it works.
 *  - ANSWER — the call chain completes and returns the facts the question asked for.
 *  - COST — what the whole chain spent. A correct answer that costs 50,000 characters is a
 *    scenario that fails on a real context budget.
 *
 * Ground truth comes from the database, not from another endpoint, wherever a number is checked.
 */
import { execFileSync } from 'node:child_process'
import type { ToolDefinition } from '../../../src/tools/_factory.js'
import { searchTools } from '../../../src/tools/dynamic-search.js'

/** Where the agent looks: the default search limit. */
export const VISIBLE = 5

export interface CallResult {
	isError: boolean
	text: string
	body: Record<string, unknown>
	chars: number
}

export interface ScenarioContext {
	/** Invoke a tool and record what it cost. */
	call(name: string, input?: Record<string, unknown>): Promise<CallResult>
	/** The tool names an agent would see for this query, in rank order. */
	search(query: string, limit?: number): string[]
	/** Ground truth. Returns rows of columns, tab-separated by wp-cli. */
	db(sql: string): string[][]
	/** Record something the scenario proved, or a caveat worth reporting. */
	note(text: string): void
	/** Total characters returned across every call this scenario made. */
	spent(): number
	/** How many tool calls this scenario needed. */
	calls(): number
}

export interface Scenario {
	/** Short stable id, used in the report. */
	id: string
	/** The question as a person would ask it. */
	question: string
	/**
	 * What the agent would type into search, and the tool that must be visible for it.
	 * Omit only when the scenario is reached from another tool's output rather than by searching.
	 */
	discovery?: { query: string; expect: string; within?: number }
	/** The chain. Throw or return false to fail; the harness records the reason. */
	run: (ctx: ScenarioContext) => Promise<void>
	/** Fail the scenario if the whole chain costs more than this many characters. */
	budget?: number
}

const PLAYGROUND = '/Users/tomrobak/_projects_/fchub-playground'

/**
 * Query the store database directly.
 *
 * Deliberately not another REST call: checking one endpoint against another proves only that two
 * endpoints agree, which is exactly the trap the report families fell into — seven routes, three
 * different totals, every one of them HTTP 200.
 */
export function queryDatabase(sql: string): string[][] {
	const out = execFileSync(
		'docker',
		['compose', 'exec', '-T', 'wpcli', 'wp', 'db', 'query', sql, '--skip-column-names'],
		{ cwd: PLAYGROUND, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] },
	)
	return out
		.split('\n')
		.map((line) => line.trim())
		.filter((line) => line !== '')
		.map((line) => line.split('\t'))
}

export interface ScenarioOutcome {
	id: string
	question: string
	passed: boolean
	reason?: string
	discoveryRank?: number
	calls: number
	chars: number
	notes: string[]
}

function makeContext(
	tools: ToolDefinition[],
): ScenarioContext & { outcome: () => [number, number, string[]] } {
	let chars = 0
	let calls = 0
	const notes: string[] = []

	return {
		async call(name, input = {}) {
			const tool = tools.find((candidate) => candidate.name === name)
			if (!tool) throw new Error(`${name} is not registered`)
			const result = (await tool.handler(input as never, {} as never)) as {
				isError?: boolean
				content: { text: string }[]
			}
			const text = result.content[0]?.text ?? ''
			calls += 1
			chars += text.length
			let body: Record<string, unknown> = {}
			try {
				body = JSON.parse(text || '{}')
			} catch {
				body = { raw: text }
			}
			return { isError: Boolean(result.isError), text, body, chars: text.length }
		},
		search(query, limit = VISIBLE) {
			return searchTools(tools, query, { limit }).map((row) => row.name)
		},
		db: queryDatabase,
		note(text) {
			notes.push(text)
		},
		spent: () => chars,
		calls: () => calls,
		outcome: () => [calls, chars, notes],
	}
}

/** Run one scenario, catching its failure rather than aborting the sweep. */
export async function runScenario(
	tools: ToolDefinition[],
	scenario: Scenario,
): Promise<ScenarioOutcome> {
	const ctx = makeContext(tools)
	let passed = true
	let reason: string | undefined
	let discoveryRank: number | undefined

	if (scenario.discovery) {
		const within = scenario.discovery.within ?? VISIBLE
		const names = ctx.search(scenario.discovery.query, Math.max(within, VISIBLE))
		const rank = names.indexOf(scenario.discovery.expect)
		discoveryRank = rank < 0 ? undefined : rank + 1
		if (rank < 0 || rank >= within) {
			passed = false
			reason =
				rank < 0
					? `not discoverable: "${scenario.discovery.query}" never returns ${scenario.discovery.expect}; got ${names.slice(0, 3).join(', ')}`
					: `discovery rank ${rank + 1}, needed top ${within}`
		}
	}

	if (passed) {
		try {
			await scenario.run(ctx)
		} catch (error) {
			passed = false
			reason = error instanceof Error ? error.message : String(error)
		}
	}

	const [calls, chars, notes] = ctx.outcome()
	if (passed && scenario.budget !== undefined && chars > scenario.budget) {
		passed = false
		reason = `cost ${chars} characters against a ${scenario.budget} budget`
	}

	return {
		id: scenario.id,
		question: scenario.question,
		passed,
		reason,
		discoveryRank,
		calls,
		chars,
		notes,
	}
}

/** One line per scenario, so a failing sweep reads as a report rather than a stack trace. */
export function formatOutcomes(outcomes: ScenarioOutcome[]): string {
	const lines = outcomes.map((outcome) => {
		const mark = outcome.passed ? 'PASS' : 'FAIL'
		const rank = outcome.discoveryRank ? `#${outcome.discoveryRank}` : '—'
		const head = `${mark}  ${outcome.id.padEnd(26)} search:${rank.padEnd(4)} calls:${String(outcome.calls).padStart(2)} chars:${String(outcome.chars).padStart(6)}  ${outcome.question}`
		const detail = outcome.reason ? `\n        ↳ ${outcome.reason}` : ''
		const notes = outcome.notes.map((note) => `\n        · ${note}`).join('')
		return head + detail + notes
	})
	const failed = outcomes.filter((outcome) => !outcome.passed)
	const total = outcomes.reduce((sum, outcome) => sum + outcome.chars, 0)
	return [
		...lines,
		'',
		`${outcomes.length - failed.length}/${outcomes.length} scenarios answered, ${total} characters across the sweep`,
	].join('\n')
}
