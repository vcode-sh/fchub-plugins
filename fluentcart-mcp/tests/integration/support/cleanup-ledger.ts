export type OwnedId = string | number

export interface CleanupRegistration {
	/** Resource kind, used only for human-readable failure reporting. */
	type: string
	/** The exact identifier returned by the create call for this run. */
	id: OwnedId
	remove: (id: OwnedId) => Promise<void>
	/**
	 * Independent confirmation that the record is gone. Must resolve `false` — not throw — when
	 * the record is still present, and must throw when it cannot tell the difference between
	 * "missing" and an authentication or network failure.
	 */
	verifyMissing: (id: OwnedId) => Promise<boolean>
}

/**
 * Tracks records this run created so they can be removed and independently proven gone.
 *
 * Deliberately narrow: no prefix queries, no bulk deletes, no "delete everything matching".
 * A record is removable only because this run recorded the exact id the API returned.
 */
export class CleanupLedger {
	#registrations: CleanupRegistration[] = []

	track(registration: CleanupRegistration): void {
		const { id } = registration
		if (id === undefined || id === null || id === '') {
			throw new Error(
				`CleanupLedger.track requires an exact id for ${registration.type}; received ${String(id)}`,
			)
		}
		this.#registrations.push(registration)
	}

	get size(): number {
		return this.#registrations.length
	}

	/**
	 * Removes every tracked record, newest first so children go before their parents.
	 *
	 * Every registration is attempted even after an earlier failure, then all failures are
	 * reported together. Cleanup failure is a suite failure; it is never swallowed.
	 */
	async cleanup(): Promise<void> {
		const pending = this.#registrations.slice().reverse()
		this.#registrations = []
		const failures: string[] = []

		for (const registration of pending) {
			const label = `${registration.type} ${String(registration.id)}`
			try {
				await registration.remove(registration.id)
			} catch (error) {
				failures.push(`${label}: delete failed (${describe(error)})`)
				continue
			}

			let missing: boolean
			try {
				missing = await registration.verifyMissing(registration.id)
			} catch (error) {
				failures.push(`${label}: could not verify removal (${describe(error)})`)
				continue
			}

			if (!missing) {
				failures.push(`${label}: still present after delete`)
			}
		}

		if (failures.length > 0) {
			throw new Error(
				`cleanup incomplete — ${failures.length} record(s):\n  ${failures.join('\n  ')}`,
			)
		}
	}
}

function describe(error: unknown): string {
	return error instanceof Error ? error.message : String(error)
}
