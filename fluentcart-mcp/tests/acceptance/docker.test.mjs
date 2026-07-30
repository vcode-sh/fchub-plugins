import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { after, before, describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import { installSignalCleanup, openCandidateStore } from '../../scripts/candidate-store.mjs'
import { removeDockerContainer } from '../../scripts/docker-container-cleanup.mjs'
import { validateDualEraSmoke } from '../../scripts/smoke-mcp-http.mjs'

const PACKAGE_ROOT = dirname(dirname(dirname(fileURLToPath(import.meta.url))))
const VERSION = JSON.parse(readFileSync(join(PACKAGE_ROOT, 'package.json'), 'utf8')).version
const IMAGE = process.env.FLUENTCART_ACCEPTANCE_IMAGE ?? null
const EXPECTED_SOURCE_SHA = process.env.FLUENTCART_ACCEPTANCE_SOURCE_SHA ?? null
const EXPECTED_CONTENT_DIGEST = process.env.FLUENTCART_ACCEPTANCE_SOURCE_TREE_DIGEST ?? null
const REQUIRED = process.env.FLUENTCART_ACCEPTANCE_REQUIRED === 'yes'
const EXTERNAL_STORE_URL = process.env.FLUENTCART_ACCEPTANCE_STORE_URL ?? null

/** Long enough to satisfy the 32-character floor, and obviously disposable. */
const STRONG_KEY = `acceptance-${randomBytes(24).toString('hex')}`
const EXPECTED_NAMES = [
	'fluentcart_search_tools',
	'fluentcart_describe_tools',
	'fluentcart_execute_read_tool',
]

function dualEraFixture() {
	const serverInfo = { name: 'fluentcart-mcp', version: '2.1.0' }
	const modernBase = {
		resultType: 'complete',
		ttlMs: 0,
		cacheScope: 'private',
		_meta: { 'io.modelcontextprotocol/serverInfo': serverInfo },
	}
	return {
		legacy: {
			initialize: {
				protocolVersion: '2025-11-25',
				serverInfo,
				capabilities: {
					tools: { listChanged: false },
					resources: { listChanged: false },
					prompts: { listChanged: false },
				},
			},
			tools: { tools: EXPECTED_NAMES.map((name) => ({ name })) },
			sessionId: null,
		},
		modern: {
			discovery: {
				...modernBase,
				supportedVersions: ['2026-07-28'],
				capabilities: {
					tools: { listChanged: false },
					resources: { listChanged: false },
					prompts: { listChanged: false },
				},
			},
			tools: {
				...modernBase,
				tools: EXPECTED_NAMES.map((name) => ({ name })),
			},
			sessionId: null,
		},
		expectedToolNames: EXPECTED_NAMES,
	}
}

describe('dual-era Docker smoke validator', () => {
	it('accepts one coherent legacy and modern candidate transcript', () => {
		assert.deepEqual(validateDualEraSmoke(dualEraFixture()), {
			legacyProtocol: '2025-11-25',
			modernProtocol: '2026-07-28',
			toolCount: 3,
		})
	})

	it('rejects a legacy-only or modern-only transcript', () => {
		const legacyOnly = dualEraFixture()
		legacyOnly.modern = undefined
		assert.throws(() => validateDualEraSmoke(legacyOnly), /modern transcript/)

		const modernOnly = dualEraFixture()
		modernOnly.legacy = undefined
		assert.throws(() => validateDualEraSmoke(modernOnly), /legacy transcript/)
	})

	it('rejects wrong tool order or surface', () => {
		const wrongOrder = dualEraFixture()
		wrongOrder.modern.tools.tools.reverse()
		assert.throws(() => validateDualEraSmoke(wrongOrder), /modern tools\/list surface/)

		const wrongSurface = dualEraFixture()
		wrongSurface.legacy.tools.tools.pop()
		assert.throws(() => validateDualEraSmoke(wrongSurface), /legacy tools\/list surface/)
	})

	it('rejects a session header or missing modern cache/result fields', () => {
		const session = dualEraFixture()
		session.modern.sessionId = 'accidental-session'
		assert.throws(() => validateDualEraSmoke(session), /Mcp-Session-Id/)

		for (const field of ['resultType', 'ttlMs', 'cacheScope']) {
			const missing = dualEraFixture()
			delete missing.modern.discovery[field]
			assert.throws(() => validateDualEraSmoke(missing), new RegExp(field))
		}
	})
})

function docker(args, options = {}) {
	return spawnSync('docker', args, { encoding: 'utf8', timeout: 60_000, ...options })
}

function dockerAvailable() {
	const probe = docker(['version', '--format', '{{.Server.Version}}'])
	return probe.status === 0
}

function imagePresent() {
	return docker(['image', 'inspect', IMAGE, '--format', '{{.Id}}']).status === 0
}

function inspectImage() {
	const result = docker(['image', 'inspect', IMAGE])
	assert.equal(result.status, 0, `docker image inspect failed: ${result.stderr}`)
	return JSON.parse(result.stdout)[0]
}

/** Reason the whole suite cannot run, or null when it can. */
function blockedReason() {
	if (!dockerAvailable()) return 'docker daemon is not reachable from this environment'
	if (!IMAGE) {
		return 'FLUENTCART_ACCEPTANCE_IMAGE is not set; refusing to test an unrelated local image with the same version'
	}
	if (!imagePresent()) {
		return `image ${IMAGE} is not built; run scripts/build-validated-docker-image.mjs first`
	}
	return null
}

const blocked = blockedReason()
if (blocked && REQUIRED) {
	throw new Error(`required Docker acceptance cannot run: ${blocked}`)
}
let containerId = null
let port = null
let storeRuntime = null
let cleanupPromise = null

function storeArguments() {
	return [
		...(storeRuntime.url.includes('host.docker.internal')
			? ['--add-host', 'host.docker.internal:host-gateway']
			: []),
		'-e',
		`FLUENTCART_URL=${storeRuntime.url}`,
		'-e',
		'FLUENTCART_USERNAME=fixture',
		'-e',
		'FLUENTCART_APP_PASSWORD=fixture',
		'-e',
		'FLUENTCART_MCP_ALLOWED_HOSTS=127.0.0.1',
		'-e',
		'FLUENTCART_MCP_ALLOWED_ORIGINS=127.0.0.1',
	]
}

async function cleanup() {
	if (cleanupPromise) return cleanupPromise
	cleanupPromise = (async () => {
		let failure = null
		if (containerId) {
			try {
				removeDockerContainer(containerId, { runDocker: docker })
				containerId = null
			} catch (error) {
				failure = error
			}
		}
		try {
			await storeRuntime?.close()
			storeRuntime = null
		} catch (error) {
			failure ??= error
		}
		if (failure) throw failure
	})()
	try {
		return await cleanupPromise
	} catch (error) {
		cleanupPromise = null
		throw error
	}
}

const removeSignalCleanup = blocked ? () => undefined : installSignalCleanup(cleanup)

async function waitForPort(attempts = 40) {
	for (let attempt = 0; attempt < attempts; attempt += 1) {
		try {
			await fetch(`http://127.0.0.1:${port}/mcp`, { method: 'GET' })
			return true
		} catch {
			await new Promise((resolve) => setTimeout(resolve, 250))
		}
	}
	return false
}

async function waitForPublishedPort(attempts = 40) {
	let detail = ''
	for (let attempt = 0; attempt < attempts; attempt += 1) {
		const mapping = docker(['port', containerId, '3000/tcp'])
		detail = mapping.stderr || mapping.stdout
		const published = Number(mapping.stdout.trim().match(/:(\d+)$/)?.[1])
		if (mapping.status === 0 && published > 0) return published
		await new Promise((resolve) => setTimeout(resolve, 100))
	}
	throw new Error(`container did not publish a dynamic loopback port: ${detail}`)
}

before(async () => {
	if (blocked) return
	try {
		storeRuntime = await openCandidateStore(EXTERNAL_STORE_URL)
		const run = docker([
			'run',
			'--rm',
			'--detach',
			'--publish',
			'127.0.0.1::3000',
			...storeArguments(),
			'-e',
			`FLUENTCART_MCP_API_KEY=${STRONG_KEY}`,
			IMAGE,
		])
		assert.equal(run.status, 0, `container did not start: ${run.stderr}`)
		containerId = run.stdout.trim()
		port = await waitForPublishedPort()
		assert.ok(await waitForPort(), 'container never became reachable on its published port')
	} catch (error) {
		await cleanup()
		throw error
	}
})

after(async () => {
	removeSignalCleanup()
	await cleanup()
})

describe('docker image contract', () => {
	it('binds 0.0.0.0 explicitly in its command', (t) => {
		if (blocked) return t.skip(blocked)
		const config = inspectImage().Config
		const command = (config.Cmd ?? []).join(' ')
		assert.match(command, /--host\s+0\.0\.0\.0/, `image command was: ${command}`)
		assert.match(command, /--transport\s+http/)
	})

	it('carries the exact version and revision labels', (t) => {
		if (blocked) return t.skip(blocked)
		const labels = inspectImage().Config.Labels ?? {}
		assert.equal(labels['org.opencontainers.image.version'], VERSION)
		const revision = labels['org.opencontainers.image.revision'] ?? ''
		if (EXPECTED_SOURCE_SHA) {
			assert.match(EXPECTED_SOURCE_SHA, /^[0-9a-f]{40}$/, 'expected source SHA is malformed')
			assert.match(revision, /^[0-9a-f]{40}$/, 'revision label must be a full commit SHA')
			assert.equal(revision, EXPECTED_SOURCE_SHA, 'image revision is not the validated source SHA')
		} else {
			assert.equal(revision, '', 'an uncommitted local image must not claim a Git revision')
		}
		const content = labels['sh.vcode.fluentcart-mcp.candidate-content-digest'] ?? ''
		assert.match(content, /^sha256:[0-9a-f]{64}$/)
		if (EXPECTED_CONTENT_DIGEST) assert.equal(content, EXPECTED_CONTENT_DIGEST)
	})

	it('bakes no credential into the image', (t) => {
		if (blocked) return t.skip(blocked)
		const image = inspectImage()
		const env = image.Config.Env ?? []
		for (const entry of env) {
			assert.doesNotMatch(entry, /^FLUENTCART_/, `image environment ships ${entry.split('=')[0]}`)
		}

		// A key removed in a later layer is still readable in the build history.
		const history = docker(['history', '--no-trunc', '--format', '{{.CreatedBy}}', IMAGE])
		assert.doesNotMatch(
			history.stdout,
			/FLUENTCART_MCP_API_KEY=\S/,
			'a key appears in the build history',
		)
		assert.doesNotMatch(
			history.stdout,
			/FLUENTCART_APP_PASSWORD=\S/,
			'a password appears in the build history',
		)
	})
})

describe('docker startup policy', () => {
	/** Non-loopback binding without a usable key must abort, not serve. */
	function runWithKey(key) {
		const args = ['run', '--rm', ...storeArguments()]
		if (key !== null) args.push('-e', `FLUENTCART_MCP_API_KEY=${key}`)
		args.push(IMAGE)
		return docker(args, { timeout: 30_000 })
	}

	it('refuses to start with no API key at all', (t) => {
		if (blocked) return t.skip(blocked)
		const result = runWithKey(null)
		assert.notEqual(result.status, 0, 'container started while bound to 0.0.0.0 with no key')
		assert.match(result.stderr, /Private HTTP on 0\.0\.0\.0 requires/)
		assert.match(result.stderr, /FLUENTCART_MCP_API_KEY/)
	})

	it('refuses to start with a key shorter than 32 UTF-8 bytes', (t) => {
		if (blocked) return t.skip(blocked)
		const result = runWithKey('too-short-to-be-useful')
		assert.notEqual(result.status, 0, 'container started with a weak key')
		assert.match(result.stderr, /at least 32 UTF-8 bytes/)
	})
})

describe('docker authenticated endpoint', () => {
	async function request(headers) {
		const response = await fetch(`http://127.0.0.1:${port}/mcp`, { method: 'GET', headers })
		return { status: response.status, body: await response.text() }
	}

	it('returns exactly {"error":"Unauthorized"} for a missing key', async (t) => {
		if (blocked) return t.skip(blocked)
		const { status, body } = await request({})
		assert.equal(status, 401)
		assert.equal(body, '{"error":"Unauthorized"}')
	})

	it('returns exactly {"error":"Unauthorized"} for a wrong key', async (t) => {
		if (blocked) return t.skip(blocked)
		const { status, body } = await request({ authorization: `Bearer ${STRONG_KEY}-wrong` })
		assert.equal(status, 401)
		assert.equal(body, '{"error":"Unauthorized"}')
	})

	it('stops refusing once the correct key is presented', async (t) => {
		if (blocked) return t.skip(blocked)
		const { status, body } = await request({ authorization: `Bearer ${STRONG_KEY}` })
		assert.notEqual(status, 401, `authorised request was still rejected: ${body}`)
	})

	it('reports the published port as reachable from the host', (t) => {
		if (blocked) return t.skip(blocked)
		const ports = docker(['port', containerId, '3000'])
		assert.equal(ports.status, 0, ports.stderr)
		assert.match(ports.stdout, new RegExp(`127\\.0\\.0\\.1:${port}`))
	})

	it('completes deliberate legacy and modern discovery plus tools/list', (t) => {
		if (blocked) return t.skip(blocked)
		const result = spawnSync(
			process.execPath,
			[
				join(PACKAGE_ROOT, 'scripts', 'smoke-mcp-http.mjs'),
				`http://127.0.0.1:${port}/mcp`,
				STRONG_KEY,
			],
			{ encoding: 'utf8', timeout: 30_000 },
		)
		assert.equal(result.status, 0, result.stderr)
		assert.match(result.stdout, /MCP dual-era smoke succeeded \(2025-11-25, 2026-07-28/)
	})
})

if (blocked) {
	// Visible on stderr as well as in the skip messages: a container contract that silently
	// evaporates because nobody built the image is worse than no test at all.
	process.stderr.write(`docker acceptance skipped — ${blocked}\n`)
}

describe('docker image content', () => {
	// The npm launcher directory is excluded from the build context so the context validator can
	// keep refusing symlinks outright. Asserting on the image as well means the exclusion is
	// proved where it matters — in the artefact that ships — not only in the archive it came from.
	it('ships no npm launcher directory', (t) => {
		if (blocked) return t.skip(blocked)
		const result = docker([
			'run',
			'--rm',
			'--entrypoint',
			'sh',
			IMAGE,
			'-c',
			'ls -d /app/node_modules/.bin 2>/dev/null && echo PRESENT || echo ABSENT',
		])

		assert.equal(result.status, 0, `docker run failed: ${result.stderr}`)
		assert.match(result.stdout.trim(), /ABSENT/)
	})

	it('still has the package the launcher used to point at', (t) => {
		if (blocked) return t.skip(blocked)
		// Excluding .bin must not have removed the package itself; a context narrowed too far
		// would produce an image that only fails at run time.
		const result = docker([
			'run',
			'--rm',
			'--entrypoint',
			'sh',
			IMAGE,
			'-c',
			'test -d /app/node_modules && echo HAS_MODULES || echo NO_MODULES',
		])

		assert.match(result.stdout.trim(), /HAS_MODULES/)
	})

	it('runs the entry point the release contract names', (t) => {
		if (blocked) return t.skip(blocked)
		const result = docker([
			'run',
			'--rm',
			'--entrypoint',
			'node',
			IMAGE,
			'dist/index.js',
			'--version',
		])
		assert.equal(result.status, 0, `--version failed: ${result.stderr}`)
		assert.match(result.stdout.trim(), new RegExp(VERSION.replace(/\./g, '\\.')))
	})
})
