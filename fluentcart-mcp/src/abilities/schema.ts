export interface JsonSchema {
	type?: string | readonly string[]
	properties?: Record<string, JsonSchema>
	required?: readonly string[]
	items?: JsonSchema
	enum?: readonly unknown[]
	[key: string]: unknown
}

export interface AbilityAnnotations {
	abilitiesReadonly: boolean | null
	abilitiesDestructive: boolean | null
	abilitiesIdempotent: boolean | null
	mcpReadOnlyHint: boolean | null
	mcpDestructiveHint: boolean | null
	mcpIdempotentHint: boolean | null
	mcpOpenWorldHint: boolean | null
}

export interface DiscoveredAbility {
	name: string
	label: string
	description: string
	category: string
	inputSchema: JsonSchema
	outputSchema: unknown
	annotations: AbilityAnnotations
	rest: {
		discoveryPath: string
		runPath: string
		methods: readonly string[]
	}
}

interface WordPressAbility {
	name?: unknown
	label?: unknown
	description?: unknown
	category?: unknown
	input_schema?: unknown
	output_schema?: unknown
	meta?: { annotations?: Record<string, unknown> }
	_links?: { 'wp:action-run'?: Array<{ href?: unknown }> }
}

function canonicalise(value: unknown): unknown {
	if (Array.isArray(value)) return value.map(canonicalise)
	if (value !== null && typeof value === 'object') {
		return Object.fromEntries(
			Object.keys(value)
				.sort()
				.map((key) => [key, canonicalise((value as Record<string, unknown>)[key])]),
		)
	}
	return value
}

/** Serialize JSON with recursively sorted object keys and stable array order. */
export function canonicalJson(value: unknown): string {
	const json = JSON.stringify(canonicalise(value))
	if (json === undefined) throw new Error('Ability input is not JSON serialisable.')
	return json
}

