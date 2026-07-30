import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, it } from 'node:test'
import {
	APPROVED_FALLBACK_FINGERPRINTS,
	fingerprintAbility,
} from '../../dist/abilities/compatibility.js'

const fixtureText = readFileSync(
	new URL('../fixtures/abilities/fluentcart-1.6.0-wordpress-7.0.2.json', import.meta.url),
	'utf8',
)
const fixture = JSON.parse(fixtureText)
const legacyFixture = JSON.parse(
	readFileSync(
		new URL('../fixtures/abilities/fluentcart-1.5.5-wordpress-7.0.2.json', import.meta.url),
		'utf8',
	),
)
const support = JSON.parse(
	readFileSync(new URL('../../compatibility-support.json', import.meta.url), 'utf8'),
)

function runCaptureFault(scenario) {
	const directory = mkdtempSync(join(tmpdir(), 'abilities-capture-fault-'))
	const profilePath = join(directory, 'profile.json')
	const tracePath = join(directory, 'trace.json')
	const preloadPath = join(directory, 'preload.mjs')
	writeFileSync(
		profilePath,
		JSON.stringify({
			wordpress: '7.0.2',
			activeComponents: [
				{ slug: 'fluent-cart', version: '1.6.0' },
				{ slug: 'fluent-cart-pro', version: '1.6.0' },
			],
		}),
	)
	writeFileSync(
		preloadPath,
		`
import { writeFileSync } from 'node:fs'

let enabled = false
let statusReads = 0
const toggles = []
let disabledVerified = false
const json = (status, body) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

globalThis.fetch = async (url, init = {}) => {
  const pathname = new URL(url).pathname
  const method = init.method ?? 'GET'
  if (pathname.endsWith('/fluent-cart/v2/settings/mcp/toggle') && method === 'POST') {
    const requested = JSON.parse(String(init.body)).mcp_enabled
    enabled = requested
    toggles.push(requested)
    if (requested === true && process.env.CAPTURE_FAULT === 'toggle-malformed') {
      return new Response('{', { status: 200 })
    }
    if (requested === true && process.env.CAPTURE_FAULT === 'toggle-lost') {
      throw new Error('enable toggle response lost')
    }
    if (requested === false && process.env.CAPTURE_FAULT === 'restore-lost') {
      throw new Error('restore toggle response lost')
    }
    return json(200, { data: { mcp_enabled: enabled, adapter_available: false } })
  }
  if (pathname.endsWith('/fluent-cart/v2/settings/mcp') && method === 'GET') {
    statusReads += 1
    if (
      enabled === true &&
      (process.env.CAPTURE_FAULT === 'verification-lost' ||
        process.env.CAPTURE_FAULT === 'restore-lost')
    ) {
      throw new Error('enable verification response lost')
    }
    if (enabled === false && statusReads > 1) disabledVerified = true
    return json(200, { data: { mcp_enabled: enabled, adapter_available: false } })
  }
  throw new Error('unexpected request: ' + method + ' ' + pathname)
}

process.on('exit', () => {
  writeFileSync(
    process.env.CAPTURE_TRACE,
    JSON.stringify({ enabled, toggles, statusReads, disabledVerified }),
  )
})
`,
	)

	try {
		const result = spawnSync(
			process.execPath,
			[
				'--import',
				preloadPath,
				new URL('../../scripts/capture-abilities-contract.mjs', import.meta.url).pathname,
				'--profile',
				profilePath,
			],
			{
				encoding: 'utf8',
				env: {
					...process.env,
					CAPTURE_FAULT: scenario,
					CAPTURE_TRACE: tracePath,
					FLUENTCART_URL: 'http://127.0.0.1:9081',
					FLUENTCART_USERNAME: 'fault-user',
					FLUENTCART_APP_PASSWORD: 'fault-password',
				},
			},
		)
		return { result, trace: JSON.parse(readFileSync(tracePath, 'utf8')) }
	} finally {
		rmSync(directory, { recursive: true, force: true })
	}
}

