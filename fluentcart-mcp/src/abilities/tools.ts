import { z } from 'zod'
import { redactSensitive } from '../security/redaction.js'
import type { ToolDefinition } from '../tools/_factory.js'
import { READ_SAFETY } from '../tools/risk.js'
import { formatError, formatSuccess } from '../tools/tool-response.js'
import { admitAuditedReadAbilities, type DiscoveredAbility } from './client.js'
import { validateAbilityInput } from './schema.js'

export const ABILITY_BRIDGE_TOOL_NAMES = [
	'fluentcart_search_abilities',
	'fluentcart_describe_abilities',
	'fluentcart_execute_read_ability',
] as const

interface AbilityBridge {
	abilities: readonly DiscoveredAbility[]
	execute: (name: string, input: Record<string, unknown>, signal?: AbortSignal) => Promise<unknown>
}

const annotations = {
	readOnlyHint: true,
	destructiveHint: false,
	idempotentHint: true,
	openWorldHint: true,
}
const safety = READ_SAFETY

function unsupportedRoute() {
	return { routes: [] as [], unsupported: true as const }
}

function define(
	name: string,
	title: string,
	description: string,
	schema: ToolDefinition['schema'],
	handler: ToolDefinition['handler'],
): ToolDefinition {
	return {
		name,
		title,
		description,
		schema,
		annotations,
		safety,
		// This route belongs to the WordPress Abilities namespace rather than fluent-cart/v2.
		// Its exact execution path is pinned by the captured ability contract and client tests.
		route: unsupportedRoute(),
		handler,
	}
}

export function createAbilityBridgeTools(bridge: AbilityBridge): ToolDefinition[] {
	const compatibleAbilities = admitAuditedReadAbilities(bridge.abilities)
	const abilities = new Map(compatibleAbilities.map((ability) => [ability.name, ability]))

	const searchSchema = z.object({
		query: z.string().min(1),
		limit: z.number().int().min(1).max(10).optional(),
	})
	const describeSchema = z.object({
		abilities: z.array(z.string()).min(1).max(5),
	})
	const executeSchema = z.object({
		ability_name: z.string(),
		input: z.record(z.string(), z.unknown()).optional().default({}),
	})

	return [
		define(
			ABILITY_BRIDGE_TOOL_NAMES[0],
			'Search FluentCart Native Read Abilities',
			'Search the live, audited FluentCart native read abilities exposed through the opt-in WordPress Abilities bridge. Write abilities are never included.',
			searchSchema,
			async (raw) => {
				const parsed = searchSchema.safeParse(raw)
				if (!parsed.success)
					return formatError(new Error(`Validation error: ${JSON.stringify(parsed.error.issues)}`))
				const words = parsed.data.query.toLowerCase().split(/\s+/).filter(Boolean)
				const matches = compatibleAbilities
					.filter((ability) => {
						const haystack = `${ability.name} ${ability.label} ${ability.description}`.toLowerCase()
						return words.every((word) => haystack.includes(word))
					})
					.slice(0, parsed.data.limit ?? 5)
					.map(({ name, label, description }) => ({ name, label, description }))
				return formatSuccess(
					{ total_available: abilities.size, matches: matches.length, abilities: matches },
					{ context: ABILITY_BRIDGE_TOOL_NAMES[0], pageable: false },
				)
			},
		),
		define(
			ABILITY_BRIDGE_TOOL_NAMES[1],
			'Describe FluentCart Native Read Abilities',
			'Return the exact live input and output schemas for up to five audited FluentCart native read abilities.',
			describeSchema,
			async (raw) => {
				const parsed = describeSchema.safeParse(raw)
				if (!parsed.success)
					return formatError(new Error(`Validation error: ${JSON.stringify(parsed.error.issues)}`))
				return formatSuccess(
					parsed.data.abilities.map((name) => {
						const ability = abilities.get(name)
						return (
							ability ?? {
								name,
								error: 'Ability not discovered or not allowed by the read-only bridge',
							}
						)
					}),
					{ context: ABILITY_BRIDGE_TOOL_NAMES[1], pageable: false },
				)
			},
		),
		define(
			ABILITY_BRIDGE_TOOL_NAMES[2],
			'Execute a FluentCart Native Read Ability',
			'Execute one audited, live-discovered FluentCart native read ability. Uses the exact discovered input schema and refuses every write or unknown ability.',
			executeSchema,
			async (raw, execution) => {
				try {
					const parsed = executeSchema.safeParse(raw)
					if (!parsed.success) {
						return formatError(
							new Error(`Validation error: ${JSON.stringify(parsed.error.issues)}`),
						)
					}
					const ability = abilities.get(parsed.data.ability_name)
					if (!ability) {
						throw new Error(
							`Ability "${parsed.data.ability_name}" is not discovered or is not an audited read.`,
						)
					}
					const issues = validateAbilityInput(ability.inputSchema, parsed.data.input)
					if (issues.length > 0) throw new Error(`Validation error: ${issues.join('; ')}`)
					const result = redactSensitive(
						await bridge.execute(ability.name, parsed.data.input, execution?.signal),
					)
					return formatSuccess(result, { context: ability.name, pageable: false })
				} catch (error) {
					return formatError(error)
				}
			},
		),
	]
}
