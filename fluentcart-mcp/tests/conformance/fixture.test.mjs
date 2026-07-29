import assert from 'node:assert/strict'
import { mkdtemp, readFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { dirname, join, relative, resolve } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'
import { Client, InMemoryTransport } from '@modelcontextprotocol/client'
import {
	buildConformanceArgs,
	CONFORMANCE_MATRIX,
	runConformanceMatrix,
} from '../../scripts/run-conformance.mjs'
import {
	createConformanceFactory,
	DIAGNOSTIC_SURFACE,
	startConformanceFixture,
} from '../../scripts/serve-conformance-fixture.mjs'

const PACKAGE_ROOT = dirname(dirname(dirname(fileURLToPath(import.meta.url))))
const REPO_ROOT = dirname(PACKAGE_ROOT)
const EXPECTED_MATRIX = [
	['server-initialize', '2025-11-25'],
	['server-stateless', '2026-07-28'],
	['ping', '2025-11-25'],
	['tools-list', '2025-11-25'],
	['tools-list', '2026-07-28'],
	['tools-call-simple-text', '2025-11-25'],
	['tools-call-simple-text', '2026-07-28'],
	['json-schema-2020-12', '2025-11-25'],
	['json-schema-2020-12', '2026-07-28'],
	['resources-list', '2025-11-25'],
	['resources-list', '2026-07-28'],
	['resources-read-text', '2025-11-25'],
	['resources-read-text', '2026-07-28'],
	['prompts-list', '2025-11-25'],
	['prompts-list', '2026-07-28'],
	['prompts-get-simple', '2025-11-25'],
	['prompts-get-simple', '2026-07-28'],
	['prompts-get-with-args', '2025-11-25'],
	['prompts-get-with-args', '2026-07-28'],
	['dns-rebinding-protection', '2025-11-25'],
	['dns-rebinding-protection', '2026-07-28'],
	['http-header-validation', '2026-07-28'],
]

function relativeImports(source) {
	const imports = []
	const patterns = [
		/(?:import|export)\s+(?:[^'"]*?\s+from\s+)?['"](\.[^'"]+)['"]/g,
		/import\(\s*['"](\.[^'"]+)['"]\s*\)/g,
	]
	for (const pattern of patterns) {
		for (const match of source.matchAll(pattern)) imports.push(match[1])
	}
	return imports
}

async function reachableLocalModules(entryPath) {
	const pending = [entryPath]
	const visited = new Set()
	while (pending.length > 0) {
		const path = pending.pop()
		if (!path || visited.has(path)) continue
		visited.add(path)
		const source = await readFile(path, 'utf8')
		for (const specifier of relativeImports(source)) {
			pending.push(resolve(dirname(path), specifier))
		}
	}
	return [...visited].map((path) => relative(PACKAGE_ROOT, path))
}

async function fixtureSurface() {
	const server = await createConformanceFactory()({ era: 'legacy' })
	const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair()
	const client = new Client({ name: 'fixture-test', version: '1.0.0' }, { capabilities: {} })
	await Promise.all([server.connect(serverTransport), client.connect(clientTransport)])
	try {
		return {
			tools: (await client.listTools()).tools.map(({ name }) => name).sort(),
			resources: (await client.listResources()).resources.map(({ uri }) => uri).sort(),
			prompts: (await client.listPrompts()).prompts.map(({ name }) => name).sort(),
		}
	} finally {
		await client.close()
		await server.close()
	}
}

test('the fixture module graph cannot reach the production server, registry, or credentials', async () => {
	const modules = await reachableLocalModules(
		join(PACKAGE_ROOT, 'scripts', 'serve-conformance-fixture.mjs'),
	)
	const forbidden = modules.filter(
		(path) =>
			path.startsWith('dist/server') ||
			path.startsWith('dist/tools/') ||
			path.startsWith('dist/config/') ||
			path.startsWith('dist/abilities/') ||
			path.startsWith('dist/api/') ||
			path.startsWith('dist/commerce/') ||
			path === 'dist/prompts.js' ||
			path === 'dist/resources.js',
	)
	assert.deepEqual(forbidden, [])
	for (const path of modules) {
		assert.doesNotMatch(
			await readFile(join(PACKAGE_ROOT, path), 'utf8'),
			/FLUENTCART_(?:URL|USERNAME|APP_PASSWORD|ABILITIES_USERNAME|ABILITIES_APP_PASSWORD)/,
			`${path} reads a production store credential`,
		)
	}
})

test('the CI conformance command runs both local contracts before the official matrix', async () => {
	const packageJson = JSON.parse(await readFile(join(PACKAGE_ROOT, 'package.json'), 'utf8'))
	assert.equal(
		packageJson.scripts['test:conformance:contracts'],
		'node --test tests/conformance/fixture.test.mjs tests/conformance/package-boundary.test.mjs',
	)
	assert.equal(
		packageJson.scripts['test:conformance'],
		'npm run build && npm run test:conformance:contracts && node scripts/run-conformance.mjs',
	)
	const workflow = await readFile(join(REPO_ROOT, '.github', 'workflows', 'mcp-ci.yml'), 'utf8')
	assert.match(workflow, /run: npm run test:conformance(?:\r?\n|$)/)
})

test('the synthetic factory exposes only the selected official scenario fixtures', async () => {
	assert.deepEqual(DIAGNOSTIC_SURFACE, {
		tools: [
			'json_schema_2020_12_tool',
			'test_logging_tool',
			'test_missing_capability',
			'test_simple_text',
			'test_streaming_elicitation',
		],
		resources: ['test://static-text'],
		prompts: ['test_prompt_with_arguments', 'test_simple_prompt'],
	})
	assert.deepEqual(await fixtureSurface(), DIAGNOSTIC_SURFACE)
})

test('the fixture starts through production HTTP composition and closes through its service handle', async () => {
	const handle = await startConformanceFixture()
	try {
		const health = await fetch(`${handle.url}/health`)
		assert.equal(health.status, 200)
		assert.deepEqual(await health.json(), { status: 'ok' })
	} finally {
		await handle.close()
	}
	await assert.rejects(fetch(`${handle.url}/health`))
})

test('the modern missing-capability diagnostic is rejected before execution with HTTP 400', async () => {
	const handle = await startConformanceFixture()
	try {
		const meta = {
			'io.modelcontextprotocol/protocolVersion': '2026-07-28',
			'io.modelcontextprotocol/clientInfo': { name: 'fixture-test', version: '1.0.0' },
			'io.modelcontextprotocol/clientCapabilities': {},
		}
		const response = await fetch(`${handle.url}/mcp`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
				'MCP-Protocol-Version': '2026-07-28',
				'Mcp-Method': 'tools/call',
				'Mcp-Name': 'test_missing_capability',
			},
			body: JSON.stringify({
				jsonrpc: '2.0',
				id: 401,
				method: 'tools/call',
				params: { name: 'test_missing_capability', arguments: {}, _meta: meta },
			}),
		})
		assert.equal(response.status, 400)
		const body = await response.json()
		assert.equal(body.error.code, -32021)
		assert.match(body.error.message, /required capability/i)
		assert.deepEqual(body.error.data, { requiredCapabilities: { sampling: {} } })
	} finally {
		await handle.close()
	}
})

