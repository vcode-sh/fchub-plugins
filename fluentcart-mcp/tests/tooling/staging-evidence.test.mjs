import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { after, describe, it } from 'node:test'
import { verifyStagingChecksums } from '../../scripts/verify-staged-release.mjs'

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

function writeGoodEvidence() {
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
				schemaVersion: 2,
				version: VERSION,
				sourceSha: '1'.repeat(40),
				sourceTreeDigest: `sha256:${'2'.repeat(64)}`,
				checksumsSha256: sha256(checksums),
				npmIntegrity: 'sha512-public',
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
