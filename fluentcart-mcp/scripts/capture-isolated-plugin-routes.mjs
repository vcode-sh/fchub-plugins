#!/usr/bin/env node
/**
 * Capture a FluentCart REST index from an isolated, ephemeral WordPress.
 *
 * The point is attribution. A capture taken on a machine where eleven plugins are active proves
 * only that *something* serves those routes; it cannot show that core alone, or core plus Pro,
 * serves them. This boots a stack containing nothing but the components named on the command
 * line, so every route it records is attributable to exactly those components.
 *
 * SAFETY: the project name is generated, prefixed and validated, both volumes are project-scoped,
 * plugin sources are mounted read-only, and teardown is always `-p <project> down -v`. Nothing here
 * touches, names or can reach the playground project or its volumes.
 *
 *   node scripts/capture-isolated-plugin-routes.mjs \
 *     --core /path/to/fluent-cart --output tests/fixtures/routes/fluentcart-1.5.5-core.json
 */

import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, isAbsolute, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
const COMPOSE_FILE = join(PACKAGE_ROOT, 'tests', 'fixtures', 'routes', 'legacy-runtime.compose.yml')

/** Project names must be ours and obviously ours. Anything else is refused before Docker runs. */
const PROJECT_PREFIX = 'fcmcp-iso-'
const FORBIDDEN_PROJECTS = new Set(['fchub-playground', 'default', 'routes', 'fluentcart-mcp'])
const DIGEST_SKIP = new Set(['.git', 'node_modules', '.DS_Store'])

function fail(message) {
	process.stderr.write(`error: ${message}\n`)
	process.exit(1)
}

function run(command, args, options = {}) {
	return execFileSync(command, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], ...options })
}

function compose(project, args, options = {}) {
	assertSafeProject(project)
	return run('docker', ['compose', '-f', COMPOSE_FILE, '-p', project, ...args], options)
}

function assertSafeProject(project) {
	if (!project.startsWith(PROJECT_PREFIX) || FORBIDDEN_PROJECTS.has(project)) {
		fail(`refusing to operate on Docker project "${project}"`)
	}
	if (!/^[a-z0-9-]+$/.test(project)) fail(`unsafe project name: ${project}`)
}

function parseArguments(argv) {
	const known = new Set([
		'--core', '--pro', '--output', '--port', '--download', '--sha256', '--label', '--wp-image',
	])
	const options = { port: '9391' }

	for (let index = 0; index < argv.length; index += 2) {
		const flag = argv[index]
		const value = argv[index + 1]
		if (!known.has(flag)) fail(`unknown flag: ${flag}`)
		if (!value || value.startsWith('--')) fail(`${flag} requires a value`)
		options[flag.slice(2)] = value
	}
	if (!options.core && !options.download) fail('--core or --download is required')
	if (options.download && !options.sha256) fail('--download requires --sha256')
	return options
}

/**
 * Deterministic digest of a source tree: every file's path and bytes, in sorted order. Recorded
 * so a fixture can be tied to the exact source that produced it without copying that source here.
 */
export function digestSourceTree(root) {
	const hash = createHash('sha256')
	let fileCount = 0

	const walk = (directory, prefix) => {
		const entries = readdirSync(directory, { withFileTypes: true })
			.filter((entry) => !DIGEST_SKIP.has(entry.name))
			.sort((a, b) => (a.name < b.name ? -1 : 1))

		for (const entry of entries) {
			const path = join(directory, entry.name)
			const relative = prefix === '' ? entry.name : `${prefix}/${entry.name}`
			if (entry.isDirectory()) {
				walk(path, relative)
				continue
			}
			if (!entry.isFile()) continue
			hash.update(`${relative}\0`)
			hash.update(readFileSync(path))
			fileCount += 1
		}
	}

	walk(root, '')
	return { sha256: `sha256:${hash.digest('hex')}`, fileCount }
}

function readPluginVersion(pluginDir, slug) {
	const candidates = [`${slug}.php`, 'plugin.php', `${slug.replace(/-/g, '')}.php`]
	for (const candidate of candidates) {
		const path = join(pluginDir, candidate)
		if (!existsSync(path)) continue
		const header = readFileSync(path, 'utf8').slice(0, 4000)
		const match = header.match(/^\s*\*?\s*Version:\s*([0-9][^\s]*)/im)
		if (match) return match[1]
	}
	return null
}

async function downloadPlugin(url, expected, scratch) {
	process.stderr.write(`downloading ${url}\n`)
	const response = await fetch(url, { redirect: 'follow' })
	if (!response.ok) fail(`download failed with status ${response.status}`)

	const bytes = Buffer.from(await response.arrayBuffer())
	const actual = createHash('sha256').update(bytes).digest('hex')
	if (actual !== expected) fail(`archive digest ${actual} does not match the expected ${expected}`)

	const archive = join(scratch, 'plugin.zip')
	const target = join(scratch, 'extracted')
	writeFileSync(archive, bytes)
	run('unzip', ['-q', archive, '-d', target])

	const [entry] = readdirSync(target)
	process.stderr.write(`verified archive digest ${actual}\n`)
	return join(target, entry)
}

function waitForRest(port, attempts = 90) {
	for (let attempt = 0; attempt < attempts; attempt += 1) {
		try {
			const body = run('curl', ['-fsS', '-m', '5', `http://127.0.0.1:${port}/wp-json/`])
			if (body.includes('fluent-cart')) return true
		} catch {
			// Apache and MariaDB take a moment; only a persistent failure matters.
		}
		execFileSync('sleep', ['2'])
	}
	return false
}

