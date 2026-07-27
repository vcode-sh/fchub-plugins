// Per-profile capability matrix, asserted on exact public tool names.
//
// Counts alone would let a tool be swapped for another of equal weight unnoticed, so public
// surfaces are pinned by name and registry totals are asserted by derivation: "disabled exposes
// exactly the read-risk tools" stays meaningful as the registry grows, where a hardcoded 139 only
// records what one afternoon looked like. The other half matters as much: an unsupported tool must
// be ABSENT, never a callable failure trap that teaches an agent the store is merely broken.

import assert from 'node:assert/strict'
import { dirname, join, resolve } from 'node:path'
import { before, describe, it } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { loadServerModule, measureMode } from '../../scripts/measure-tool-context.mjs'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')

/** Dynamic always registers the same five meta-tools; the policy bounds what they can reach. */
const DYNAMIC_NAMES =
	'search_tools describe_tools execute_read_tool execute_reversible_write execute_guarded_write'
		.split(' ')
		.map((suffix) => `fluentcart_${suffix}`)

const CODE_NAMES = ['fluentcart_search_api', 'fluentcart_execute_code']

const CURATED_NAMES = (
	'app_init dashboard_overview order_list product_list customer_list subscription_list ' +
	'coupon_list product_search_by_name order_get product_get customer_get subscription_get ' +
	'coupon_get order_transactions report_overview report_revenue report_top_products_sold ' +
	'report_sales_growth coupon_create coupon_update'
)
	.split(' ')
	.map((suffix) => `fluentcart_${suffix}`)

/** Risk classes this server never executes, whatever the operator configures. */
const RISKS =
	'destructive-write external-side-effect control-plane infrastructure credential-bearing'
const NEVER_EXECUTABLE_RISKS = RISKS.split(' ')

/** Named definitions that exist in the registry and must never reach a caller. */
const WITHHELD_BY_NAME = (
	'order_delete order_bulk_action role_create role_update settings_save_payment_method ' +
	'integration_save_global_settings file_upload order_accept_dispute'
)
	.split(' ')
	.map((suffix) => `fluentcart_${suffix}`)

// Real-money actions that appear only in guarded mode, and only with both guard prerequisites:
// without durable state and a signing secret there is no way to stop a replayed refund, so the
// tool must be absent rather than present and hopeful.
const GUARDED_ONLY = ['fluentcart_order_refund', 'fluentcart_subscription_cancel']

const WRITE_MODES = ['disabled', 'reversible', 'guarded']
const snapshots = new Map()
let registry = []
let guardedWithoutPrerequisites = []

function distImport(...segments) {
	return import(pathToFileURL(join(PACKAGE_ROOT, 'dist', ...segments)).href)
}

// Wire names for one toolset mode, from a real `tools/list` on the built server.
async function wireNames(serverModule, mode) {
	const measurement = await measureMode(mode, { serverModule })
	assert.ok(measurement.available, `${mode} must be constructible: ${measurement.reason ?? ''}`)
	return measurement.tools.map((tool) => tool.name)
}

// Expected exposure, derived from the risk rows of the widest registry the server itself builds —
// not from a bare createAllTools(), which lacks the guard and capability dependencies that bring
// the store-context tool into existence. Comparing against that would compare two different things.
const widestWithRisk = (...risks) =>
	snapshots
		.get('guarded')
		.safety.filter((t) => risks.includes(t.risk))
		.map((t) => t.name)

before(async () => {
	const serverModule = await loadServerModule()
	const { createAllTools } = await distImport('tools', 'index.js')
	const { createClient } = await distImport('api', 'client.js')
	const { resolveApiUrls } = await distImport('config', 'types.js')
	const config = resolveApiUrls({ url: 'https://fixture.invalid', username: 'f', appPassword: 'f' })
	registry = createAllTools(createClient(config))

	for (const writeMode of WRITE_MODES) {
		process.env.FLUENTCART_WRITE_MODE = writeMode
		const context = serverModule.resolveServerContext()
		snapshots.set(writeMode, {
			safety: context.tools.map((tool) => ({ name: tool.name, ...tool.safety })),
			exposed: context.tools.map((tool) => tool.name),
			dynamic: await wireNames(serverModule, 'dynamic'),
			curated: await wireNames(serverModule, 'curated'),
			code: await wireNames(serverModule, 'code'),
			full: await wireNames(serverModule, 'full'),
		})
	}

	// Guarded mode with the guard taken away: the same request, none of the means to honour it.
	const guard = ['FLUENTCART_GUARD_SECRET', 'FLUENTCART_GUARD_STATE_DIR']
	const saved = guard.map((key) => process.env[key])
	process.env.FLUENTCART_WRITE_MODE = 'guarded'
	for (const key of guard) Reflect.deleteProperty(process.env, key)
	guardedWithoutPrerequisites = serverModule.resolveServerContext().tools.map((tool) => tool.name)
	for (const [index, key] of guard.entries()) process.env[key] = saved[index]
})

