import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import {
	cpSync,
	mkdirSync,
	mkdtempSync,
	readFileSync,
	rmSync,
	symlinkSync,
	writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { gunzipSync } from 'node:zlib'
import { Client, InMemoryTransport } from '@modelcontextprotocol/client'
import {
	compileReleaseDist,
	packMcpb,
	RUNTIME_METADATA,
	stageRuntimeTree,
} from '../../scripts/build-mcpb.mjs'
import { readCentralDirectory, readEntry } from '../../scripts/inspect-mcpb.mjs'
import { readTar } from '../../scripts/inspect-npm-pack.mjs'

const PACKAGE_ROOT = dirname(dirname(dirname(fileURLToPath(import.meta.url))))
const DIAGNOSTIC_SURFACE = {
	tools: [
		'json_schema_2020_12_tool',
		'test_logging_tool',
		'test_missing_capability',
		'test_simple_text',
		'test_streaming_elicitation',
	],
	resources: ['test://static-text'],
	prompts: ['test_prompt_with_arguments', 'test_simple_prompt'],
}
const DIAGNOSTIC_NAMES = Object.values(DIAGNOSTIC_SURFACE).flat()
const MODES = ['dynamic', 'curated', 'code', 'full']

function diagnosticLeaks(entries) {
	const leaks = []
	for (const entry of entries) {
		const path = entry.path ?? ''
		const content = Buffer.isBuffer(entry.content)
			? entry.content.toString('utf8')
			: String(entry.content ?? '')
		for (const name of DIAGNOSTIC_NAMES) {
			if (path.includes(name) || content.includes(name)) leaks.push(`${entry.path}: ${name}`)
		}
	}
	return leaks
}

function assertDiagnosticSurfaceAbsent(label, entries) {
	assert.deepEqual(diagnosticLeaks(entries), [], `${label} contains the conformance surface`)
}

function createFreshPackage(root) {
	const packageRoot = join(root, 'fresh-package')
	mkdirSync(packageRoot, { recursive: true })
	for (const name of RUNTIME_METADATA) {
		cpSync(join(PACKAGE_ROOT, name), join(packageRoot, name))
	}
	for (const relative of ['openai-plugin/.codex-plugin/plugin.json', 'openai-plugin/.mcp.json']) {
		const destination = join(packageRoot, relative)
		mkdirSync(dirname(destination), { recursive: true })
		cpSync(join(PACKAGE_ROOT, relative), destination)
	}
	symlinkSync(join(PACKAGE_ROOT, 'node_modules'), join(packageRoot, 'node_modules'), 'junction')
	const dist = compileReleaseDist(join(packageRoot, 'dist'))
	return { packageRoot, dist }
}

async function productionSurface(dist, mode) {
	const moduleUrl = pathToFileURL(join(dist, 'server.js'))
	moduleUrl.searchParams.set('boundary', `${mode}-${Date.now()}`)
	const { createServerFromContextAsync, resolveServerContext } = await import(moduleUrl.href)
	const context = resolveServerContext()
	const server = await createServerFromContextAsync(context, mode)
	const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair()
	const client = new Client({ name: `boundary-${mode}`, version: '1.0.0' }, { capabilities: {} })
	await Promise.all([server.connect(serverTransport), client.connect(clientTransport)])
	try {
		return {
			tools: (await client.listTools()).tools.map(({ name }) => name),
			resources: (await client.listResources()).resources.map(({ uri }) => uri),
			prompts: (await client.listPrompts()).prompts.map(({ name }) => name),
		}
	} finally {
		await client.close()
		await server.close()
	}
}

function packFreshNpmArchive(root, packageRoot) {
	const output = join(root, 'npm-output')
	mkdirSync(output)
	const result = JSON.parse(
		execFileSync('npm', ['pack', '--json', '--ignore-scripts', '--pack-destination', output], {
			cwd: packageRoot,
			encoding: 'utf8',
		}),
	)
	return join(output, result[0].filename)
}

function npmArchiveEntries(archivePath) {
	return readTar(gunzipSync(readFileSync(archivePath)))
		.filter(({ typeFlag }) => typeFlag !== '5')
		.map(({ name, data }) => ({
			path: name.startsWith('package/') ? name.slice('package/'.length) : name,
			content: data,
		}))
}

function packFreshMcpb(root, dist) {
	const stagingDir = stageRuntimeTree({
		stagingDir: join(root, 'mcpb-stage'),
		releaseDist: dist,
	})
	return packMcpb({ stagingDir, outputPath: join(root, 'fresh.mcpb') })
}

function mcpbArchiveEntries(archivePath) {
	const buffer = readFileSync(archivePath)
	return readCentralDirectory(buffer)
		.filter(({ name }) => !(name.endsWith('/') || name.startsWith('node_modules/')))
		.map((entry) => ({ path: entry.name, content: readEntry(buffer, entry) }))
}

test('fresh production tools, resources, and prompts exclude every diagnostic in every mode', async () => {
	const root = mkdtempSync(join(tmpdir(), 'fluentcart-conformance-surface-'))
	const original = { ...process.env }
	Object.assign(process.env, {
		FLUENTCART_URL: 'https://fixture.invalid',
		FLUENTCART_USERNAME: 'fixture',
		FLUENTCART_APP_PASSWORD: 'fixture',
		FLUENTCART_WRITE_MODE: 'disabled',
		FLUENTCART_ABILITIES_MODE: 'disabled',
	})
	try {
		const { dist } = createFreshPackage(root)
		for (const mode of MODES) {
			const surface = await productionSurface(dist, mode)
			for (const kind of Object.keys(DIAGNOSTIC_SURFACE)) {
				assertDiagnosticSurfaceAbsent(`${mode} ${kind}`, [
					{ path: `${mode}/${kind}`, content: JSON.stringify(surface[kind]) },
				])
			}
		}
	} finally {
		process.env = original
		rmSync(root, { recursive: true, force: true })
	}
})

test('fresh npm and MCPB archives exclude diagnostics from paths and file contents', () => {
	const root = mkdtempSync(join(tmpdir(), 'fluentcart-conformance-archives-'))
	try {
		const { packageRoot, dist } = createFreshPackage(root)
		const npmArchive = packFreshNpmArchive(root, packageRoot)
		const mcpbArchive = packFreshMcpb(root, dist)
		assertDiagnosticSurfaceAbsent('npm archive', npmArchiveEntries(npmArchive))
		assertDiagnosticSurfaceAbsent('MCPB archive', mcpbArchiveEntries(mcpbArchive))

		const manifest = JSON.parse(readFileSync(join(packageRoot, 'manifest.json'), 'utf8'))
		assertDiagnosticSurfaceAbsent('manifest', [
			{ path: 'manifest.json', content: JSON.stringify(manifest) },
		])
		for (const dockerfile of ['Dockerfile', 'Dockerfile.release']) {
			const command = readFileSync(join(PACKAGE_ROOT, dockerfile), 'utf8')
				.split('\n')
				.find((line) => line.startsWith('CMD '))
			assert.match(command ?? '', /^CMD \["node", "dist\/index\.js"/)
			assertDiagnosticSurfaceAbsent(`${dockerfile} production command`, [
				{ path: dockerfile, content: command },
			])
		}
	} finally {
		rmSync(root, { recursive: true, force: true })
	}
})

test('fresh npm package preserves both OpenAI plugin manifests', () => {
	const root = mkdtempSync(join(tmpdir(), 'fluentcart-conformance-openai-plugin-'))
	try {
		const { packageRoot } = createFreshPackage(root)
		const entries = npmArchiveEntries(packFreshNpmArchive(root, packageRoot))
		const paths = entries.map(({ path }) => path)
		assert.ok(paths.includes('openai-plugin/.codex-plugin/plugin.json'))
		assert.ok(paths.includes('openai-plugin/.mcp.json'))
	} finally {
		rmSync(root, { recursive: true, force: true })
	}
})

test('archive content inspection detects a diagnostic module with an innocent filename', () => {
	const root = mkdtempSync(join(tmpdir(), 'fluentcart-conformance-mutation-'))
	try {
		const { packageRoot, dist } = createFreshPackage(root)
		const mutationPath = join(dist, 'transport', 'status.js')
		writeFileSync(mutationPath, `export default ${JSON.stringify(DIAGNOSTIC_SURFACE)};\n`, 'utf8')
		const npmArchive = packFreshNpmArchive(root, packageRoot)
		assert.notDeepEqual(diagnosticLeaks(npmArchiveEntries(npmArchive)), [])
	} finally {
		rmSync(root, { recursive: true, force: true })
	}
})
