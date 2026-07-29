import { createHash } from 'node:crypto'
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, posix, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))

/** Inputs whose bytes define generated release truth. Generated outputs exclude themselves. */
export const DIGEST_INPUTS = [
	{ file: 'package.json' },
	{ file: 'package-lock.json' },
	{ file: 'README.md' },
	{ file: 'LICENSE' },
	{ file: 'tsconfig.json' },
	{ file: 'tsconfig.release.json' },
	{ file: 'Dockerfile' },
	{ file: 'Dockerfile.release' },
	{ file: 'docker-mcp-registry/server.yaml' },
	{ file: 'compatibility-support.json' },
	{ directory: 'scripts' },
	{ directory: 'src' },
	{ directory: 'tests/fixtures' },
]

export const DIGEST_EXCLUDED = ['release-contract.json', 'manifest.json']

function walk(root, directory) {
	const found = []
	for (const entry of readdirSync(join(root, directory), { withFileTypes: true })) {
		if (entry.name === '.DS_Store') continue
		const child = posix.join(directory, entry.name)
		if (entry.isDirectory()) found.push(...walk(root, child))
		else if (entry.isFile()) found.push(child)
	}
	return found
}

export function digestInputPaths(root = PACKAGE_ROOT) {
	return [
		...new Set(
			DIGEST_INPUTS.flatMap((input) =>
				input.file ? [input.file] : walk(root, input.directory),
			),
		),
	].sort()
}

export function computeSourceTreeDigest(paths = digestInputPaths(), root = PACKAGE_ROOT) {
	const hash = createHash('sha256')
	for (const path of paths) {
		const absolute = join(root, path.split('/').join(sep))
		if (!existsSync(absolute) || !statSync(absolute).isFile()) {
			throw new Error(`Declared digest input is missing: ${path}`)
		}
		hash.update(path)
		hash.update('\0')
		hash.update(readFileSync(absolute))
		hash.update('\0')
	}
	return `sha256:${hash.digest('hex')}`
}