describe('source registry', () => {
	it('carries a unique, prefixed name and a reviewed risk row for every definition', (t) => {
		const names = registry.map((tool) => tool.name)
		t.diagnostic(`source registry holds ${names.length} definitions`)
		assert.equal(new Set(names).size, names.length, 'a tool name may appear only once')
		for (const { name, safety } of registry) {
			assert.match(name, /^fluentcart_[a-z0-9_]+$/)
			assert.ok(safety?.risk && safety?.execution, `${name} has no risk row`)
		}
	})

	it('classifies every definition into the reviewed risk taxonomy', (t) => {
		const known = ['read', 'reversible-write', 'real-money', ...NEVER_EXECUTABLE_RISKS]
		const tally = {}
		for (const tool of registry) tally[tool.safety.risk] = (tally[tool.safety.risk] ?? 0) + 1
		t.diagnostic(JSON.stringify(tally))
		for (const risk of Object.keys(tally)) assert.ok(known.includes(risk), `unreviewed: ${risk}`)
	})
})

describe('exposure by write mode', () => {
	it('exposes exactly the read-risk definitions when writes are disabled', (t) => {
		const exposed = snapshots.get('disabled').exposed
		t.diagnostic(`disabled exposes ${exposed.length} definitions`)
		assert.deepEqual([...exposed].sort(), widestWithRisk('read').sort())
	})

	it('adds exactly the reversible writes when the operator opts in', (t) => {
		const exposed = snapshots.get('reversible').exposed
		t.diagnostic(`reversible exposes ${exposed.length} definitions`)
		assert.deepEqual([...exposed].sort(), widestWithRisk('read', 'reversible-write').sort())
	})

	it('adds nothing in guarded mode, because 2.0.0 ships guarded writes unavailable', (t) => {
		// The guard is built and unit-tested but was never acceptance-proven: no run-owned
		// refundable order can be created and then removed on FluentCart 1.5.5. Guarded mode is
		// therefore identical to reversible, and this asserts that equality rather than a
		// guarded-only addition. Restore the previous expectation when the lanes can run.
		const guardable = snapshots
			.get('guarded')
			.safety.filter((tool) => tool.risk === 'real-money' && tool.execution === 'guarded-rest')
			.map((tool) => tool.name)
		t.diagnostic(`guard-wired real-money actions: ${guardable.join(', ') || 'none'}`)
		assert.deepEqual(guardable, [], 'no tool may be guard-wired in this release')
		assert.deepEqual(
			[...snapshots.get('guarded').exposed].sort(),
			[...widestWithRisk('read', 'reversible-write')].sort(),
		)
	})

	it('withholds them again the moment a guard prerequisite is missing', () => {
		// Asking for guarded mode is not the same as being able to honour it.
		assert.deepEqual(
			[...guardedWithoutPrerequisites].sort(),
			[...snapshots.get('reversible').exposed].sort(),
		)
		for (const name of GUARDED_ONLY) assert.ok(!guardedWithoutPrerequisites.includes(name))
	})

	it('never exposes a real-money action that has no guarded route', () => {
		const unroutable = registry
			.filter((t) => t.safety.risk === 'real-money' && t.safety.execution !== 'guarded-rest')
			.map((tool) => tool.name)
		assert.ok(unroutable.length > 0, 'the taxonomy must contain unroutable real-money actions')
		for (const writeMode of WRITE_MODES) {
			const exposed = snapshots.get(writeMode).exposed
			for (const name of unroutable) assert.ok(!exposed.includes(name), `${name} in ${writeMode}`)
		}
	})

	it('defaults to the narrowest profile', async () => {
		const serverModule = await loadServerModule()
		process.env.FLUENTCART_WRITE_MODE = ''
		assert.equal(serverModule.resolveWritePolicy().writeMode, 'disabled')
		const names = serverModule.resolveServerContext().tools.map((tool) => tool.name)
		assert.deepEqual(names.sort(), widestWithRisk('read').sort())
	})

	it('never exposes a never-executable risk class in any mode', () => {
		const forbidden = new Set(
			registry
				.filter((tool) => NEVER_EXECUTABLE_RISKS.includes(tool.safety.risk))
				.map((tool) => tool.name),
		)
		assert.ok(forbidden.size > 0, 'the taxonomy must actually contain refused classes')
		for (const writeMode of WRITE_MODES) {
			for (const name of snapshots.get(writeMode).exposed) {
				assert.ok(!forbidden.has(name), `${name} is exposed in ${writeMode} mode`)
			}
		}
	})
})

