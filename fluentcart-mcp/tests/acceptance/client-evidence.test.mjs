import assert from 'node:assert/strict'
import {
	chmodSync,
	existsSync,
	mkdirSync,
	mkdtempSync,
	readdirSync,
	readFileSync,
	rmSync,
	symlinkSync,
	writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { after, before, describe, it } from 'node:test'
import { LANES } from '../../scripts/acceptance/lanes.mjs'
import { CandidateStore } from '../../scripts/candidate-store.mjs'
import {
	CLIENT_CELLS,
	certificationState,
	certifyClients,
	validateClientEvidence,
	validateCurrentClientEvidence,
	versionCommandFor,
} from '../../scripts/certify-clients.mjs'
import { ClientAdapters } from '../../scripts/client-adapters.mjs'

const SHA = '0123456789abcdef0123456789abcdef01234567'
const BASE_SHA = '89abcdef0123456789abcdef0123456789abcdef'
const IMAGE_ID = `sha256:${'a'.repeat(64)}`
const IMAGE_DIGEST = `sha256:${'b'.repeat(64)}`
const CONTENT_DIGEST = `sha256:${'c'.repeat(64)}`
const NOW = '2026-07-28T12:00:00.000Z'
const VERSIONS = {
	'MCP Inspector': '2.0.0',
	'Claude Code': '2.1.15 (Claude Code)',
	'Claude Desktop': '1.2.3',
	Cursor: 'cursor 1.4.2',
	'Docker smoke': '2.0.0',
}

let outputRoot
let runDirectory
let configRoot

function candidate(overrides = {}) {
	return {
		image: 'registry.invalid/fluentcart-mcp:2.0.0',
		imageId: IMAGE_ID,
		imageDigest: IMAGE_DIGEST,
		candidateContentDigest: CONTENT_DIGEST,
		baseCommitSha: BASE_SHA,
		sourceSha: SHA,
		sourceShaKind: 'committed-ci',
		...overrides,
	}
}

function capabilitySource(client) {
	if (client === 'MCP Inspector') {
		return 'https://github.com/modelcontextprotocol/inspector'
	}
	if (client === 'Cursor') {
		return 'https://docs.cursor.com/context/model-context-protocol'
	}
	return 'https://docs.anthropic.com/en/docs/agents-and-tools/mcp'
}

function entry(
	{ client, transport },
	outcome = ['Claude Desktop', 'Cursor'].includes(client) ? 'CONFIGURATION_TARGET' : 'PASS',
) {
	const configurationRoot = join(configRoot, client.toLowerCase().replaceAll(' ', '-'))
	const target = outcome === 'CONFIGURATION_TARGET'
	return {
		client,
		version: outcome === 'BLOCKED' ? null : VERSIONS[client],
		versionCommand: versionCommandFor(client, candidate()),
		transport,
		configurationRoot,
		evidenceTime: NOW,
		outcome,
		reason: outcome === 'PASS' || target ? null : `${client}/${transport} fixture reason`,
		prerequisite: target ? 'Install the client and apply its documented configuration.' : null,
		capabilitySource: outcome === 'UNSUPPORTED' || target ? capabilitySource(client) : null,
		observedHandshake:
			outcome === 'PASS' ? { protocolVersion: '2026-07-28', observedAt: NOW } : null,
	}
}

function evidence(overrides = {}) {
	return {
		schemaVersion: 3,
		producer: 'scripts/certify-clients.mjs',
		producedAt: NOW,
		runRoot: configRoot,
		candidate: candidate(),
		clients: CLIENT_CELLS.map((cell) => entry(cell)),
		...overrides,
	}
}

function context(overrides = {}) {
	return {
		runDirectory,
		now: NOW,
		candidate: candidate(),
		currentVersions: VERSIONS,
		...overrides,
	}
}

async function withFakeClientBinary(name, body, run) {
	const bin = mkdtempSync(join(outputRoot, `fcmcp-fake-${name}-`))
	const executable = join(bin, name)
	writeFileSync(executable, `#!${process.execPath}\n${body}`)
	chmodSync(executable, 0o700)
	const previousPath = process.env.PATH
	process.env.PATH = `${bin}:${previousPath}`
	try {
		return await run()
	} finally {
		process.env.PATH = previousPath
		rmSync(bin, { recursive: true, force: true })
	}
}

function adapterForStdio() {
	const adapter = Object.create(ClientAdapters.prototype)
	adapter.candidate = candidate()
	adapter.store = { url: 'http://host.docker.internal:43210' }
	return adapter
}

before(() => {
	outputRoot = mkdtempSync(join(tmpdir(), 'fcmcp-client-evidence-'))
	runDirectory = join(outputRoot, SHA)
	configRoot = join(runDirectory, 'client-config')
	mkdirSync(configRoot, { recursive: true })
	for (const client of Object.keys(VERSIONS)) {
		mkdirSync(join(configRoot, client.toLowerCase().replaceAll(' ', '-')), {
			recursive: true,
		})
	}
})

after(() => {
	rmSync(outputRoot, { recursive: true, force: true })
})

describe('named-client acceptance lane', () => {
	it('runs the producer before the evidence gate and never edits user configuration', () => {
		assert.deepEqual(
			LANES.clients.steps.map(({ id }) => id),
			['candidate-preflight', 'certify-clients', 'client-evidence'],
		)
		assert.equal(LANES.clients.steps[0].file, 'scripts/verify-acceptance-candidate.mjs')
		assert.equal(LANES.clients.steps[1].file, 'scripts/certify-clients.mjs')
		assert.equal(LANES.clients.steps[2].reporter, 'node-test')
		assert.ok(LANES.clients.steps[2].proves.includes('certifies named client matrix'))
	})
})

describe('producer-owned named-client evidence', () => {
	it('accepts five candidate-bound handshakes and four documented configuration targets', () => {
		assert.equal(CLIENT_CELLS.length, 9)
		assert.equal(certificationState(validateClientEvidence(evidence(), context())), 'PASS')
	})

	it('rejects fake versions, future times, and nonexistent configuration roots', () => {
		const fakeVersion = evidence()
		fakeVersion.clients[0].version = '9.9.9 totally genuine'
		assert.throws(() => validateClientEvidence(fakeVersion, context()), /current command output/)

		const future = evidence()
		future.clients[0].evidenceTime = '2026-07-28T12:10:00.000Z'
		assert.throws(() => validateClientEvidence(future, context()), /future/)

		const missingRoot = evidence()
		missingRoot.clients[0].configurationRoot = join(configRoot, 'does-not-exist')
		assert.throws(() => validateClientEvidence(missingRoot, context()), /does not exist/)
	})

	it('rejects arbitrary candidate identity and an image digest mismatch', () => {
		const arbitrary = evidence({
			candidate: candidate({ candidateContentDigest: `sha256:${'d'.repeat(64)}` }),
		})
		assert.throws(() => validateClientEvidence(arbitrary, context()), /candidate identity/)

		const mismatch = evidence({
			candidate: candidate({ imageDigest: `sha256:${'e'.repeat(64)}` }),
		})
		assert.throws(() => validateClientEvidence(mismatch, context()), /candidate identity/)
	})

	it('rejects a tenth cell instead of ignoring it', () => {
		const extra = evidence()
		extra.clients.push({
			...entry(CLIENT_CELLS[0]),
			client: 'MCP Inspector',
			transport: 'docker-http',
		})
		assert.throws(() => validateClientEvidence(extra, context()), /exactly 9/)
	})

	it('keeps unavailable and officially unsupported cells out of PASS', () => {
		const honest = evidence()
		honest.clients = honest.clients.map((cell, index) => {
			if (index === 0) return entry(CLIENT_CELLS[index], 'BLOCKED')
			if (index === 1) return entry(CLIENT_CELLS[index], 'UNSUPPORTED')
			return cell
		})
		assert.equal(certificationState(validateClientEvidence(honest, context())), 'BLOCKED')
		honest.clients[1].capabilitySource = 'https://example.com/not-official'
		assert.throws(() => validateClientEvidence(honest, context()), /official capability source/)
	})

	it('certifies the five automated handshakes even when manual configuration targets are recorded', () => {
		const recordedTargets = evidence()
		for (const cell of recordedTargets.clients.filter(({ client }) =>
			['Claude Desktop', 'Cursor'].includes(client),
		)) {
			cell.outcome = 'CONFIGURATION_TARGET'
			cell.reason = null
			cell.prerequisite =
				'Install the client and apply the documented configuration in its own profile.'
			cell.capabilitySource = capabilitySource(cell.client)
			cell.observedHandshake = null
		}

		assert.equal(certificationState(validateClientEvidence(recordedTargets, context())), 'PASS')
	})

	it('overwrites fabricated input and creates its own run roots and observations', async () => {
		const producerRun = join(outputRoot, 'producer-run')
		mkdirSync(producerRun)
		writeFileSync(
			join(producerRun, 'named-clients.json'),
			JSON.stringify({ fabricated: true, outcome: 'PASS' }),
		)
		const produced = await certifyClients(
			{ runDirectory: producerRun, now: NOW },
			{
				resolveCandidate: () => candidate(),
				captureVersions: () => VERSIONS,
				observeCell: async (cell) =>
					cell.client === 'MCP Inspector'
						? { outcome: 'PASS', protocolVersion: '2026-07-28' }
						: { outcome: 'BLOCKED', reason: 'test client unavailable' },
			},
		)
		assert.equal(produced.fabricated, undefined)
		assert.equal(produced.clients.length, 9)
		assert.ok(produced.clients.every(({ configurationRoot }) => existsSync(configurationRoot)))
		assert.equal(certificationState(produced), 'BLOCKED')
		assert.deepEqual(
			JSON.parse(readFileSync(join(producerRun, 'named-clients.json'), 'utf8')),
			produced,
		)
	})

	it('rejects a symlinked client-config root before resolving or launching anything', async () => {
		const producerRun = join(outputRoot, 'symlinked-run-root')
		const outside = join(outputRoot, 'outside-run-root')
		mkdirSync(producerRun)
		mkdirSync(outside)
		symlinkSync(outside, join(producerRun, 'client-config'))
		let calls = 0
		await assert.rejects(
			() =>
				certifyClients(
					{ runDirectory: producerRun, now: NOW },
					{
						resolveCandidate: () => {
							calls += 1
							return candidate()
						},
						captureVersions: () => VERSIONS,
						observeCell: async () => ({ outcome: 'PASS', protocolVersion: '2026-07-28' }),
					},
				),
			/client-config.*symlink/,
		)
		assert.equal(calls, 0)
		assert.deepEqual(readdirSync(outside), [])
	})

	it('rejects a symlinked individual client root before resolving or launching anything', async () => {
		const producerRun = join(outputRoot, 'symlinked-client-root')
		const runRoot = join(producerRun, 'client-config')
		const outside = join(outputRoot, 'outside-client-root')
		mkdirSync(runRoot, { recursive: true })
		mkdirSync(outside)
		symlinkSync(outside, join(runRoot, 'mcp-inspector'))
		let calls = 0
		await assert.rejects(
			() =>
				certifyClients(
					{ runDirectory: producerRun, now: NOW },
					{
						resolveCandidate: () => {
							calls += 1
							return candidate()
						},
						captureVersions: () => VERSIONS,
						observeCell: async () => ({ outcome: 'PASS', protocolVersion: '2026-07-28' }),
					},
				),
			/mcp-inspector.*symlink/,
		)
		assert.equal(calls, 0)
		assert.deepEqual(readdirSync(outside), [])
	})
})

describe('named-client adapter routing', () => {
	it('forwards the candidate image and store through an Inspector v2 server config', async () => {
		const root = join(configRoot, 'mcp-inspector')
		const receipt = join(root, 'stdio-receipt.json')
		await withFakeClientBinary(
			'npx',
			`
const { readFileSync, writeFileSync } = require('node:fs')
const args = process.argv.slice(2)
const configIndex = args.indexOf('--config')
const serverIndex = args.indexOf('--server')
if (configIndex < 0 || serverIndex < 0) process.exit(41)
const config = JSON.parse(readFileSync(args[configIndex + 1], 'utf8'))
const server = config.mcpServers[args[serverIndex + 1]]
if (!server || server.env.FCMCP_CLIENT_IMAGE_ID !== process.env.FCMCP_CLIENT_IMAGE_ID || server.env.FCMCP_CLIENT_STORE_URL !== process.env.FCMCP_CLIENT_STORE_URL) process.exit(42)
writeFileSync(server.args[1], JSON.stringify({ protocolVersion: '2026-07-28', observedAt: '${NOW}', candidateImageId: process.env.FCMCP_CLIENT_IMAGE_ID }))
`,
			async () => {
				const observed = await adapterForStdio().stdio({
					client: 'MCP Inspector',
					transport: 'stdio',
					configurationRoot: root,
				})
				assert.deepEqual(observed, { outcome: 'PASS', protocolVersion: '2026-07-28' })
				assert.ok(existsSync(receipt))
			},
		)
	})

	it('keeps the candidate store responsive while Inspector starts its bridge child', async () => {
		const root = join(configRoot, 'mcp-inspector-live-store')
		const receipt = join(root, 'stdio-receipt.json')
		const store = new CandidateStore()
		mkdirSync(root, { recursive: true })
		await store.start()
		const adapter = Object.create(ClientAdapters.prototype)
		adapter.candidate = candidate()
		adapter.store = store
		try {
			await withFakeClientBinary(
				'npx',
				`
const { readFileSync, writeFileSync } = require('node:fs')
const args = process.argv.slice(2)
const config = JSON.parse(readFileSync(args[args.indexOf('--config') + 1], 'utf8'))
const server = config.mcpServers[args[args.indexOf('--server') + 1]]
const deadline = setTimeout(() => process.exit(75), 350)
fetch(process.env.FCMCP_CLIENT_STORE_URL.replace('host.docker.internal', '127.0.0.1') + '/wp-json/fluent-cart/v2')
  .then((response) => {
    if (!response.ok) process.exit(76)
    writeFileSync(server.args[1], JSON.stringify({ protocolVersion: '2026-07-28', observedAt: '${NOW}', candidateImageId: process.env.FCMCP_CLIENT_IMAGE_ID }))
    clearTimeout(deadline)
  })
  .catch(() => process.exit(77))
`,
				async () => {
					const observed = await adapter.stdio({
						client: 'MCP Inspector',
						transport: 'stdio',
						configurationRoot: root,
					})
					assert.deepEqual(observed, { outcome: 'PASS', protocolVersion: '2026-07-28' })
					assert.ok(existsSync(receipt))
				},
			)
		} finally {
			await store.close()
		}
	})

	it('registers and checks Claude Code through its isolated persistent configuration', async () => {
		const root = join(configRoot, 'claude-code')
		const receipt = join(root, 'stdio-receipt.json')
		await withFakeClientBinary(
			'claude',
			`
const { existsSync, readFileSync, writeFileSync } = require('node:fs')
const { join } = require('node:path')
const args = process.argv.slice(2)
const statePath = join(process.env.HOME, 'fake-claude-state.json')
if (args[0] !== 'mcp') process.exit(51)
if (args[1] === 'add') {
  const marker = args.indexOf('--')
  const name = args.indexOf('fluentcartCandidate')
  const image = args.indexOf('FCMCP_CLIENT_IMAGE_ID=${IMAGE_ID}')
  const store = args.indexOf('FCMCP_CLIENT_STORE_URL=http://host.docker.internal:43210')
  if (marker < 0 || name < 0 || image < 0 || store < 0 || !(name < image && image < store && store < marker) || args[image - 1] !== '--env' || args[store - 1] !== '--env' || !args.includes('--scope') || !args.includes('user') || !args.includes('--transport') || !args.includes('stdio')) process.exit(52)
  writeFileSync(statePath, JSON.stringify({ receipt: args.at(-1), image: '${IMAGE_ID}' }))
  process.exit(0)
}
if (!existsSync(statePath)) process.exit(53)
const state = JSON.parse(readFileSync(statePath, 'utf8'))
if (args[1] === 'list') process.exit(0)
if (args[1] === 'get' && args[2] === 'fluentcartCandidate') {
  writeFileSync(state.receipt, JSON.stringify({ protocolVersion: '2026-07-28', observedAt: '${NOW}', candidateImageId: state.image }))
  process.exit(0)
}
process.exit(54)
`,
			async () => {
				const observed = await adapterForStdio().stdio({
					client: 'Claude Code',
					transport: 'stdio',
					configurationRoot: root,
				})
				assert.deepEqual(observed, { outcome: 'PASS', protocolVersion: '2026-07-28' })
				assert.ok(existsSync(receipt))
			},
		)
	})

	it('registers Claude Code HTTP without colliding with its stdio candidate', async () => {
		const root = join(configRoot, 'claude-code-dual-transport')
		mkdirSync(root, { recursive: true })
		await withFakeClientBinary(
			'claude',
			`
const { existsSync, readFileSync, writeFileSync } = require('node:fs')
const { join } = require('node:path')
const args = process.argv.slice(2)
const statePath = join(process.env.HOME, 'fake-claude-dual-transport.json')
if (args[0] !== 'mcp') process.exit(61)
if (args[1] === 'remove') {
  const state = existsSync(statePath) ? JSON.parse(readFileSync(statePath, 'utf8')) : { servers: {} }
  delete state.servers[args.at(-1)]
  writeFileSync(statePath, JSON.stringify(state))
  process.exit(0)
}
if (args[1] === 'add') {
  const transport = args[args.indexOf('--transport') + 1]
  const name = args[args.indexOf('--transport') + 2]
  const state = existsSync(statePath) ? JSON.parse(readFileSync(statePath, 'utf8')) : { servers: {} }
  if (state.servers[name]) process.exit(62)
  state.servers[name] = transport
  writeFileSync(statePath, JSON.stringify(state))
  process.exit(0)
}
if (!existsSync(statePath)) process.exit(63)
if (args[1] === 'list' || args[1] === 'get') process.exit(0)
process.exit(64)
`,
			async () => {
				const adapter = adapterForStdio()
				const env = {
					PATH: process.env.PATH,
					HOME: root,
					FCMCP_CLIENT_IMAGE_ID: IMAGE_ID,
					FCMCP_CLIENT_STORE_URL: 'http://host.docker.internal:43210',
				}
				await adapter.claudeStdio(env, join(root, 'stdio-receipt.json'))
				const http = await adapter.claudeHttp(env, 'http://127.0.0.1:12345/mcp')
				assert.equal(http.ok, true)
				const repeatedHttp = await adapter.claudeHttp(env, 'http://127.0.0.1:12345/mcp')
				assert.equal(repeatedHttp.ok, true)
				const servers = JSON.parse(
					readFileSync(join(root, 'fake-claude-dual-transport.json'), 'utf8'),
				).servers
				assert.equal(Object.keys(servers).length, 2)
				assert.deepEqual(Object.values(servers).sort(), ['http', 'stdio'])
			},
		)
	})

	it('routes every supported local cell to an executable adapter', async () => {
		const adapters = Object.create(ClientAdapters.prototype)
		adapters.stdio = async (cell) => ({ outcome: 'PASS', protocolVersion: `stdio:${cell.client}` })
		adapters.http = async (cell) => ({ outcome: 'PASS', protocolVersion: `http:${cell.client}` })
		const routed = await Promise.all(CLIENT_CELLS.map((cell) => adapters.observe(cell)))
		for (const [index, cell] of CLIENT_CELLS.entries()) {
			if (['Claude Desktop', 'Cursor'].includes(cell.client)) {
				assert.equal(routed[index].outcome, 'CONFIGURATION_TARGET')
				assert.equal(routed[index].observedHandshake, undefined)
			} else {
				assert.equal(routed[index].outcome, 'PASS', `${cell.client}/${cell.transport}`)
			}
		}
	})
})

const currentRunDirectory = process.env.FLUENTCART_ACCEPTANCE_RUN_DIR
const evidencePath = currentRunDirectory ? join(currentRunDirectory, 'named-clients.json') : null

if (!(evidencePath && existsSync(evidencePath))) {
	it('certifies named client matrix (BLOCKED: run-owned producer evidence is unavailable)', {
		skip: true,
	}, () => {
		// The release run must generate this evidence before the gate can claim support.
	})
} else {
	const current = validateCurrentClientEvidence(JSON.parse(readFileSync(evidencePath, 'utf8')), {
		runDirectory: currentRunDirectory,
	})
	const state = certificationState(current)
	if (state === 'BLOCKED') {
		it('certifies named client matrix (BLOCKED: producer recorded unavailable cells)', {
			skip: true,
		}, () => {
			// The producer preserves the real blocked state instead of upgrading it to a pass.
		})
	} else {
		it('certifies named client matrix', () => {
			assert.equal(
				state,
				current.clients.some(({ outcome }) => outcome === 'UNSUPPORTED')
					? 'PASS_WITH_EXCLUSIONS'
					: 'PASS',
			)
		})
	}
	for (const cell of current.clients.filter(({ outcome }) => outcome === 'UNSUPPORTED')) {
		it(`${cell.client}/${cell.transport} (BLOCKED: ${cell.capabilitySource})`, {
			skip: true,
		}, () => {
			// Officially unsupported cells remain named exclusions in the release report.
		})
	}
}
