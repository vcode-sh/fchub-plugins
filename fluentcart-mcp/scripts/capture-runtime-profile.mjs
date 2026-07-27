#!/usr/bin/env node
/**
 * Capture the runtime component profile of a WordPress environment.
 *
 * A route fixture without a profile is an anecdote. This script records what was actually
 * running when an index was captured, so a later reader can tell whether a route came from
 * FluentCart core, from Pro, or from something else entirely that happened to be active.
 *
 * It records EVERY active plugin rather than the two or three assumed to matter. Guessing which
 * component contributes a route is exactly the mistake that produces a fixture nobody can
 * reproduce.
 *
 * Read-only by construction: the only commands executed are `wp core version` and
 * `wp plugin list`, both passed as argument arrays so no shell can expand anything.
 *
 * Usage:
 *   node scripts/capture-runtime-profile.mjs \
 *     --compose-file /absolute/path/docker-compose.yml \
 *     --output /tmp/fluentcart-runtime-profile.json
 */

import { execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, writeFileSync } from 'node:fs'
import { dirname, isAbsolute, resolve } from 'node:path'

const DEFAULT_SERVICE = 'wpcli'
const VERSION_PATTERN = /^\d+(\.\d+)*(-[0-9A-Za-z.-]+)?$/

function fail(message) {
	process.stderr.write(`capture-runtime-profile: ${message}\n`)
	process.exit(1)
}

function parseArgs(argv) {
	const options = { service: DEFAULT_SERVICE }
	const known = new Set(['--compose-file', '--output', '--service'])

	for (let index = 0; index < argv.length; index += 1) {
		const flag = argv[index]
		if (!known.has(flag)) fail(`unknown argument: ${flag}`)

		const value = argv[index + 1]
		if (!value || value.startsWith('--')) fail(`${flag} requires a value`)
		index += 1

		if (flag === '--compose-file') options.composeFile = value
		if (flag === '--output') options.output = value
		if (flag === '--service') options.service = value
	}

	if (!options.composeFile) fail('--compose-file is required')
	if (!options.output) fail('--output is required')
	if (!isAbsolute(options.composeFile)) {
		fail('--compose-file must be an absolute path so the capture is reproducible')
	}
	if (!existsSync(options.composeFile)) fail(`compose file not found: ${options.composeFile}`)

	return options
}

/** Run one read-only WP-CLI command inside the compose service. */
function wpCli(options, args) {
	const argv = [
		'compose',
		'-f',
		options.composeFile,
		'exec',
		'-T',
		options.service,
		'wp',
		...args,
	]

	try {
		return execFileSync('docker', argv, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
	} catch (error) {
		const detail = error?.stderr?.toString().trim() || error?.message || 'unknown failure'
		fail(`\`wp ${args.join(' ')}\` failed: ${detail}`)
	}
}

function captureWordPressVersion(options) {
	const version = wpCli(options, ['core', 'version']).trim()
	if (!VERSION_PATTERN.test(version)) {
		fail(`WordPress version is missing or unrecognisable: ${JSON.stringify(version)}`)
	}
	return version
}

function captureActiveComponents(options) {
	const raw = wpCli(options, [
		'plugin',
		'list',
		'--status=active',
		'--fields=name,version',
		'--format=json',
	])

	let parsed
	try {
		parsed = JSON.parse(raw)
	} catch {
		fail('`wp plugin list` did not return JSON')
	}

	if (!Array.isArray(parsed) || parsed.length === 0) {
		fail('`wp plugin list` returned no active components')
	}

	const seen = new Set()
	const components = []

	for (const entry of parsed) {
		const slug = typeof entry?.name === 'string' ? entry.name.trim() : ''
		const version = typeof entry?.version === 'string' ? entry.version.trim() : ''

		if (!slug) fail(`an active component reported no name: ${JSON.stringify(entry)}`)
		// A component with no version cannot anchor a fixture to anything reproducible.
		if (!version) fail(`active component "${slug}" reported no version`)
		if (!VERSION_PATTERN.test(version)) {
			fail(`active component "${slug}" reported an unrecognisable version: ${version}`)
		}
		if (seen.has(slug)) fail(`active component "${slug}" was reported more than once`)

		seen.add(slug)
		components.push({ slug, version })
	}

	components.sort((left, right) => (left.slug < right.slug ? -1 : 1))
	return components
}

function main() {
	const options = parseArgs(process.argv.slice(2))

	const profile = {
		wordpress: captureWordPressVersion(options),
		activeComponents: captureActiveComponents(options),
		capturedAt: new Date().toISOString(),
		source: 'wp-cli',
	}

	const output = resolve(options.output)
	mkdirSync(dirname(output), { recursive: true })
	writeFileSync(output, `${JSON.stringify(profile, null, 2)}\n`, 'utf8')

	process.stdout.write(
		`Captured WordPress ${profile.wordpress} with ${profile.activeComponents.length} active components -> ${output}\n`,
	)
}

main()
