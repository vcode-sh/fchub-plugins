import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const toolsDir = resolve(packageRoot, 'src/tools')
const fixture = JSON.parse(
	readFileSync(
		resolve(packageRoot, 'tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json'),
		'utf8',
	),
)

const CURRENT_OPERATIONS = new Set(fixture.operations.map((o) => `${o.method} ${o.path}`))

/**
 * Routes that exist only on FluentCart 1.3.9.
 *
 * Declared fallbacks, not mistakes: a tool lists the retired route after the current one so a
 * store still serving it keeps working. They are absent from the current fixture by design.
 */
const LEGACY_ONLY = new Set([
	'POST /email-notification/get-template',
	'POST /options/attr/group/{param}/term',
	'POST /options/attr/group/{param}/term/{param}/serial',
])

/** Modules owned by other workstreams; their tools are reported, never asserted. */
const NOT_YET_MIGRATED = new Set(['orders-refunds.ts', 'subscriptions-cancellation.ts'])

function canonical(path) {
	const withParams = path
		.replace(/:[A-Za-z_][A-Za-z0-9_]*/g, '{param}')
		.replace(/\{[^}]*\}/g, '{param}')
		.replace(/\/{2,}/g, '/')
	const trimmed = withParams.length > 1 ? withParams.replace(/\/$/, '') : withParams
	return trimmed.startsWith('/') ? trimmed : `/${trimmed}`
}

/**
 * Isolate each tool definition by brace depth so a block is never cut mid-literal.
 *
 * biome-ignore lint/complexity/noExcessiveCognitiveComplexity: a brace-depth scanner is
 * irreducibly branchy — it must track string, template and comment state to avoid cutting a
 * block inside a literal. Splitting it into helpers would scatter that state and make the
 * parser harder to verify, not easier.
 */
function toolBlocks(source) {
	const blocks = []
	const opener = /\b(get|post|put|delete|create)Tool\(client, \{/g
	let match = opener.exec(source)

	while (match) {
		let depth = 0
		let i = match.index + match[0].length - 1
		for (; i < source.length; i += 1) {
			const ch = source[i]
			if (ch === '{') depth += 1
			else if (ch === '}') {
				depth -= 1
				if (depth === 0) break
			} else if (ch === "'" || ch === '"' || ch === '`') {
				const quote = ch
				i += 1
				while (i < source.length && source[i] !== quote) {
					if (source[i] === '\\') i += 1
					i += 1
				}
			}
		}
		const body = source.slice(match.index, i + 1)
		const name = /name: '([^']+)'/.exec(body)?.[1]
		blocks.push({ factory: match[1], name, body })
		opener.lastIndex = i + 1
		match = opener.exec(source)
	}

	return blocks
}

const modules = readdirSync(toolsDir)
	.filter((f) => f.endsWith('.ts') && !f.startsWith('_'))
	.map((file) => ({ file, source: readFileSync(resolve(toolsDir, file), 'utf8') }))
	.map(({ file, source }) => ({ file, blocks: toolBlocks(source) }))
	.filter(({ blocks }) => blocks.length > 0)

const allTools = modules.flatMap(({ file, blocks }) => blocks.map((block) => ({ ...block, file })))
const auditable = allTools.filter((tool) => !NOT_YET_MIGRATED.has(tool.file))

describe('source route migration', () => {
	it('finds the tool registry in source', () => {
		assert.ok(allTools.length > 200, `expected the full registry, found ${allTools.length}`)
	})

	it('names every tool definition', () => {
		const unnamed = allTools.filter((tool) => !tool.name).map((tool) => tool.file)
		assert.deepEqual(unnamed, [], 'every tool must declare a public name')
	})

	it('leaves no custom tool without route metadata', () => {
		// An endpoint-factory tool derives its metadata from the `endpoint` it already declares,
		// so it cannot drift. A hand-written handler has to say where it goes: nothing else can
		// know, and an undeclared route is exactly the implicit routing this migration removes.
		const undeclared = auditable
			.filter((tool) => tool.factory === 'create' && !/\broutes:/.test(tool.body))
			.map((tool) => `${tool.name} (${tool.file})`)
			.sort()

		assert.deepEqual(
			undeclared,
			[],
			`custom tools missing ToolRouteMetadata: ${undeclared.join(', ')}`,
		)
	})

	it('leaves no endpoint tool without an endpoint', () => {
		const undeclared = auditable
			.filter((tool) => tool.factory !== 'create' && !/\bendpoint:/.test(tool.body))
			.map((tool) => `${tool.name} (${tool.file})`)
			.sort()

		assert.deepEqual(undeclared, [], `endpoint tools missing a route: ${undeclared.join(', ')}`)
	})

	it('declares a composite tool for every multi-target handler', () => {
		// A handler that can reach more than one route is composite by definition; declaring it
		// `direct` would hide the extra routes from the contract.
		//
		// Distinct targets, not call sites. Two calls to the same resolved path — the usual shape
		// of a branch that formats a legacy body one way and a current body another — reach one
		// route between them, and counting them as two would force a false `composite`.
		const misdeclared = []
		for (const tool of auditable) {
			if (tool.factory !== 'create') continue

			const targets = new Set(
				[...tool.body.matchAll(/\b\w{1,8}\.(?:get|post|put|delete|request)\(\s*([^,)]+)/g)].map(
					(match) => match[1].trim(),
				),
			)
			const declaredComposite = /routes: composite\(/.test(tool.body)

			if (targets.size > 1 && !declaredComposite) {
				misdeclared.push(`${tool.name} (${targets.size} targets, ${tool.file})`)
			}
		}

		assert.deepEqual(misdeclared, [], `multi-target tools not declared composite: ${misdeclared}`)
	})

	it('declares only routes the current store serves, or a documented legacy fallback', () => {
		const unknown = []
		for (const { file, blocks } of modules) {
			if (NOT_YET_MIGRATED.has(file)) continue
			for (const block of blocks) {
				for (const [, method, path] of block.body.matchAll(
					/(?:direct|composite|op)\('(GET|POST|PUT|PATCH|DELETE)', '([^']+)'/g,
				)) {
					const key = `${method} ${canonical(path)}`
					if (!(CURRENT_OPERATIONS.has(key) || LEGACY_ONLY.has(key))) {
						unknown.push(`${block.name}: ${key} (${file})`)
					}
				}
			}
		}

		assert.deepEqual(unknown, [], `routes absent from the captured registry: ${unknown.join(', ')}`)
	})

	it('leaves nothing in the registry without metadata', () => {
		const pending = allTools
			.filter((tool) => tool.factory === 'create' && !/\broutes:/.test(tool.body))
			.map((tool) => `${tool.name} (${tool.file})`)
			.sort()

		// The migration is complete, so this is now a ratchet rather than a running total: any
		// new custom tool that ships without route metadata fails here by name.
		assert.deepEqual(pending, [], `custom tools still pending: ${pending.join(', ')}`)
	})
})
