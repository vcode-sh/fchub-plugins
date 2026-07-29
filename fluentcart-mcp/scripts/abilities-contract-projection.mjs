import { createHash } from 'node:crypto'

export function canonical(value) {
	if (Array.isArray(value)) return value.map(canonical)
	if (value && typeof value === 'object') {
		return Object.fromEntries(
			Object.keys(value)
				.sort()
				.map((key) => [key, canonical(value[key])]),
		)
	}
	return value
}

export function digest(value) {
	return `sha256:${createHash('sha256').update(JSON.stringify(canonical(value))).digest('hex')}`
}

export function methodsFrom(options) {
	const found = new Set()
	for (const value of [
		...(Array.isArray(options?.methods) ? options.methods : []),
		...(Array.isArray(options?.endpoints)
			? options.endpoints.flatMap((endpoint) =>
					Array.isArray(endpoint?.methods) ? endpoint.methods : [],
				)
			: []),
	]) {
		if (typeof value === 'string') found.add(value.toUpperCase())
	}
	return [...found].sort()
}

export function projectAbility(ability, restMethods) {
	if (
		!ability ||
		typeof ability !== 'object' ||
		typeof ability.name !== 'string' ||
		!ability.name.startsWith('fluent-cart/')
	) {
		throw new Error('ability list contains an invalid or foreign row')
	}
	const annotations = ability.meta?.annotations ?? {}
	return {
		name: ability.name,
		label: typeof ability.label === 'string' ? ability.label : '',
		description: typeof ability.description === 'string' ? ability.description : '',
		category: ability.category,
		inputSchema: ability.input_schema ?? null,
		outputSchema: ability.output_schema ?? null,
		annotations: {
			abilitiesReadonly: annotations.readonly ?? null,
			abilitiesDestructive: annotations.destructive ?? null,
			abilitiesIdempotent: annotations.idempotent ?? null,
			mcpReadOnlyHint: annotations.readOnlyHint ?? null,
			mcpDestructiveHint: annotations.destructiveHint ?? null,
			mcpIdempotentHint: annotations.idempotentHint ?? null,
			mcpOpenWorldHint: annotations.openWorldHint ?? null,
		},
		rest: {
			discoveryPath: `/wp-abilities/v1/abilities/${ability.name}`,
			runPath: `/wp-abilities/v1/abilities/${ability.name}/run`,
			methods: restMethods,
		},
	}
}

export function fingerprintAbilityRow(ability) {
	return digest({
		name: ability.name,
		category: ability.category,
		inputSchema: ability.inputSchema,
		outputSchema: ability.outputSchema,
		annotations: ability.annotations,
		rest: {
			runPath: ability.rest.runPath,
			methods: [...new Set(ability.rest.methods)].sort(),
		},
	})
}
