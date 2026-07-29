import assert from 'node:assert/strict'
import { mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, posix } from 'node:path'
import { after, describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	computeSourceTreeDigest,
	digestInputPaths,
} from '../../scripts/release-contract-inputs.mjs'

const PACKAGE_ROOT = dirname(dirname(dirname(fileURLToPath(import.meta.url))))
const scratch = mkdtempSync(join(tmpdir(), 'release-input-closure-'))
after(() => rmSync(scratch, { recursive: true, force: true }))

function walk(relative) {
	const found = []
	for (const entry of readdirSync(join(PACKAGE_ROOT, relative), { withFileTypes: true })) {
		const child = posix.join(relative, entry.name)
		if (entry.isDirectory()) found.push(...walk(child))
		else if (entry.isFile()) found.push(child)
	}
	return found
}

const REQUIRED_ROOT_FILES = [
	'package.json',
	'package-lock.json',
	'README.md',
	'LICENSE',
	'tsconfig.json',
	'tsconfig.release.json',
	'Dockerfile',
	'Dockerfile.release',
	'docker-mcp-registry/server.yaml',
	'compatibility-support.json',
]

describe('release digest input closure', () => {
	it('covers every shipped root file, generator, runtime source and evidence fixture', () => {
		const actual = new Set(digestInputPaths())
		for (const path of REQUIRED_ROOT_FILES) assert.ok(actual.has(path), path)
		for (const directory of ['scripts', 'src', 'tests/fixtures']) {
			for (const path of walk(directory)) assert.ok(actual.has(path), path)
		}
	})

	it('is sorted, unique and excludes only circular generated outputs', () => {
		const paths = digestInputPaths()
		assert.deepEqual(paths, [...new Set(paths)].sort())
		assert.ok(!paths.includes('release-contract.json'))
		assert.ok(!paths.includes('manifest.json'))
	})

	for (const path of ['package-lock.json', 'scripts/manifest-config.mjs', 'README.md', 'LICENSE']) {
		it(`changes when ${path} changes independently`, () => {
			const targetRoot = join(scratch, path.replaceAll('/', '-'))
			const target = join(targetRoot, path)
			mkdirSync(dirname(target), { recursive: true })
			writeFileSync(target, readFileSync(join(PACKAGE_ROOT, path)))
			const before = computeSourceTreeDigest([path], targetRoot)
			writeFileSync(target, Buffer.concat([readFileSync(target), Buffer.from('\nmutation\n')]))
			assert.notEqual(computeSourceTreeDigest([path], targetRoot), before)
		})
	}

	it('hashes the path as well as the bytes and rejects a missing declared input', () => {
		const first = join(scratch, 'path-a')
		const second = join(scratch, 'path-b')
		mkdirSync(join(first, 'a'), { recursive: true })
		mkdirSync(join(second, 'b'), { recursive: true })
		writeFileSync(join(first, 'a/value.txt'), 'same bytes')
		writeFileSync(join(second, 'b/value.txt'), 'same bytes')
		assert.notEqual(
			computeSourceTreeDigest(['a/value.txt'], first),
			computeSourceTreeDigest(['b/value.txt'], second),
		)
		assert.throws(
			() => computeSourceTreeDigest(['missing.txt'], scratch),
			/Declared digest input is missing: missing\.txt/,
		)
	})
})