function installWordPress(project, port) {
	const wp = (args) => compose(project, ['exec', '-T', 'wpcli', 'wp', ...args])

	for (let attempt = 0; attempt < 40; attempt += 1) {
		try {
			wp(['core', 'install', `--url=http://127.0.0.1:${port}`, '--title=Isolated Capture',
				'--admin_user=capture', '--admin_password=capture-only-never-reused',
				'--admin_email=capture@fixture.invalid', '--skip-email'])
			return
		} catch (error) {
			if (String(error.stdout ?? '').includes('already installed')) return
			execFileSync('sleep', ['2'])
		}
	}
	fail('WordPress never finished installing in the isolated stack')
}

function activatePlugins(project, slugs) {
	const wp = (args) => compose(project, ['exec', '-T', 'wpcli', 'wp', ...args])

	// Isolation means isolation: the image ships Akismet and Hello Dolly, and a capture that left
	// them active could not claim the route set belongs to the named components alone.
	try {
		wp(['plugin', 'deactivate', '--all'])
		wp(['plugin', 'delete', 'akismet', 'hello'])
	} catch {
		// Nothing to remove on a rerun.
	}

	for (const slug of slugs) {
		try {
			wp(['plugin', 'activate', slug])
		} catch (error) {
			fail(`could not activate ${slug}: ${String(error.stderr ?? error.stdout ?? error.message).trim()}`)
		}
	}

	const active = JSON.parse(wp(['plugin', 'list', '--status=active', '--fields=name,version', '--format=json']))
	const wordpress = wp(['core', 'version']).trim()
	return { wordpress, activeComponents: active.map((p) => ({ slug: p.name, version: p.version })) }
}

export async function captureIsolatedRoutes(options) {
	const project = `${PROJECT_PREFIX}${options.label ?? 'capture'}`
	assertSafeProject(project)

	const scratch = mkdtempSync(join(tmpdir(), 'fcmcp-iso-'))
	const emptyDir = join(scratch, 'absent')
	execFileSync('mkdir', ['-p', emptyDir])

	// Teardown runs from `finally`, possibly before the real values exist, so it always has a
	// complete set of placeholders to interpolate.
	const teardownEnv = {
		...process.env,
		FC_HTTP_PORT: options.port,
		FC_CORE_SOURCE: emptyDir,
		FC_PRO_SOURCE: emptyDir,
		FC_PRO_TARGET: '/tmp/fc-absent-pro',
		...(options['wp-image'] ? { FC_WP_IMAGE: options['wp-image'] } : {}),
	}

	let result
	try {
		const core = options.download
			? await downloadPlugin(options.download, options.sha256, scratch)
			: resolve(options.core)
		if (!existsSync(core) || !statSync(core).isDirectory()) fail(`core source not found: ${core}`)

		const pro = options.pro ? resolve(options.pro) : null
		if (pro && !existsSync(pro)) fail(`pro source not found: ${pro}`)

		const digests = { 'fluent-cart': digestSourceTree(core) }
		if (pro) digests['fluent-cart-pro'] = digestSourceTree(pro)

		const environment = {
			...process.env,
			FC_HTTP_PORT: options.port,
			FC_CORE_SOURCE: core,
			FC_PRO_SOURCE: pro ?? emptyDir,
			FC_PRO_TARGET: pro ? '/var/www/html/wp-content/plugins/fluent-cart-pro' : '/tmp/fc-absent-pro',
			...(options['wp-image'] ? { FC_WP_IMAGE: options['wp-image'] } : {}),
		}

		process.stderr.write(`starting isolated project ${project} on 127.0.0.1:${options.port}\n`)
		compose(project, ['up', '-d', '--wait'], { env: environment, stdio: ['ignore', 2, 2] })

		installWordPress(project, options.port)
		const slugs = pro ? ['fluent-cart', 'fluent-cart-pro'] : ['fluent-cart']
		const profile = activatePlugins(project, slugs)

		// A fresh install uses plain permalinks, under which /wp-json/ returns the site HTML rather
		// than the REST index. The playground capture was taken through /wp-json/, so this matches it.
		compose(project, ['exec', '-T', 'wpcli', 'wp', 'rewrite', 'structure', '/%postname%/', '--hard'])
		compose(project, ['exec', '-T', 'wpcli', 'wp', 'rewrite', 'flush', '--hard'])

		if (!waitForRest(options.port)) fail('the isolated store never served a fluent-cart REST namespace')

		const profilePath = join(scratch, 'profile.json')
		writeFileSync(profilePath, JSON.stringify(profile, null, 2))

		if (options.output) {
			run('node', [join(PACKAGE_ROOT, 'scripts', 'capture-route-fixture.mjs'),
				'--rest-index', `http://127.0.0.1:${options.port}/wp-json/`,
				'--profile', profilePath,
				'--output', isAbsolute(options.output) ? options.output : join(PACKAGE_ROOT, options.output),
			], { stdio: ['ignore', 2, 2] })
		}

		result = { project, profile, digests, output: options.output ?? null, coreVersion: readPluginVersion(core, 'fluent-cart') }
	} finally {
		process.stderr.write(`tearing down ${project}\n`)
		try {
			// The same environment is required here: without it compose cannot interpolate the mount
			// specs, `down -v` fails its own config validation, and the stack survives teardown.
			compose(project, ['down', '-v', '--remove-orphans'], { env: teardownEnv, stdio: ['ignore', 2, 2] })
		} catch (error) {
			process.stderr.write(`teardown reported: ${String(error.message).trim()}\n`)
		}
		rmSync(scratch, { recursive: true, force: true })
	}

	return result
}

if (process.argv[1] && process.argv[1].endsWith('capture-isolated-plugin-routes.mjs')) {
	const options = parseArguments(process.argv.slice(2))
	const captured = await captureIsolatedRoutes(options)
	process.stdout.write(`${JSON.stringify(captured, null, 2)}\n`)
}