describe('FluentCart WordPress Abilities evidence', () => {
	it('contains the exact 1.6.0 catalogue without host or credential material', () => {
		assert.equal(fixture.schemaVersion, 2)
		assert.equal(fixture.profile.wordpress, '7.0.2')
		assert.equal(fixture.profile.source, 'wp-cli')
		assert.equal(
			fixture.profile.activeComponents.find((entry) => entry.slug === 'fluent-cart')?.version,
			'1.6.0',
		)
		assert.equal(
			fixture.profile.activeComponents.find((entry) => entry.slug === 'fluent-cart-pro')?.version,
			'1.6.0',
		)
		assert.equal(fixture.abilities.length, 33)
		assert.equal(new Set(fixture.abilities.map((row) => row.name)).size, 33)
		assert.equal(fixture.authentication.unauthenticatedDiscoveryStatus, 401)
		assert.equal(fixture.authentication.authenticatedDiscoveryStatus, 200)
		assert.doesNotMatch(fixtureText, /localhost|fchub\\.vcode\\.sh|Authorization|app.password/i)
	})

	it('records the observed WordPress readonly mismatch', () => {
		const reads = fixture.abilities.filter((row) => row.annotations.mcpReadOnlyHint === true)
		assert.equal(reads.length, 26)
		for (const row of reads) assert.equal(row.annotations.abilitiesReadonly, null)
		assert.deepEqual(fixture.compatibility, {
			representativeAbility: 'fluent-cart/get-store-context',
			getStatus: 405,
			getCode: 'rest_ability_invalid_method',
			postStatus: 200,
			responseBodiesPersisted: false,
		})
		assert.equal(support.abilityParity.executionMethod, 'POST')
		assert.equal(support.abilityParity.executionMethodEvidence.getStatus, 405)
		assert.equal(support.abilityParity.executionMethodEvidence.postStatus, 200)
	})

	it('pins one canonical fallback fingerprint per admitted missing-readonly read', () => {
		const admitted = new Set(
			support.abilityParity.rows
				.filter((row) => row.bridgeAvailable === true)
				.map((row) => row.ability),
		)
		const missingReadonlyReads = fixture.abilities.filter(
			(row) =>
				admitted.has(row.name) &&
				row.annotations.abilitiesReadonly === null &&
				row.annotations.abilitiesDestructive !== true &&
				row.annotations.mcpReadOnlyHint === true &&
				row.annotations.mcpDestructiveHint !== true,
		)
		const expected = missingReadonlyReads.map((row) => ({
			ability: row.name,
			fingerprint: fingerprintAbility(row),
		}))

		assert.equal(expected.length, 26)
		assert.deepEqual(fixture.fallbackFingerprints, expected)
		assert.deepEqual(
			fixture.fallbackFingerprints.map((entry) => entry.ability),
			fixture.fallbackFingerprints.map((entry) => entry.ability).toSorted(),
		)
		assert.equal(
			new Set(fixture.fallbackFingerprints.map((entry) => entry.fingerprint)).size,
			fixture.fallbackFingerprints.length,
		)
		const auditedFingerprints = new Set([
			...legacyFixture.fallbackFingerprints.map((entry) => entry.fingerprint),
			...expected.map((entry) => entry.fingerprint),
		])
		assert.deepEqual(
			[...APPROVED_FALLBACK_FINGERPRINTS].toSorted(),
			[...auditedFingerprints].toSorted(),
		)
	})

	it('maps every captured ability to one explicit disposition', () => {
		const rows = support.abilityParity.rows
		assert.equal(rows.length, 33)
		assert.deepEqual(
			rows.map((row) => row.ability).sort(),
			fixture.abilities.map((row) => row.name).sort(),
		)
		for (const row of rows) {
			assert.equal(typeof row.standaloneEquivalent, 'string')
			assert.equal(typeof row.semanticDifference, 'string')
			assert.ok(['standalone', 'optional-read-bridge', 'unavailable'].includes(row.disposition))
		}
	})

	it('describes unavailable subscription cancellation without implying an execution path', () => {
		const cancellation = support.abilityParity.rows.find(
			({ ability }) => ability === 'fluent-cart/change-subscription-status',
		)
		assert.equal(cancellation?.disposition, 'unavailable')
		assert.equal(
			cancellation?.semanticDifference,
			'The native ability covers several lifecycle transitions; standalone cancellation is unavailable in every write mode until a cleanable acceptance lane proves it.',
		)
	})
})

describe('Ability capture state restoration', () => {
	for (const scenario of ['verification-lost', 'toggle-malformed', 'toggle-lost']) {
		it(`restores and verifies the initially disabled state after ${scenario}`, () => {
			const { result, trace } = runCaptureFault(scenario)

			assert.notEqual(result.status, 0)
			assert.deepEqual(trace.toggles, [true, false])
			assert.equal(trace.enabled, false)
			assert.equal(trace.disabledVerified, true)
		})
	}

	it('retains the capture failure when restoration also fails', () => {
		const { result, trace } = runCaptureFault('restore-lost')

		assert.notEqual(result.status, 0)
		assert.deepEqual(trace.toggles, [true, false])
		assert.equal(trace.enabled, false)
		assert.match(result.stderr, /enable verification response lost/)
		assert.match(result.stderr, /restore toggle response lost/)
	})
})
