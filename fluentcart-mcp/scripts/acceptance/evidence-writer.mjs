// Evidence writing for the acceptance harness.
//
// Acceptance evidence is the thing an owner reads when deciding whether to ship, so it has to be
// boring: absolute paths only, never inside tracked source, never a symlink someone repointed, and
// never carrying a credential out of the runtime. Every rule here fails the write rather than
// annotating it, because a warning in an evidence file is just a secret with better manners.

import { randomUUID } from 'node:crypto'
import { existsSync, lstatSync, mkdirSync, realpathSync, renameSync, unlinkSync, writeFileSync } from 'node:fs'
import { dirname, isAbsolute, join, normalize, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

export const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
export const REPO_ROOT = resolve(PACKAGE_ROOT, '..')

// The one place inside the package that may hold evidence, because .gitignore anchors it.
export const IGNORED_EVIDENCE_DIR = join(PACKAGE_ROOT, 'artifacts', 'acceptance')

export const SOURCE_SHA_PATTERN = /^[0-9a-f]{40}$/

// Keys that may never appear in evidence, at any depth, in any case.
export const SECRET_KEY_PATTERN = /password|token|authorization|secret|confirm|idempotency/i

// Values that carry a credential on the wire regardless of the key they hang from.
export const SECRET_VALUE_PATTERNS = [
	{ id: 'http-auth-scheme', pattern: /\b(?:Basic|Bearer) / },
	{ id: 'wp-application-password', pattern: /\b(?:[A-Za-z0-9]{4} ){5}[A-Za-z0-9]{4}\b/ },
	{ id: 'assigned-credential', pattern: /\b[A-Z][A-Z0-9_]*(?:KEY|SECRET|PASSWORD|TOKEN)\s*=\s*\S/ },
]

export class EvidenceError extends Error {
	constructor(message) {
		super(message)
		this.name = 'EvidenceError'
	}
}

function fail(message) {
	throw new EvidenceError(message)
}

function isInside(child, parent) {
	return child === parent || child.startsWith(parent + sep)
}

function canonicalRoot(path) {
	return existsSync(path) ? realpathSync(path) : path
}

// Resolve the deepest ancestor that exists, then re-attach the parts that do not. This turns an
// ancestor symlink into its real location so containment is checked against where writes land,
// not against the name the caller typed.
function canonicalise(path) {
	const trailing = []
	let current = path
	while (!existsSync(current)) {
		const parent = dirname(current)
		if (parent === current) return path
		trailing.unshift(current.slice(parent.length + 1))
		current = parent
	}
	return trailing.length === 0 ? realpathSync(current) : join(realpathSync(current), ...trailing)
}

function assertNotSymlink(path, label) {
	const stat = lstatSync(path, { throwIfNoEntry: false })
	if (stat?.isSymbolicLink()) fail(`${label} must not be a symlink: ${path}`)
}

function assertUntrackedLocation(path) {
	const repoRoot = canonicalRoot(REPO_ROOT)
	const allowed = canonicalise(IGNORED_EVIDENCE_DIR)
	if (isInside(path, allowed)) return
	if (isInside(path, repoRoot)) {
		fail(
			`output directory is inside the git repository and would land in tracked source: ${path}. ` +
				`Use a directory outside ${repoRoot}, or ${IGNORED_EVIDENCE_DIR}.`,
		)
	}
}

/**
 * Validate and canonicalise the `--output` directory.
 * @param {string} raw
 * @returns {string} absolute, symlink-free, untracked directory path
 */
export function resolveOutputRoot(raw) {
	if (typeof raw !== 'string' || raw.trim() === '') fail('output directory is required')
	if (raw.includes('\0')) fail('output directory must not contain a NUL byte')
	if (!isAbsolute(raw)) fail(`output directory must be an absolute path, received: ${raw}`)
	if (raw.split(/[\\/]/).includes('..')) {
		fail(`output directory must not contain a ".." segment, received: ${raw}`)
	}
	const normalised = normalize(raw).replace(/[\\/]+$/, '') || sep
	assertNotSymlink(normalised, 'output directory')
	const canonical = canonicalise(normalised)
	assertUntrackedLocation(canonical)
	return canonical
}

/**
 * Create the single run directory for this invocation, named for the source SHA.
 * @param {string} outputRoot canonical output root from resolveOutputRoot
 * @param {string} sourceSha 40 lowercase hex characters
 * @returns {string} absolute run directory path
 */
export function createRunDirectory(outputRoot, sourceSha) {
	if (!SOURCE_SHA_PATTERN.test(sourceSha ?? '')) {
		fail(`--source-sha must be 40 lowercase hex characters, received: ${sourceSha}`)
	}
	if (!isAbsolute(outputRoot)) fail('run directory root must be absolute')
	assertUntrackedLocation(outputRoot)
	mkdirSync(outputRoot, { recursive: true, mode: 0o700 })
	const runDirectory = join(outputRoot, sourceSha)
	assertNotSymlink(runDirectory, 'run directory')
	mkdirSync(runDirectory, { recursive: true, mode: 0o700 })
	return runDirectory
}

function describeValue(value, pointer, findings) {
	for (const { id, pattern } of SECRET_VALUE_PATTERNS) {
		if (pattern.test(value)) findings.push({ pointer, reason: `value matches ${id}` })
	}
}

function walk(value, pointer, findings, seen) {
	if (typeof value === 'string') return describeValue(value, pointer, findings)
	if (value === null || typeof value !== 'object') return
	if (seen.has(value)) fail(`evidence contains a circular reference at ${pointer}`)
	seen.add(value)
	if (Array.isArray(value)) {
		value.forEach((entry, index) => walk(entry, `${pointer}/${index}`, findings, seen))
		return
	}
	for (const [key, entry] of Object.entries(value)) {
		const childPointer = `${pointer}/${key}`
		if (SECRET_KEY_PATTERN.test(key)) {
			findings.push({ pointer: childPointer, reason: `key "${key}" is a forbidden credential key` })
		}
		walk(entry, childPointer, findings, seen)
	}
}

/**
 * @param {unknown} value
 * @returns {{pointer: string, reason: string}[]}
 */
export function findSecrets(value) {
	const findings = []
	walk(value, '', findings, new Set())
	return findings
}

/**
 * @param {unknown} value
 * @param {string} label used in the thrown message so the failure names the file
 */
export function assertNoSecrets(value, label) {
	const findings = findSecrets(value)
	if (findings.length === 0) return
	const detail = findings.map((entry) => `  ${entry.pointer || '<root>'}: ${entry.reason}`).join('\n')
	fail(`refusing to write ${label}; it contains material that must never reach evidence:\n${detail}`)
}

/**
 * Write JSON by temp-file + rename so a reader never observes a half-written evidence file.
 * @param {string} filePath absolute destination
 * @param {unknown} value
 */
export function writeJsonAtomic(filePath, value) {
	if (!isAbsolute(filePath)) fail(`evidence file path must be absolute, received: ${filePath}`)
	assertUntrackedLocation(canonicalise(filePath))
	assertNoSecrets(value, filePath)
	const serialised = `${JSON.stringify(value, null, 2)}\n`
	const temporary = `${filePath}.${randomUUID()}.tmp`
	try {
		writeFileSync(temporary, serialised, { encoding: 'utf8', mode: 0o600, flag: 'wx' })
		renameSync(temporary, filePath)
	} catch (error) {
		if (existsSync(temporary)) unlinkSync(temporary)
		throw error
	}
	return filePath
}

/**
 * Resolve an optional `--fixture` argument against the package root.
 * @param {string|undefined} raw
 * @returns {string|null} absolute path to an existing regular file
 */
export function resolveFixture(raw) {
	if (raw === undefined) return null
	if (typeof raw !== 'string' || raw.trim() === '') fail('--fixture requires a path')
	if (raw.includes('\0')) fail('--fixture must not contain a NUL byte')
	const absolute = isAbsolute(raw) ? normalize(raw) : resolve(PACKAGE_ROOT, raw)
	const packageRoot = canonicalRoot(PACKAGE_ROOT)
	if (!isInside(absolute, packageRoot)) {
		fail(`fixture must be inside the package root ${packageRoot}: ${absolute}`)
	}
	assertNotSymlink(absolute, 'fixture')
	if (!existsSync(absolute)) fail(`fixture does not exist: ${absolute}`)
	if (!lstatSync(absolute).isFile()) fail(`fixture must be a regular file: ${absolute}`)
	const canonical = realpathSync(absolute)
	if (!isInside(canonical, packageRoot)) {
		fail(`fixture must resolve inside the package root ${packageRoot}: ${absolute}`)
	}
	return canonical
}
