import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { after, describe, it } from 'node:test'
import { verifyStagingChecksums } from '../../scripts/verify-staged-release.mjs'
import {
	buildDirectPublishingState,
	buildStagingState,
	parseNativeStageResult,
} from '../../scripts/write-staging-state.mjs'

const scratch = mkdtempSync(join(tmpdir(), 'staging-evidence-'))
after(() => rmSync(scratch, { recursive: true, force: true }))

const VERSION = '2.0.0'
const REQUIRED_ASSETS = [
	`fluentcart-mcp-${VERSION}.tgz`,
	'fluentcart-mcp.mcpb',
	'fluentcart-mcp-docker-context.tar.gz',
	'previous-release-state.json',
	'release-contract.json',
]

function sha256(value) {
	return createHash('sha256').update(value).digest('hex')
}

function writeGoodEvidence(schemaVersion = 3) {
	const files = REQUIRED_ASSETS.map((file) => {
		const value = Buffer.from(`checked bytes for ${file}\n`)
		writeFileSync(join(scratch, file), value)
		return { file, sha256: sha256(value) }
	})
	const checksums = `${JSON.stringify({ algorithm: 'sha256', files }, null, 2)}\n`
	writeFileSync(join(scratch, 'SHA256SUMS.json'), checksums)
	writeFileSync(
		join(scratch, 'staging-state.json'),
		`${JSON.stringify(
			{
				schemaVersion,
				version: VERSION,
				sourceSha: '1'.repeat(40),
				sourceTreeDigest: `sha256:${'2'.repeat(64)}`,
				checksumsSha256: sha256(checksums),
				...(schemaVersion === 2
					? { npmIntegrity: 'sha512-legacy-public' }
					: schemaVersion === 4
						? {
								npm: {
									mode: 'direct',
									tag: 'latest',
									expectedIntegrity: 'sha512-trusted-publishing',
								},
							}
						: {
								npm: {
									stageId: '123e4567-e89b-42d3-a456-426614174000',
									tag: 'latest',
									expectedIntegrity: 'sha512-native-stage',
								},
							}),
				dockerDigests: {
					'ghcr.io': `sha256:${'3'.repeat(64)}`,
					'docker.io': `sha256:${'4'.repeat(64)}`,
				},
			},
			null,
			2,
		)}\n`,
	)
}

describe('downloaded staging evidence', () => {
	it('accepts the exact checksum-bound asset set', () => {
		writeGoodEvidence()
		assert.doesNotThrow(() => verifyStagingChecksums(scratch))
	})

	it('accepts schema 2 evidence for the one-time 2.0.0 recovery', () => {
		writeGoodEvidence(2)
		assert.doesNotThrow(() => verifyStagingChecksums(scratch))
	})

	it('accepts direct Trusted Publishing evidence', () => {
		writeGoodEvidence(4)
		assert.doesNotThrow(() => verifyStagingChecksums(scratch))
	})

	it('rejects native evidence without an npm stage identifier', () => {
		writeGoodEvidence()
		const statePath = join(scratch, 'staging-state.json')
		const state = JSON.parse(readFileSync(statePath, 'utf8'))
		state.npm.stageId = ''
		writeFileSync(statePath, `${JSON.stringify(state, null, 2)}\n`)
		assert.throws(() => verifyStagingChecksums(scratch), /invalid npm stageId/)
	})

	for (const file of REQUIRED_ASSETS) {
		it(`rejects corrupted ${file} when staging-state is unchanged`, () => {
			writeGoodEvidence()
			writeFileSync(
				join(scratch, file),
				Buffer.concat([readFileSync(join(scratch, file)), Buffer.from('x')]),
			)
			assert.throws(() => verifyStagingChecksums(scratch), new RegExp(`${file}.*checksum mismatch`))
		})
	}

	it('rejects a changed checksum catalogue when staging-state is unchanged', () => {
		writeGoodEvidence()
		writeFileSync(
			join(scratch, 'SHA256SUMS.json'),
			Buffer.concat([readFileSync(join(scratch, 'SHA256SUMS.json')), Buffer.from(' ')]),
		)
		assert.throws(() => verifyStagingChecksums(scratch), /SHA256SUMS\.json checksum mismatch/)
	})

	it('rejects an unlisted downloaded asset', () => {
		writeGoodEvidence()
		writeFileSync(join(scratch, 'unreviewed.sh'), 'echo nope\n')
		assert.throws(() => verifyStagingChecksums(scratch), /unreviewed\.sh is not checksummed/)
	})
})

describe('native npm stage result', () => {
	const tarball = Buffer.from('reviewed npm tarball')
	const integrity = `sha512-${createHash('sha512').update(tarball).digest('base64')}`
	const stageId = '123e4567-e89b-42d3-a456-426614174000'
	const nativeResult = {
		'fluentcart-mcp': {
			name: 'fluentcart-mcp',
			version: VERSION,
			integrity,
			stageId,
		},
	}

	it('reads the package-keyed JSON shape emitted by npm 11.15', () => {
		assert.equal(
			parseNativeStageResult(nativeResult, 'fluentcart-mcp', VERSION, integrity).stageId,
			stageId,
		)
	})

	it('rejects the flat shape that would orphan a successful native stage', () => {
		assert.throws(
			() =>
				parseNativeStageResult(
					nativeResult['fluentcart-mcp'],
					'fluentcart-mcp',
					VERSION,
					integrity,
				),
			/expected fluentcart-mcp package key/,
		)
	})

	it('binds npm identity and local integrity into schema 3 evidence', () => {
		const state = buildStagingState({
			stageResult: JSON.stringify(nativeResult),
			tarballBytes: tarball,
			checksumsBytes: Buffer.from('checksums'),
			version: VERSION,
			sourceSha: '1'.repeat(40),
			sourceTreeDigest: `sha256:${'2'.repeat(64)}`,
			ghcrDigest: `sha256:${'3'.repeat(64)}`,
			dockerhubDigest: `sha256:${'4'.repeat(64)}`,
		})
		assert.deepEqual(state.npm, {
			stageId,
			tag: 'latest',
			expectedIntegrity: integrity,
		})
	})

	it('rejects a stage result whose integrity differs from the inspected tarball', () => {
		const changed = structuredClone(nativeResult)
		changed['fluentcart-mcp'].integrity = 'sha512-wrong'
		assert.throws(
			() => parseNativeStageResult(changed, 'fluentcart-mcp', VERSION, integrity),
			/integrity does not match/,
		)
	})
})

describe('direct npm Trusted Publishing evidence', () => {
	it('binds local npm integrity without a stage identifier', () => {
		const tarball = Buffer.from('reviewed npm tarball')
		const state = buildDirectPublishingState({
			tarballBytes: tarball,
			checksumsBytes: Buffer.from('checksums'),
			version: VERSION,
			sourceSha: '1'.repeat(40),
			sourceTreeDigest: `sha256:${'2'.repeat(64)}`,
			ghcrDigest: `sha256:${'3'.repeat(64)}`,
			dockerhubDigest: `sha256:${'4'.repeat(64)}`,
		})
		assert.deepEqual(state.npm, {
			mode: 'direct',
			tag: 'latest',
			expectedIntegrity: `sha512-${createHash('sha512').update(tarball).digest('base64')}`,
		})
	})
})
