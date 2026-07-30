import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { after, describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const CHECKER = join(PACKAGE_ROOT, 'scripts/verify-mcp-sdk-current.mjs')
const FIXTURE = join(PACKAGE_ROOT, 'tests/fixtures/sdk/npm-registry-current.json')
const current = JSON.parse(readFileSync(FIXTURE, 'utf8'))
const scratch = mkdtempSync(join(tmpdir(), 'fluentcart-sdk-current-'))

after(() => rmSync(scratch, { recursive: true, force: true }))

function runFixture(fixture) {
	const path = join(scratch, `fixture-${crypto.randomUUID()}.json`)
	writeFileSync(path, `${JSON.stringify(fixture, null, 2)}\n`)
	return spawnSync(process.execPath, [CHECKER, '--fixture', path, '--json'], {
		cwd: PACKAGE_ROOT,
		encoding: 'utf8',
	})
}

function expectRejected(mutate, pattern) {
	const fixture = structuredClone(current)
	mutate(fixture)
	const result = runFixture(fixture)
	assert.notEqual(result.status, 0, `fixture unexpectedly passed:\n${result.stdout}`)
	assert.match(`${result.stdout}\n${result.stderr}`, pattern)
}

describe('MCP SDK registry-current gate', () => {
	it('accepts one coherent exact SDK v2 graph and selects modern conformance alpha', () => {
		const result = runFixture(current)
		assert.equal(result.status, 0, result.stderr)
		const evidence = JSON.parse(result.stdout)
		assert.equal(evidence.status, 'current')
		assert.equal(evidence.sdkVersion, '2.0.0')
		assert.equal(evidence.conformance.selected, '0.2.0-alpha.10')
		assert.equal(evidence.conformance.channel, 'alpha')
		assert.deepEqual(
			evidence.packages.map(({ name, version }) => [name, version]),
			[
				['@modelcontextprotocol/server', '2.0.0'],
				['@modelcontextprotocol/client', '2.0.0'],
				['@modelcontextprotocol/node', '2.0.0'],
				['@modelcontextprotocol/express', '2.0.0'],
				['@modelcontextprotocol/core', '2.0.0'],
			],
		)
	})

	it('rejects an outdated direct pin', () => {
		expectRejected((fixture) => {
			fixture.project.packageJson.dependencies['@modelcontextprotocol/server'] = '1.9.0'
		}, /server.*exact pin 1\.9\.0.*registry latest 2\.0\.0/i)
	})

	it('rejects mismatched stable package releases', () => {
		expectRejected((fixture) => {
			fixture.registry['@modelcontextprotocol/node'].distTags.latest = '2.0.1'
			fixture.registry['@modelcontextprotocol/node'].versions['2.0.1'] = {
				integrity: 'sha512-new-node-fixture',
				repository: 'https://github.com/modelcontextprotocol/typescript-sdk.git',
			}
		}, /stable SDK packages do not share one latest version/i)
	})

	it('rejects lockfile integrity that differs from the published tarball', () => {
		expectRejected((fixture) => {
			fixture.project.lockPackages['@modelcontextprotocol/server'].integrity =
				'sha512-wrong-fixture'
		}, /server.*lockfile integrity.*registry integrity/i)
	})

	it('rejects a stale conformance selection', () => {
		expectRejected((fixture) => {
			fixture.project.packageJson.devDependencies['@modelcontextprotocol/conformance'] =
				'0.2.0-alpha.9'
		}, /conformance.*exact pin 0\.2\.0-alpha\.9.*selected 0\.2\.0-alpha\.10/i)
	})

	it('prefers stable conformance as soon as stable covers every modern scenario', () => {
		expectRejected((fixture) => {
			const conformance = fixture.registry['@modelcontextprotocol/conformance']
			conformance.distTags.latest = '0.2.0'
			conformance.versions['0.2.0'] = {
				integrity: 'sha512-stable-modern-fixture',
				repository: 'https://github.com/modelcontextprotocol/conformance.git',
				scenarios: [...conformance.versions['0.2.0-alpha.10'].scenarios],
			}
		}, /conformance.*exact pin 0\.2\.0-alpha\.10.*selected 0\.2\.0/i)
	})

	it('fails closed on malformed registry metadata', () => {
		expectRejected((fixture) => {
			fixture.registry['@modelcontextprotocol/core'].distTags = undefined
		}, /core.*missing registry latest dist-tag/i)
	})

	it('fails closed on a registry timeout', () => {
		expectRejected((fixture) => {
			fixture.registry['@modelcontextprotocol/express'] = {
				error: 'timeout',
			}
		}, /express.*registry timeout/i)
	})
})