describe('public names per mode', () => {
	for (const writeMode of WRITE_MODES) {
		it(`registers the same meta-tool names in ${writeMode} mode`, () => {
			assert.deepEqual(snapshots.get(writeMode).dynamic, DYNAMIC_NAMES)
			assert.deepEqual(snapshots.get(writeMode).code, CODE_NAMES)
		})

		it(`lists exactly the filtered registry in full mode, ${writeMode}`, () => {
			const snapshot = snapshots.get(writeMode)
			assert.deepEqual([...snapshot.full].sort(), [...snapshot.exposed].sort())
		})

		it(`draws every curated name from the filtered registry in ${writeMode} mode`, () => {
			const snapshot = snapshots.get(writeMode)
			const exposed = new Set(snapshot.exposed)
			for (const name of snapshot.curated) {
				assert.ok(exposed.has(name), `curated ${name} is not in the filtered registry`)
			}
			assert.equal(new Set(snapshot.curated).size, snapshot.curated.length)
		})
	}

	it('selects the exact curated list once reversible writes are available', () => {
		assert.deepEqual(snapshots.get('reversible').curated, CURATED_NAMES)
		assert.deepEqual(snapshots.get('guarded').curated, CURATED_NAMES)
	})

	it('drops only the write members from the curated list when writes are disabled', () => {
		const writes = CURATED_NAMES.slice(-2)
		assert.deepEqual(
			snapshots.get('disabled').curated,
			CURATED_NAMES.filter((name) => !writes.includes(name)),
		)
	})
})

describe('unsupported tools are absent, never callable failure traps', () => {
	const everyName = (writeMode) => {
		const s = snapshots.get(writeMode)
		return new Set([...s.exposed, ...s.dynamic, ...s.curated, ...s.code, ...s.full])
	}

	it('proves the withheld names are real, so absence is a decision and not a typo', () => {
		const source = new Set(registry.map((tool) => tool.name))
		for (const name of WITHHELD_BY_NAME) {
			assert.ok(source.has(name), `${name} is not a real tool; fix the test, not the registry`)
		}
	})

	for (const writeMode of WRITE_MODES) {
		it(`hides every withheld name in ${writeMode} mode`, () => {
			const names = everyName(writeMode)
			for (const name of WITHHELD_BY_NAME) {
				assert.ok(!names.has(name), `${name} must be absent in ${writeMode} mode`)
			}
		})
	}

	it('withholds refund and cancellation from every write mode in this release', () => {
		// Shipped unavailable, so absence is asserted in all three modes including a fully
		// configured guard. A money-moving action that could not be acceptance-proven does not
		// get to be present merely because the operator supplied a secret and a state directory.
		for (const name of GUARDED_ONLY) {
			assert.ok(!everyName('disabled').has(name), `${name} must be absent when writes are off`)
			assert.ok(!everyName('reversible').has(name), `${name} is not a reversible write`)
			assert.ok(!everyName('guarded').has(name), `${name} must stay absent in 2.0.0`)
			assert.ok(!snapshots.get('guarded').curated.includes(name), `${name} is not a curated tool`)
		}
	})

	it('withholds a substantial part of the registry under the widest profile', (t) => {
		const exposed = new Set(snapshots.get('guarded').exposed)
		const withheld = registry.map((tool) => tool.name).filter((name) => !exposed.has(name))
		t.diagnostic(`${withheld.length} of ${registry.length} definitions are withheld`)
		assert.ok(withheld.length > 50, 'the policy must actually be withholding something')
		for (const name of WITHHELD_BY_NAME) assert.ok(withheld.includes(name))
	})

	it('adds nothing beyond the plain registry that is not a read', (t) => {
		// The server builds its registry with guard and capability dependencies a bare
		// createAllTools() call lacks, so a few tools exist only in the former. Which ones is a
		// moving target; that every one of them is a read is the part that must never move.
		const plain = new Set(registry.map((tool) => tool.name))
		const extra = snapshots.get('guarded').safety.filter((tool) => !plain.has(tool.name))
		t.diagnostic(`dependency-provided: ${extra.map((tool) => tool.name).join(', ')}`)
		for (const tool of extra) assert.equal(tool.risk, 'read', `${tool.name} is not a read`)
	})
})