test('the runner defines exactly 22 explicit scenario/version invocations without suite flags', () => {
	assert.deepEqual(CONFORMANCE_MATRIX, EXPECTED_MATRIX)
	for (const [scenario, version] of CONFORMANCE_MATRIX) {
		const args = buildConformanceArgs('http://127.0.0.1:4000/mcp', scenario, version, '/tmp/checks')
		assert.deepEqual(args, [
			'server',
			'--url',
			'http://127.0.0.1:4000/mcp',
			'--scenario',
			scenario,
			'--spec-version',
			version,
			'--output-dir',
			'/tmp/checks',
		])
		assert.equal(
			args.some((argument) => argument === '--suite'),
			false,
		)
		assert.equal(
			args.some((argument) => argument === '--expected-failures'),
			false,
		)
	}
})

test('the runner rejects warnings, writes failure-only metadata, and still runs the full matrix', async () => {
	const root = await mkdtemp(join(tmpdir(), 'fluentcart-conformance-test-'))
	const artifactPath = join(root, 'failure.json')
	const invocations = []
	try {
		await assert.rejects(
			runConformanceMatrix({
				mcpUrl: 'http://127.0.0.1:4000/mcp',
				resultsRoot: join(root, 'raw'),
				artifactPath,
				execute: async (_binary, args, outputDir) => {
					invocations.push(args)
					return {
						exitCode: 0,
						checks: [
							{
								id: 'fixture-check',
								name: 'FixtureCheck',
								description: 'Synthetic check',
								status: invocations.length === 2 ? 'WARNING' : 'SUCCESS',
								timestamp: 'not-preserved',
								details: { payload: 'not-preserved' },
							},
						],
						outputDir,
					}
				},
			}),
			/warning or failure checks/,
		)
		assert.equal(invocations.length, 22)
		const artifact = JSON.parse(await readFile(artifactPath, 'utf8'))
		assert.equal(artifact.runs.length, 22)
		assert.deepEqual(artifact.runs[1].checks, [
			{
				id: 'fixture-check',
				name: 'FixtureCheck',
				description: 'Synthetic check',
				status: 'WARNING',
			},
		])
		assert.equal(JSON.stringify(artifact).includes('not-preserved'), false)
	} finally {
		await rm(root, { recursive: true, force: true })
	}
})

test('the runner leaves no metadata artifact after a clean matrix', async () => {
	const root = await mkdtemp(join(tmpdir(), 'fluentcart-conformance-clean-'))
	const artifactPath = join(root, 'failure.json')
	try {
		const summary = await runConformanceMatrix({
			mcpUrl: 'http://127.0.0.1:4000/mcp',
			resultsRoot: join(root, 'raw'),
			artifactPath,
			execute: async (_binary, _args, outputDir) => ({
				exitCode: 0,
				checks: [
					{
						id: 'fixture-check',
						name: 'FixtureCheck',
						description: 'Synthetic check',
						status: 'SUCCESS',
					},
				],
				outputDir,
			}),
		})
		assert.deepEqual(summary, { runs: 22, failures: 0, warnings: 0 })
		await assert.rejects(readFile(artifactPath), { code: 'ENOENT' })
	} finally {
		await rm(root, { recursive: true, force: true })
	}
})
