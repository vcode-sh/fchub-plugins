import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const PACKAGE_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const contract = JSON.parse(readFileSync(join(PACKAGE_ROOT, 'release-contract.json'), 'utf8'))

describe('capability matrix', () => {
	it('ships only disabled and reversible write profiles', () => {
		assert.deepEqual([...new Set(contract.profiles.map((profile) => profile.writeMode))].sort(), [
			'disabled',
			'reversible',
		])
	})

	it('keeps real-money operations audit-only in every measured profile', () => {
		for (const profile of contract.profiles.filter((entry) => entry.status === 'MEASURED')) {
			for (const mode of Object.values(profile.modes)) {
				assert.ok(mode.toolCount > 0)
			}
		}
		assert.equal(contract.writePolicyExposure.realMoneyExposable, undefined)
	})

	it('exposes three dynamic tools when disabled and four when reversible', () => {
		for (const profile of contract.profiles.filter((entry) => entry.status === 'MEASURED')) {
			assert.equal(profile.modes.dynamic.toolCount, profile.writeMode === 'disabled' ? 3 : 4)
		}
	})
})
