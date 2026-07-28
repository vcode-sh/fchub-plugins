/**
 * Shared plumbing for the store-configuration and storefront scenario sweeps.
 *
 * The two sweeps ask the same kind of question of the same registry, so they share a registry
 * builder, the ground-truth helpers and the reporting. Splitting them into two files is a file-size
 * rule, not a boundary in the subject matter, and duplicating this header in both would be the
 * worse of the two costs.
 */
import { expect } from 'vitest'
import type { FluentCartClient } from '../../../src/api/client.js'
import type { ToolDefinition } from '../../../src/tools/_factory.js'
import { commerceContextTools } from '../../../src/tools/commerce-context.js'
import { createAllTools } from '../../../src/tools/index.js'
import {
	type CallResult,
	formatOutcomes,
	runScenario,
	type Scenario,
	type ScenarioContext,
} from './scenario.js'

/**
 * The registry an operator actually gets, composed the way src/server.ts composes it.
 *
 * `createAllTools` alone omits the orientation tool, which server.ts adds afterwards. A sweep built
 * on `createAllTools` alone would therefore report a registry nobody is ever given, and would miss
 * that the orientation tool ranks first for the question it cannot answer.
 *
 * The client is passed in rather than fetched here so each sweep enters through `getLiveClient()`
 * in its own file, which is what the no-unsafe-scenarios guard checks for.
 */
export function buildConfigTools(client: FluentCartClient): ToolDefinition[] {
	return [
		...createAllTools(client, {}),
		...commerceContextTools(client, {
			profile: null,
			operations: null,
			exposedToolNames: [],
			storeUrl: process.env.FLUENTCART_URL ?? 'http://localhost:9081',
		}),
	]
}

/** Asserts that read as a sentence in the failure report. */
export function must(condition: unknown, message: string): asserts condition {
	if (!condition) throw new Error(message)
}

export const STORE_OPTION = 'fluent_cart_store_settings'

/**
 * Prove a value against the PHP-serialised option WordPress actually persisted.
 *
 * `fluent_cart_store_settings` is a serialised array — no column to compare against, no JSON path
 * to extract — so matching the exact `key;value` pair inside the blob is the closest thing
 * available to reading the row the store itself reads.
 */
export function storeHolds(ctx: ScenarioContext, key: string, value: string): boolean {
	const needle = `s:${key.length}:"${key}";s:${value.length}:"${value}"`
	const [[hit]] = ctx.db(
		`select option_value like '%${needle}%' from wp_options where option_name='${STORE_OPTION}';`,
	)
	return hit === '1'
}

/** One number out of the database, for the many scenarios that check a count or a flag. */
export function count(ctx: ScenarioContext, sql: string): number {
	return Number(ctx.db(sql)[0][0])
}

/**
 * The ground-truth queries, named once.
 *
 * Written out here rather than inline so a scenario body reads as the question it is asking, and
 * so two scenarios counting the same thing cannot drift into counting it differently.
 */
export const SQL = {
	admins:
		"select count(*) from wp_usermeta where meta_key='wp_capabilities' and meta_value like '%administrator%';",
	nonAdmins:
		"select count(*) from wp_usermeta where meta_key='wp_capabilities' and meta_value not like '%administrator%';",
	labels: 'select count(*) from wp_fct_label;',
	publishedProducts:
		"select count(*) from wp_posts where post_type='fluent-products' and post_status='publish';",
	emailConfig: "select meta_value from wp_fct_meta where meta_key='email_notifications_config';",
	moduleSettings:
		"select option_value from wp_options where option_name='fluent_cart_modules_settings';",
	// No `replace()` in here: wp-cli reads that keyword as a write statement and prints "Query
	// succeeded" instead of the rows, so the prefix comes off in TypeScript below.
	gatewayFlags:
		"select meta_key, json_unquote(json_extract(meta_value,'$.is_active')) from wp_fct_meta where meta_key like 'fluent_cart_payment_settings_%';",
	orderIntegrations: "select meta_key from wp_fct_meta where object_type='order_integration';",
} as const

/** Gateway slugs the store has switched on, straight out of its own settings rows. */
export function activeGateways(ctx: ScenarioContext): string[] {
	const prefix = 'fluent_cart_payment_settings_'
	return ctx
		.db(SQL.gatewayFlags)
		.filter(([, on]) => on === 'yes')
		.map(([key]) => key.slice(prefix.length))
}

/** Whether WordPress itself knows a role key, rather than whether FluentCart offers it. */
export function roleExists(ctx: ScenarioContext, key: string): boolean {
	const sql = `select option_value like '%"${key}"%' from wp_options where option_name='wp_user_roles';`
	return count(ctx, sql) === 1
}

/** Call a tool and fail the scenario with the store's own words when it refuses. */
export async function ok(
	ctx: ScenarioContext,
	name: string,
	input?: Record<string, unknown>,
): Promise<CallResult> {
	const result = await ctx.call(name, input)
	must(!result.isError, `${name} failed: ${result.text.slice(0, 140)}`)
	return result
}

/** Run a sweep, print it as a report, and fail with that report rather than a stack trace. */
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
