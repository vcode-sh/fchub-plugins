// Run-owned dependencies for the plan 06 Task 3 reversible-write lane.
//
// Deliberately narrow. It creates saved views and nothing else, because saved views are the only
// candidate that passed the Task 3 checkpoint: they have a per-route policy, a stable id, a
// read-after-write route and a real DELETE. There is no generic delete helper here — removal is
// only ever reachable through the id this run recorded at creation time, so nothing in the lane
// can reach a record it did not make.
import type { CleanupLedger, OwnedId } from './cleanup-ledger.js'
import { getLiveClient } from './live-client.js'
import { getLiveRun } from './live-run.js'

/** Object types the saved-views policy maps to a permission; the lane uses one of them. */
export type SavedViewObjectType = 'order_table' | 'product_table'

export interface OwnedSavedView {
	id: number
	name: string
	objectType: SavedViewObjectType
}

function readViews(payload: unknown): Record<string, unknown>[] {
	const record = (payload ?? {}) as Record<string, unknown>
	return Array.isArray(record.views)
		? record.views.filter((row): row is Record<string, unknown> => typeof row === 'object')
		: []
}

/**
 * Read the views for one object type.
 *
 * `object_type` is mandatory: the store answers 403 without it, which reads like a permission
 * failure and is really a missing argument. Every call here passes it explicitly so a genuine
 * permission problem stays distinguishable from a malformed request.
 */
export async function listSavedViews(
	objectType: SavedViewObjectType,
): Promise<Record<string, unknown>[]> {
	const response = await getLiveClient().get('/saved-views', { object_type: objectType })
	return readViews(response.data)
}

export async function savedViewMissing(
	id: OwnedId,
	objectType: SavedViewObjectType,
): Promise<boolean> {
	const views = await listSavedViews(objectType)
	return !views.some((view) => Number(view.id) === Number(id))
}

/**
 * Create a saved view this run owns, and register it for removal before anything else can fail.
 *
 * The id is tracked the moment the store returns it. If the read-back below throws, the record is
 * already in the ledger and still gets cleaned up — registering after verification would leak
 * exactly the records whose creation went strangely.
 */
export async function createOwnedSavedView(
	ledger: CleanupLedger,
	objectType: SavedViewObjectType,
): Promise<OwnedSavedView> {
	const run = getLiveRun()
	const name = `${run.prefix} reversible view`

	const created = await getLiveClient().post('/saved-views', {
		object_type: objectType,
		name,
		description: 'Run-owned fixture for the reversible-write lane',
	})

	const payload = (created.data ?? {}) as Record<string, unknown>
	const view = (payload.view ?? payload.data ?? {}) as Record<string, unknown>
	let id = Number(view.id)

	if (!Number.isInteger(id) || id <= 0) {
		// Some builds answer with a bare success message. Recover the id by name rather than
		// leaving an untracked record behind.
		const match = (await listSavedViews(objectType)).find((row) => row.name === name)
		id = Number(match?.id)
	}

	if (!Number.isInteger(id) || id <= 0) {
		throw new Error(`saved view create returned no usable id: ${JSON.stringify(created.data)}`)
	}

	ledger.track({
		type: 'saved-view',
		id,
		remove: async (target) => {
			await getLiveClient().delete(`/saved-views/${target}`)
		},
		verifyMissing: (target) => savedViewMissing(target, objectType),
	})

	return { id, name, objectType }
}