function isSchema(value: unknown): value is JsonSchema {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function booleanOrNull(value: unknown): boolean | null | undefined {
	if (value === null || value === undefined) return null
	if (typeof value === 'boolean') return value
	return undefined
}

function abilityAnnotations(raw: WordPressAbility): AbilityAnnotations | null {
	const source = raw.meta?.annotations ?? {}
	const parsed = {
		abilitiesReadonly: booleanOrNull(source.readonly),
		abilitiesDestructive: booleanOrNull(source.destructive),
		abilitiesIdempotent: booleanOrNull(source.idempotent),
		mcpReadOnlyHint: booleanOrNull(source.readOnlyHint),
		mcpDestructiveHint: booleanOrNull(source.destructiveHint),
		mcpIdempotentHint: booleanOrNull(source.idempotentHint),
		mcpOpenWorldHint: booleanOrNull(source.openWorldHint),
	}
	if (Object.values(parsed).some((value) => value === undefined)) return null
	return parsed as AbilityAnnotations
}

function runPath(raw: WordPressAbility): string | null {
	if (typeof raw.name !== 'string') return null
	const href = raw._links?.['wp:action-run']?.[0]?.href
	if (typeof href !== 'string') return null
	let pathname: string
	try {
		pathname = new URL(href, 'https://ability.invalid').pathname
	} catch {
		return null
	}
	const marker = '/wp-json'
	const markerIndex = pathname.lastIndexOf(marker)
	const relative = markerIndex === -1 ? pathname : pathname.slice(markerIndex + marker.length)
	const expected = `/wp-abilities/v1/abilities/${raw.name}/run`
	return relative === expected ? expected : null
}

function abilityRows(value: unknown): WordPressAbility[] {
	if (!Array.isArray(value)) throw new Error('Ability discovery returned a non-array payload.')
	return value as WordPressAbility[]
}

export function potentialAbilityNames(value: unknown, allowedNames: ReadonlySet<string>): string[] {
	const names = new Set<string>()
	for (const raw of abilityRows(value)) {
		if (
			typeof raw?.name === 'string' &&
			allowedNames.has(raw.name) &&
			raw.category === 'fluent-cart' &&
			isSchema(raw.input_schema) &&
			runPath(raw)
		) {
			names.add(raw.name)
		}
	}
	return [...names].sort()
}

export function projectAbilityRows(
	value: unknown,
	allowedNames: ReadonlySet<string>,
	restMethods: ReadonlyMap<string, readonly string[]>,
): DiscoveredAbility[] {
	const projected: DiscoveredAbility[] = []
	for (const raw of abilityRows(value)) {
		if (
			typeof raw.name !== 'string' ||
			!allowedNames.has(raw.name) ||
			raw.category !== 'fluent-cart' ||
			!isSchema(raw.input_schema)
		) {
			continue
		}
		const annotations = abilityAnnotations(raw)
		const path = runPath(raw)
		if (!(annotations && path)) continue
		projected.push({
			name: raw.name,
			label: typeof raw.label === 'string' ? raw.label : raw.name,
			description: typeof raw.description === 'string' ? raw.description : '',
			category: raw.category,
			inputSchema: raw.input_schema,
			outputSchema: raw.output_schema ?? null,
			annotations,
			rest: {
				discoveryPath: `/wp-abilities/v1/abilities/${raw.name}`,
				runPath: path,
				methods: [
					...new Set((restMethods.get(raw.name) ?? []).map((method) => method.toUpperCase())),
				].sort(),
			},
		})
	}
	return projected
}

export function methodsFromAbilityOptions(value: unknown): string[] {
	if (value === null || typeof value !== 'object') return []
	const options = value as {
		methods?: unknown
		endpoints?: Array<{ methods?: unknown }>
	}
	const methods = [
		...(Array.isArray(options.methods) ? options.methods : []),
		...(Array.isArray(options.endpoints)
			? options.endpoints.flatMap((endpoint) =>
					Array.isArray(endpoint?.methods) ? endpoint.methods : [],
				)
			: []),
	]
	return [
		...new Set(methods.filter((method): method is string => typeof method === 'string')),
	].sort()
}

function typeMatches(value: unknown, expected: string): boolean {
	if (expected === 'null') return value === null
	if (expected === 'array') return Array.isArray(value)
	if (expected === 'object')
		return value !== null && typeof value === 'object' && !Array.isArray(value)
	if (expected === 'integer') return typeof value === 'number' && Number.isSafeInteger(value)
	if (expected === 'number') return typeof value === 'number' && Number.isFinite(value)
	return typeof value === expected
}

function sameJson(left: unknown, right: unknown): boolean {
	return JSON.stringify(left) === JSON.stringify(right)
}

function inspect(schema: JsonSchema, value: unknown, path: string, issues: string[]): void {
	const types = Array.isArray(schema.type)
		? schema.type
		: schema.type === undefined
			? []
			: [schema.type]
	if (types.length > 0 && !types.some((type) => typeMatches(value, type))) {
		issues.push(`${path} must be ${types.join(' or ')}`)
		return
	}
	if (schema.enum && !schema.enum.some((candidate) => sameJson(candidate, value))) {
		issues.push(`${path} must be one of ${schema.enum.map(String).join(', ')}`)
		return
	}

	if (Array.isArray(value) && schema.items) {
		value.forEach((entry, index) => {
			inspect(schema.items as JsonSchema, entry, `${path}[${index}]`, issues)
		})
		return
	}
	if (value === null || typeof value !== 'object' || Array.isArray(value)) return

	const object = value as Record<string, unknown>
	for (const required of schema.required ?? []) {
		if (!(required in object)) issues.push(`${path}.${required} is required`)
	}
	for (const [key, entry] of Object.entries(object)) {
		const child = schema.properties?.[key]
		if (!child) {
			issues.push(`${path}.${key} is not declared by the discovered schema`)
			continue
		}
		inspect(child, entry, `${path}.${key}`, issues)
	}
}

/** Validate the schema vocabulary emitted by FluentCart 1.5.5 without rewriting or guessing it. */
export function validateAbilityInput(schema: JsonSchema, value: unknown): string[] {
	const issues: string[] = []
	inspect(schema, value, 'input', issues)
	return issues
}
