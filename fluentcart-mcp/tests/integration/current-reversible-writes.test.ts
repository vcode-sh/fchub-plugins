// Live lifecycle lane for plan 06 Task 3. Reachable only through scripts/run-live-tests.mjs.
//
// One accepted operation: creating a saved table view. The lane proves the full round trip —
// prior state, run-owned write, independent read-back, removal, and an independent read-back
// confirming removal — because a create that cannot be proven gone is not a reversible write, it
// is a leak with good intentions.
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { savedViewTools } from '../../src/tools/saved-views.js'
import { taxConfigurationTools } from '../../src/tools/tax-configuration.js'
import { CleanupLedger } from './support/cleanup-ledger.js'
import {
	createOwnedSavedView,
	listSavedViews,
	savedViewMissing,
} from './support/current-write-fixture.js'
import { getLiveClient } from './support/live-client.js'
import { getLiveRun } from './support/live-run.js'

const run = getLiveRun()
const ledger = new CleanupLedger()
const OBJECT_TYPE = 'order_table' as const

let priorNames: string[] = []
let created: { id: number; name: string }

beforeAll(async () => {
	// Prior state, so the lane can prove afterwards that it disturbed nothing else.
	priorNames = (await listSavedViews(OBJECT_TYPE)).map((view) => String(view.name))
	created = await createOwnedSavedView(ledger, OBJECT_TYPE)
})

afterAll(async () => {
	await ledger.cleanup()
})

describe('saved view create is reversible', () => {
	it('registers the created id for removal immediately', () => {
		expect(ledger.size).toBe(1)
		expect(Number.isInteger(created.id)).toBe(true)
	})

	it('is visible on an independent read-after-write', async () => {
		const views = await listSavedViews(OBJECT_TYPE)
		const match = views.find((view) => Number(view.id) === created.id)

		expect(match, 'the created view is absent from a fresh read').toBeTruthy()
		expect(String(match?.name)).toBe(created.name)
	})

	it('carries the run prefix, so a stray record is identifiable', () => {
		expect(created.name.startsWith(run.prefix)).toBe(true)
	})

	it('leaves every pre-existing view untouched', async () => {
		const names = (await listSavedViews(OBJECT_TYPE)).map((view) => String(view.name))
		for (const prior of priorNames) expect(names).toContain(prior)
		expect(names).toHaveLength(priorNames.length + 1)
	})
})

describe('the tool reports what the store stored', () => {
	it('re-reads the view rather than echoing the request', async () => {
		const tool = savedViewTools(getLiveClient()).find(
			(candidate) => candidate.name === 'fluentcart_saved_view_list',
		)
		const result = await tool?.handler({ object_type: OBJECT_TYPE })
		const payload = JSON.parse(result?.content[0]?.text ?? '{}')

		expect(result?.isError).toBeFalsy()
		expect(payload.views.some((view: { id: number }) => view.id === created.id)).toBe(true)
	})

	it('never returns the stored query blob', async () => {
		const tool = savedViewTools(getLiveClient()).find(
			(candidate) => candidate.name === 'fluentcart_saved_view_list',
		)
		const result = await tool?.handler({ object_type: OBJECT_TYPE })
		const payload = JSON.parse(result?.content[0]?.text ?? '{}')

		for (const view of payload.views) {
			expect(Object.keys(view).sort()).toEqual([
				'description',
				'id',
				'isPublic',
				'name',
				'objectType',
			])
		}
	})

	it('rejects an object type the policy cannot map, before calling the store', async () => {
		const tool = savedViewTools(getLiveClient()).find(
			(candidate) => candidate.name === 'fluentcart_saved_view_list',
		)
		const parsed = tool?.schema.safeParse({ object_type: 'invoices' })

		expect(parsed?.success).toBe(false)
	})
})

