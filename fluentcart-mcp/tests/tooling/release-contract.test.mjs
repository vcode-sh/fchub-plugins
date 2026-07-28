import assert from 'node:assert/strict'
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { dirname, join, resolve, sep } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')

function readJson(relativePath) {
	return JSON.parse(readFileSync(join(PACKAGE_ROOT, relativePath), 'utf8'))
}

const rawContract = readFileSync(join(PACKAGE_ROOT, 'release-contract.json'), 'utf8')
const contract = JSON.parse(rawContract)
const pkg = readJson('package.json')
const manifest = readJson('manifest.json')

const MODE_KEYS = ['toolCount', 'characters', 'cl100kTokens', 'o200kTokens']
const WRITE_MODES = ['disabled', 'reversible', 'guarded']
const LEGACY_RUNTIME_FIXTURE = 'tests/fixtures/routes/fluentcart-1.3.9-runtime.json'

/** The five mandatory rows, in order. */
const MANDATORY_PROFILES = [
	'legacy-1.3.9-runtime-rest-disabled',
	'core-1.5.5-rest-disabled',
	'core-1.5.5-pro-1.5.4-rest-disabled',
	'core-1.5.5-pro-1.5.4-rest-reversible',
	'core-1.5.5-pro-1.5.4-standalone-guarded',
]

/**
 * Meta-tool counts fixed by the exposure design. Nothing registry-sized is pinned here — its
 * gate is `build-release-contract.mjs --check`.
 *
 * Dynamic is three under the disabled policy and four when reversible writes survive filtering.
 * The guarded executor remains conditional on a real-money tool surviving the same filter.
 * Advertising an executor that can only answer "not exposed" would claim a capability the
 * connected policy does not provide.
 */
function expectedMetaToolCount(profile, mode) {
	if (mode === 'code') return 2
	if (mode === 'dynamic') return profile.writeMode === 'disabled' ? 3 : 4
	throw new Error(`No fixed meta-tool count for mode ${mode}`)
}

/** A profile is measurable only when every fixture it declares is actually on disk. */
function fixturesPresent(profile) {
	return [profile.componentFixture, profile.guardFixture]
		.filter((path) => path !== null)
		.every((path) => existsSync(join(PACKAGE_ROOT, path.split('/').join(sep))))
}

function profileNamed(name) {
	return contract.profiles.find((profile) => profile.name === name)
}

describe('release contract version agreement', () => {
	it('agrees with package.json and the MCPB manifest on the release version', () => {
		assert.equal(contract.packageVersion, pkg.version)
		assert.equal(manifest.version, contract.packageVersion)
		// Neither the already-published 1.1.0 nor the manifest's stale 1.0.1 may reappear.
		assert.notEqual(contract.packageVersion, '1.1.0')
		assert.notEqual(contract.packageVersion, '1.0.1')
	})

	/** Absent only inside a published package, where web-docs was never shipped. */
	it('agrees with web-docs/lib/versions.json on version and counts', () => {
		const path = join(PACKAGE_ROOT, '..', 'web-docs', 'lib', 'versions.json')
		if (!existsSync(path)) return

		const mcp = JSON.parse(readFileSync(path, 'utf8'))
		const entry = mcp.plugins['fluentcart-mcp']
		assert.equal(entry.version, contract.packageVersion)
		assert.equal(entry.tagName, `fluentcart-mcp/v${contract.packageVersion}`)
		assert.equal(mcp.mcp.toolCount, contract.sourceDefinitionCount)
		assert.equal(mcp.mcp.moduleCount, contract.categoryCount)
	})
})

describe('release contract source digest', () => {
	it('is a sha256 over a declared input list that excludes both generated files', () => {
		assert.match(contract.sourceTreeDigest, /^sha256:[0-9a-f]{64}$/)
		assert.ok(contract.sourceTreeInputs.fileCount > 0)
		for (const name of ['release-contract.json', 'manifest.json']) {
			assert.ok(contract.sourceTreeInputs.excluded.includes(name))
		}
		assert.doesNotMatch(
			JSON.stringify(contract.sourceTreeInputs.declared),
			/-contract\.json|manifest\.json/,
		)
	})

	it('declares only inputs on disk, carries no Git SHA, and is serialised canonically', () => {
		for (const input of contract.sourceTreeInputs.declared) {
			const relative = input.file ?? input.directory
			assert.ok(existsSync(join(PACKAGE_ROOT, relative.split('/').join(sep))), relative)
		}
		assert.doesNotMatch(rawContract, /"(gitSha|commit|sourceSha|revision)"/i)
		assert.equal(rawContract, `${JSON.stringify(contract, null, 2)}\n`)
	})
})

