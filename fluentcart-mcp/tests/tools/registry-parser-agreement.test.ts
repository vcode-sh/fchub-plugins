// The coverage ledger's text parser must agree with the runtime registry.
//
// `scripts/build-api-coverage.mjs` does not import the registry; it reads risk-registry.ts as
// source and matches `...rows(UPPERCASE_NAME, { … })`. That keeps the ledger honest about what the
// source says, but it means a refactor can silently break it: rewriting a row as
// `rows(REVERSIBLE_WRITES.filter(isCreate), …)` matched nothing, so every reversible write fell
// through to `unreviewed-write` / `execution: 'none'` and the generated ledger declared 28 working
// tools unreachable. The runtime was unaffected, which is exactly what makes it easy to miss.
//
// So the two views are compared directly: for every tool the server registers, the parser must
// resolve the same risk, idempotency and execution the runtime resolved.
import { describe, expect, it } from 'vitest'
// @ts-expect-error — plain JS build script, no type declarations.
import { extractRiskRegistry } from '../../scripts/build-api-coverage.mjs'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

interface ParsedSafety {
	risk?: string
	idempotency?: string
	execution?: string
}

const parsed = extractRiskRegistry() as Map<string, ParsedSafety>
const client = { get: async () => ({ data: {} }) } as unknown as FluentCartClient
const tools = createAllTools(client, {})

describe('ledger parser agrees with the runtime registry', () => {
	it('parsed a non-trivial number of rows', () => {
		// A regex that silently matches nothing would otherwise make every assertion below vacuous.
		expect(parsed.size).toBeGreaterThan(100)
	})

	it('resolves every registered write identically', () => {
		const mismatches: string[] = []

		for (const tool of tools) {
			// Reads default identically on both sides when unlisted, so only writes are compared.
			if (tool.safety.risk === 'read') continue

			const row = parsed.get(tool.name)
			if (!row) {
				mismatches.push(`${tool.name}: absent from the parsed registry`)
				continue
			}
			if (
				row.risk !== tool.safety.risk ||
				row.idempotency !== tool.safety.idempotency ||
				row.execution !== tool.safety.execution
			) {
				mismatches.push(
					`${tool.name}: parser ${row.risk}/${row.idempotency}/${row.execution} vs runtime ${tool.safety.risk}/${tool.safety.idempotency}/${tool.safety.execution}`,
				)
			}
		}

		expect(mismatches, mismatches.join('\n')).toEqual([])
	})

	it('sees every exposed write as executable', () => {
		// The failure mode that produced a wrong ledger: exposed tools parsed as execution 'none'.
		for (const tool of tools) {
			if (tool.safety.execution === 'none') continue
			expect(
				parsed.get(tool.name)?.execution,
				`${tool.name} is executable at runtime but the ledger parser cannot see it`,
			).not.toBe('none')
		}
	})
})
