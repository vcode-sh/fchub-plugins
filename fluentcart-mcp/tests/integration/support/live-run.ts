export interface LiveRun {
	/** The unique run identifier minted by scripts/run-live-tests.mjs. */
	id: string
	/** Short prefix stamped into every created title, email, SKU, code and note. */
	prefix: string
	target: URL
}

let cached: LiveRun | null = null

/**
 * Returns this run's identity, or throws if the suite was not started by the approved launcher.
 *
 * The prefix keeps a stray record identifiable after the fact; it is emphatically not a
 * deletion key. Removal always uses the exact id recorded at creation time.
 */
export function getLiveRun(): LiveRun {
	if (cached) return cached

	const id = process.env.FLUENTCART_TEST_RUN_ID
	if (!id) {
		throw new Error(
			'FLUENTCART_TEST_RUN_ID is unset. Live tests must be started through scripts/run-live-tests.mjs.',
		)
	}

	const url = process.env.FLUENTCART_URL
	if (!url) {
		throw new Error(
			'FLUENTCART_URL is unset. Live tests must be started through scripts/run-live-tests.mjs.',
		)
	}

	// A compact, collision-free, human-recognisable stamp: mcp + the run UUID's first block.
	const shortId = id.slice(-12)
	cached = {
		id,
		prefix: `mcp-${shortId}`,
		target: new URL(url),
	}
	return cached
}

/** Test-only reset so unit tests can exercise the accessor without leaking state. */
export function resetLiveRunCache(): void {
	cached = null
}