describe('release contract registry counts', () => {
	it('counts the source registry, and never exposes more than it holds', () => {
		const exposure = contract.writePolicyExposure
		assert.ok(contract.sourceDefinitionCount > 0)
		assert.ok(exposure.disabled > 0)
		assert.ok(exposure.disabled < exposure.reversible, 'writes must widen exposure')
		assert.ok(exposure.guarded <= contract.sourceDefinitionCount)
		assert.equal(contract.categoryCount, 20)
	})

	/**
	 * Guarded may only exceed reversible by the real-money rows actually wired to `guarded-rest`.
	 * Stated as an identity rather than two pinned totals, so it keeps holding as those rows are
	 * built out — and still catches a guarded mode that quietly exposed something else.
	 */
	it('lets guarded exceed reversible by exactly the guarded-rest rows', () => {
		const exposure = contract.writePolicyExposure
		assert.equal(exposure.guarded - exposure.reversible, exposure.realMoneyExposable)
		assert.ok(exposure.realMoneyExposable >= 0)
	})

	it('accounts for every curated name, resolvable or not', () => {
		const curated = contract.curatedNames
		assert.equal(curated.resolvable + curated.unresolved.length, curated.declared)
		for (const name of curated.unresolved) assert.match(name, /^fluentcart_[a-z_]+$/)
	})

	it('labels its serializer and tokenizer, and records that routes prune the registry', () => {
		assert.equal(contract.serializer, 'mcp-tools-list-v1')
		assert.equal(contract.tokenizer, 'gpt-tokenizer@3.4.0')
		assert.equal(contract.capabilityFiltering.appliedToToolRegistry, true)
	})
})

describe('release contract profiles', () => {
	it('emits all five mandatory rows, in order, measuring 1.3.9 against a real runtime', () => {
		assert.deepEqual(
			contract.profiles.map((profile) => profile.name),
			MANDATORY_PROFILES,
		)
		const legacy = profileNamed('legacy-1.3.9-runtime-rest-disabled')
		assert.equal(legacy.componentFixture, LEGACY_RUNTIME_FIXTURE)
		assert.equal(legacy.evidenceKind, 'live-rest-index')
		assert.equal(legacy.replaces, null)
	})

	it('gives every row a permitted status and a declared write mode', () => {
		for (const p of contract.profiles) {
			assert.ok(['MEASURED', 'BLOCKED'].includes(p.status), p.name)
			assert.ok(WRITE_MODES.includes(p.writeMode), p.name)
		}
	})
})

describe('blocked profiles', () => {
	const blocked = () => contract.profiles.filter((profile) => profile.status === 'BLOCKED')

	/** Blocked is a consequence of absent evidence, not a hand-maintained list. */
	it('blocks a row if and only if its declared evidence is missing', () => {
		for (const profile of contract.profiles) {
			const measurable = profile.evidenceKind !== 'docs-contract' && fixturesPresent(profile)
			assert.equal(profile.status, measurable ? 'MEASURED' : 'BLOCKED', profile.name)
		}
	})

	it('carries no counts at all, and says out loud what is blocking it', () => {
		for (const profile of blocked()) {
			assert.equal(profile.modes, null, profile.name)
			assert.equal(profile.exposedDefinitionCount, null, profile.name)
			assert.match(profile.reason, /\S/, profile.name)
		}
	})

	it('names a fixture that is genuinely absent, never one sitting on disk', () => {
		for (const profile of blocked()) {
			const declared = [profile.componentFixture, profile.guardFixture, LEGACY_RUNTIME_FIXTURE]
			assert.ok(declared.includes(profile.missingFixture), profile.name)
		}
	})
})