describe('removal is provable', () => {
	it('removes the run-owned view and confirms it is gone', async () => {
		// Removal goes through the ledger rather than a direct delete here, so the lane exercises
		// the same path that cleans up after a failure. The ledger deletes, then independently
		// re-reads, and throws if the record survives.
		//
		// It must also be the only delete. FluentCart answers a delete for an id that no longer
		// exists with 403, not 404, so a belt-and-braces second delete reports a permission
		// failure for a record that was removed perfectly well.
		await ledger.cleanup()

		expect(ledger.size).toBe(0)
		expect(await savedViewMissing(created.id, OBJECT_TYPE)).toBe(true)
	})

	it('leaves the pre-existing views exactly as they were', async () => {
		const names = (await listSavedViews(OBJECT_TYPE)).map((view) => String(view.name))
		expect(names.sort()).toEqual([...priorNames].sort())
	})
})

/**
 * Tax settings are a wholesale replacement, so this lane treats them as one indivisible resource:
 * capture the entire blob, change a single cosmetic field, prove the change landed AND that
 * nothing else moved, then restore and prove the restoration byte-for-byte.
 *
 * The probe field is `tax_display_label` — a customer-facing string with no effect on what anyone
 * is charged. `enable_tax` is deliberately never touched: flipping whether a live store charges
 * tax is not a test, it is an outage.
 *
 * Restore runs in a `finally`. If an assertion throws mid-way, the store still goes back.
 */
describe('tax settings save is reversible', () => {
	const settingsTool = () =>
		taxConfigurationTools(getLiveClient()).find(
			(candidate) => candidate.name === 'fluentcart_tax_settings_save',
		)

	const readSettings = async (): Promise<Record<string, unknown>> => {
		const response = await getLiveClient().get('/tax/configuration/settings')
		return ((response.data ?? {}) as Record<string, unknown>).settings as Record<string, unknown>
	}

	const callTool = async (input: Record<string, unknown>) => {
		const result = await settingsTool()?.handler(input)
		return { isError: result?.isError === true, text: result?.content[0]?.text ?? '' }
	}

	it('changes one field, leaves the rest, and restores exactly', async () => {
		const original = await readSettings()
		expect(original, 'could not read tax settings to begin with').toBeTruthy()

		const originalLabel = String(original.tax_display_label)
		const probeLabel = `${run.prefix} probe`
		let restored = false

		try {
			const write = await callTool({ tax_display_label: probeLabel })
			expect(write.isError, `save failed: ${write.text}`).toBe(false)

			const afterWrite = await readSettings()
			expect(afterWrite.tax_display_label).toBe(probeLabel)

			// Every other field must be untouched. This is the assertion that would have caught a
			// partial payload: a merge-less write leaves this comparison in ruins.
			const { tax_display_label: _wrote, ...survivors } = afterWrite
			const { tax_display_label: _was, ...expected } = original
			expect(survivors).toEqual(expected)

			// enable_tax specifically, because it is the field with a customer-visible cost.
			expect(afterWrite.enable_tax).toBe(original.enable_tax)
		} finally {
			const undo = await callTool({ tax_display_label: originalLabel })
			restored = undo.isError === false
		}

		expect(restored, 'restore call failed').toBe(true)
		expect(await readSettings()).toEqual(original)
	})

	it('refuses an empty change set rather than writing defaults', async () => {
		const before = await readSettings()
		const result = await callTool({})

		expect(result.isError).toBe(true)
		expect(result.text).toMatch(/at least one setting/i)
		// The controller falls back to its defaults when `settings` is absent, and those defaults
		// disable tax. Proving the store is untouched is the point of this case.
		expect(await readSettings()).toEqual(before)
	})

	it('refuses an invalid enum locally, before the store can coerce it', async () => {
		const before = await readSettings()
		const parsed = settingsTool()?.schema.safeParse({ tax_inclusion: 'inclusive' })

		expect(parsed?.success).toBe(false)
		expect(await readSettings()).toEqual(before)
	})
})
