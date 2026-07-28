/**
 * Shared plumbing for the two product scenario files.
 *
 * Not a second harness: support/scenario.ts still does the scoring, the discovery check and the
 * reporting. This is only the reading of payloads and the boilerplate that would otherwise be
 * copied into both files — which is how two copies of an assertion drift into disagreeing.
 */
import { expect } from 'vitest'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import {
	formatOutcomes,
	runScenario,
	type Scenario,
	type ScenarioContext,
} from './support/scenario.js'

export type Row = Record<string, unknown>

/** Asserts that reads as a sentence in the failure report. */
export function must(condition: unknown, message: string): asserts condition {
	if (!condition) throw new Error(message)
}

/** The rows out of a `{products: {data: []}}` paginator, whatever else it carries. */
export function rowsOf(body: Record<string, unknown>): Row[] {
	const inner = (body.products ?? body) as Record<string, unknown>
	return (Array.isArray(inner.data) ? inner.data : []) as Row[]
}

export const variantsOf = (body: Record<string, unknown>): Row[] =>
	(Array.isArray(body.variants) ? body.variants : []) as Row[]

/** Ground truth, as one row of columns. */
export const facts = (ctx: ScenarioContext, sql: string): string[] => ctx.db(sql)[0] ?? []

/** "title=value, title=value" — the shape both sides of a variant comparison are reduced to. */
export const pairs = (rows: unknown[][]): string => rows.map(([a, b]) => `${a}=${b}`).join(', ')

/** Run every scenario, print the report, and fail with it rather than with a stack trace. */
export async function sweep(tools: ToolDefinition[], scenarios: Scenario[]): Promise<void> {
	const outcomes = []
	for (const scenario of scenarios) outcomes.push(await runScenario(tools, scenario))
	const report = formatOutcomes(outcomes)
	process.stderr.write(`\n${report}\n`)

	const failed = outcomes.filter((outcome) => !outcome.passed)
	expect(
		failed.map((outcome) => `${outcome.id}: ${outcome.reason}`),
		report,
	).toEqual([])
}