describe('measured profiles', () => {
	const measured = () => contract.profiles.filter((profile) => profile.status === 'MEASURED')

	it('measures at least the current core plus Pro runtime, and nothing unmandated', () => {
		const names = measured().map((profile) => profile.name)
		assert.ok(names.includes('core-1.5.5-pro-1.5.4-rest-disabled'))
		for (const name of names) assert.ok(MANDATORY_PROFILES.includes(name), name)
		for (const profile of measured()) {
			assert.ok(fixturesPresent(profile), profile.name)
			assert.equal(profile.missingFixture, null, profile.name)
		}
	})

	it('reports four modes with the four measured fields and fixed meta-tool counts', () => {
		for (const profile of measured()) {
			assert.deepEqual(Object.keys(profile.modes).sort(), ['code', 'curated', 'dynamic', 'full'])
			for (const [mode, row] of Object.entries(profile.modes)) {
				assert.deepEqual(Object.keys(row), MODE_KEYS, `${profile.name}/${mode}`)
			}
			for (const mode of ['dynamic', 'code']) {
				assert.equal(
					profile.modes[mode].toolCount,
					expectedMetaToolCount(profile, mode),
					`${profile.name}/${mode}`,
				)
			}
		}
	})

	/** The table is the unpruned ceiling; pruning only removes, so a row never exceeds it. */
	it('never exposes more than its write mode permits, and full lists exactly what it exposes', () => {
		for (const profile of measured()) {
			const ceiling = contract.writePolicyExposure[profile.writeMode]
			assert.ok(profile.exposedDefinitionCount <= ceiling, `${profile.name} exceeds its ceiling`)
			assert.equal(profile.modes.full.toolCount, profile.exposedDefinitionCount, profile.name)
			assert.ok(profile.exposedDefinitionCount <= contract.sourceDefinitionCount, profile.name)
			// Curated exists to be cheaper than full; if it ever is not, it has no purpose.
			assert.ok(profile.modes.curated.toolCount < profile.modes.full.toolCount, profile.name)
			assert.ok(profile.modes.curated.cl100kTokens < profile.modes.full.cl100kTokens, profile.name)
		}
	})

	/**
	 * Guards the defect where every row measured one unpruned registry, so three profiles reported
	 * an identical count. Rows that cannot differ by fixture are decoration.
	 */
	it('measures each write-disabled row against its own fixture, not one shared registry', () => {
		const order = [
			'legacy-1.3.9-runtime-rest-disabled',
			'core-1.5.5-rest-disabled',
			'core-1.5.5-pro-1.5.4-rest-disabled',
		]
		const counts = order.map((name) => profileNamed(name)?.exposedDefinitionCount)
		if (counts.some((count) => typeof count !== 'number')) return

		assert.notEqual(new Set(counts).size, 1, 'all three disabled rows report the same count')
		for (let i = 1; i < counts.length; i += 1) {
			assert.ok(counts[i - 1] <= counts[i], `${order[i]} exposes fewer tools than ${order[i - 1]}`)
		}
	})
})

describe('legacy 1.3.9 runtime support', () => {
	/**
	 * The route surface of a 1.3.9 store is now captured, so the row measures. That is not the same
	 * as tool-level compatibility, which is recorded apart so nobody reads a measured row as a
	 * support claim.
	 */
	it('records the captured route surface without claiming tool compatibility', () => {
		const legacy = contract.legacyRuntimeSupport
		assert.equal(legacy.evidence, 'live-rest-index')
		assert.equal(legacy.routeSurfaceProven, true)
		assert.equal(legacy.toolCompatibilityProven, false)
		assert.equal(legacy.runtimeFixture, LEGACY_RUNTIME_FIXTURE)
		assert.ok(existsSync(join(PACKAGE_ROOT, LEGACY_RUNTIME_FIXTURE.split('/').join(sep))))
	})

	/**
	 * Scoped to prose a user reads, not to the raw files. The profile row is legitimately named
	 * `legacy-1.3.9-runtime-rest-disabled`, so the version string now appears in generated metadata
	 * as a reference to captured evidence. A blanket string scan would forbid naming the fixture.
	 */
	it('is still not claimed as supported in any user-facing description', () => {
		const manifest = readJson('manifest.json')
		for (const prose of [manifest.description, manifest.long_description]) {
			assert.doesNotMatch(prose, /1\.3\.9/, 'manifest prose claims 1.3.9 support')
		}
		const readme = join(PACKAGE_ROOT, 'README.md')
		if (!existsSync(readme)) return
		assert.doesNotMatch(readFileSync(readme, 'utf8'), /1\.3\.9/, 'README claims 1.3.9 support')
	})

	/**
	 * Documentation may name the captured 1.3.9 route surface — forbidding the string outright would
	 * stop it describing its own evidence. What it may not do is mention the version without saying
	 * in the same breath that support is not claimed, so the rule requires the disclaimer rather
	 * than trying to spot a claim. Enforcing the contract's claimPolicy where a reader will meet it.
	 */
	it('never mentions 1.3.9 in the docs without the accompanying disclaimer', () => {
		const docs = join(PACKAGE_ROOT, '..', 'web-docs', 'content', 'docs', 'fluentcart-mcp')
		if (!existsSync(docs)) return

		const disclaimer = /not claimed|isn't claimed|does not establish|not the same as proof/i
		for (const page of readdirSync(docs).filter((name) => name.endsWith('.mdx'))) {
			for (const line of readFileSync(join(docs, page), 'utf8').split('\n')) {
				if (!line.includes('1.3.9')) continue
				assert.match(line, disclaimer, `${page} mentions 1.3.9 without disclaiming support`)
			}
		}
	})
})
