// Reading what a test run actually did, rather than what its exit code implies.
//
// Both runners exit 0 when tests are skipped. That is correct for them and catastrophic for us: a
// lane that skipped the only test proving a capability would report PASS, and a green PASS on a
// skipped test looks exactly like proof. So every lane that can produce a machine-readable report
// is judged on counts and names, and a skip is carried all the way into the evidence with the
// reason its author wrote down.

import { readFileSync } from 'node:fs'

/** A skip reason is written into the test name by convention: `... (BLOCKED: why)`. */
const BLOCKER_IN_NAME = /\(BLOCKED:\s*([^)]+)\)/i

const XML_ENTITIES = {
	'&amp;': '&',
	'&lt;': '<',
	'&gt;': '>',
	'&quot;': '"',
	'&apos;': "'",
	'&#10;': '\n',
}

function decode(text) {
	return text.replace(/&(?:amp|lt|gt|quot|apos|#10);/g, (entity) => XML_ENTITIES[entity] ?? entity)
}

/**
 * Pull the reason an author gave for a skip.
 *
 * The name is the only reliable carrier: node's JUnit reporter writes `message="true"` and
 * vitest writes a bare `<skipped/>`, so neither runner preserves a reason on its own.
 */
export function skipReason(name) {
	const matched = BLOCKER_IN_NAME.exec(name)
	if (matched) return matched[1].trim()
	return 'no reason recorded; name the blocker as "(BLOCKED: why)" in the test title'
}

/**
 * @param {string} xml JUnit produced by `node --test` or by vitest
 * @returns {{tests:number,passed:number,skipped:number,failed:number,skips:{name:string,reason:string}[]}}
 */
export function parseJunit(xml) {
	const cases = [...xml.matchAll(/<testcase\b([^>]*?)(?:\/>|>([\s\S]*?)<\/testcase>)/g)]
	const skips = []
	const names = []
	let passed = 0
	let failed = 0

	for (const [, attributes, body = ''] of cases) {
		const name = decode(/\bname="([^"]*)"/.exec(attributes)?.[1] ?? '<unnamed>')
		names.push(name)
		if (/<(?:failure|error)\b/.test(body)) {
			failed += 1
		} else if (/<skipped\b/.test(body)) {
			skips.push({ name, reason: skipReason(name) })
		} else {
			passed += 1
		}
	}

	return { tests: cases.length, passed, skipped: skips.length, failed, skips, names }
}

export function readJunit(path) {
	return parseJunit(readFileSync(path, 'utf8'))
}

/**
 * Statuses a step or lane can carry, worst first.
 *
 * `PASS_WITH_EXCLUSIONS` exists because the two honest answers to "did this lane pass?" are
 * sometimes both true: the lane ran and proved what it could, and a named sub-capability is
 * permanently unprovable against this store. Collapsing that into PASS hides the gap; collapsing it
 * into BLOCKED claims nothing was verified. Neither is true, so it gets its own word.
 */
export const STATUS_SEVERITY = ['FAIL', 'BLOCKED', 'PASS_WITH_EXCLUSIONS', 'SKIPPED', 'PASS']

export function worstStatus(statuses) {
	for (const candidate of STATUS_SEVERITY) {
		if (statuses.includes(candidate)) return candidate
	}
	return 'PASS'
}

/**
 * Decide a step's verdict from its report and the proofs its lane demands.
 *
 * `proves` names the tests that ARE the capability. If one of them is skipped — or has vanished
 * from the run entirely — the capability is unproven and the step is BLOCKED, however many other
 * tests passed alongside it. Anything else skipped is an exclusion: recorded, never hidden, but not
 * a claim that the lane proved nothing.
 *
 * @returns {{status:string, note:string|null, unproven:{name:string,reason:string}[]}}
 */
export function classify(report, { exitCode, proves = [] }) {
	if (report === null) {
		// No machine-readable report: the exit code is all there is, and a non-test command has no
		// skips to hide. Recorded as unparsed so the evidence never overstates what was checked.
		const passed = exitCode === 0
		return {
			status: passed ? 'PASS' : 'FAIL',
			note: passed ? null : `exited ${exitCode}`,
			unproven: [],
		}
	}

	if (report.failed > 0) {
		return { status: 'FAIL', note: `${report.failed} test(s) failed`, unproven: [] }
	}
	if (exitCode !== 0) {
		return { status: 'FAIL', note: `runner exited ${exitCode} with no failing test`, unproven: [] }
	}
	if (report.tests === 0) {
		return { status: 'BLOCKED', note: 'the runner reported no tests at all', unproven: [] }
	}

	const missingProof = missingProofs(report, proves)
	if (missingProof.length > 0) {
		return {
			status: 'BLOCKED',
			note: `the capability this lane exists to prove was not proven: ${missingProof
				.map((entry) => entry.reason)
				.join('; ')}`,
			unproven: [...missingProof, ...otherSkips(report, missingProof)],
		}
	}

	if (report.skipped === report.tests) {
		return {
			status: 'BLOCKED',
			note: 'every test skipped, so nothing was verified',
			unproven: report.skips,
		}
	}

	if (report.skipped > 0) {
		return {
			status: 'PASS_WITH_EXCLUSIONS',
			note: `${report.passed} passed, ${report.skipped} skipped`,
			unproven: report.skips,
		}
	}

	return { status: 'PASS', note: null, unproven: [] }
}

/** Declared proofs that were skipped, or that no test in the run answers to. */
function missingProofs(report, proves) {
	const missing = []
	for (const pattern of proves) {
		const expression = new RegExp(pattern, 'i')
		const skipped = report.skips.find((skip) => expression.test(skip.name))
		if (skipped) {
			missing.push(skipped)
			continue
		}
		// A proof nobody ran is not a proof. This also catches a renamed test, which is the safe
		// direction to fail in: the lane goes BLOCKED until somebody reconciles the declaration.
		if (!(report.names ?? []).some((name) => expression.test(name))) {
			missing.push({
				name: String(pattern),
				reason: `no test matching /${pattern}/ ran, so the declared proof is absent`,
			})
		}
	}
	return missing
}

function otherSkips(report, alreadyListed) {
	const listed = new Set(alreadyListed.map((entry) => entry.name))
	return report.skips.filter((skip) => !listed.has(skip.name))
}
