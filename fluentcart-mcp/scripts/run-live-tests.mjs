#!/usr/bin/env node
import { spawn } from 'node:child_process'
import { randomUUID } from 'node:crypto'
import { readFileSync, statSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'
import { parseEnv } from 'node:util'
import { assertAllowedLiveTarget, fetchTargetIdentity } from './live-target-policy.mjs'
import {
	buildLiveChildEnvironment,
	runAbilitiesLauncher,
} from '../tests/live-support/abilities-principal.mjs'

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')

// The one and only credential file this project will read. Not a prefix, not a glob, not a
// convention-driven family — an exact path, so nothing can be shadowed in from elsewhere.
const CREDENTIAL_FILE = resolve(packageRoot, '.env.test.local')

function fail(message) {
	console.error(`live-tests: ${message}`)
	process.exit(1)
}

function readCredentialFile() {
	let stats
	try {
		stats = statSync(CREDENTIAL_FILE)
	} catch {
		fail(
			`${CREDENTIAL_FILE} is missing. Live integration tests read that exact file only; ambient environment variables are ignored.`,
		)
	}

	if (!stats.isFile()) {
		fail(`${CREDENTIAL_FILE} is not a regular file.`)
	}

	if (process.platform !== 'win32') {
		const mode = stats.mode & 0o777
		if (mode !== 0o600) {
			fail(
				`${CREDENTIAL_FILE} must be mode 0600, found ${mode.toString(8).padStart(4, '0')}. Run: chmod 600 ${CREDENTIAL_FILE}`,
			)
		}
	}

	const raw = readFileSync(CREDENTIAL_FILE, 'utf8')
	assertNoDuplicateKeys(raw)
	return parseEnv(raw)
}

function assertNoDuplicateKeys(raw) {
	const seen = new Set()
	for (const line of raw.split(/\r?\n/)) {
		const match = /^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/.exec(line)
		if (!match) continue
		const key = match[1]
		if (seen.has(key)) {
			fail(`${CREDENTIAL_FILE} declares ${key} more than once; refusing to guess which value wins.`)
		}
		seen.add(key)
	}
}

export function runVitest(
	childEnv,
	{ spawnProcess = spawn, argv = process.argv.slice(2), root = packageRoot } = {},
) {
	const vitestBin = resolve(root, 'node_modules', 'vitest', 'vitest.mjs')
	const args = [vitestBin, 'run', '--config', 'vitest.integration.config.ts', ...argv]
	return new Promise((resolveExit, rejectStart) => {
		const child = spawnProcess(process.execPath, args, {
			cwd: root,
			env: childEnv,
			stdio: 'inherit',
		})
		child.once('error', (error) => {
			rejectStart(new Error(`could not start the local vitest binary: ${error.message}`))
		})
		child.once('exit', (code, signal) => resolveExit(signal ? 1 : (code ?? 1)))
	})
}

async function main() {
	const fileValues = readCredentialFile()
	const childEnv = buildLiveChildEnvironment(fileValues)

	const target = assertAllowedLiveTarget(childEnv.FLUENTCART_URL, {
		FLUENTCART_INTEGRATION_ALLOW_REMOTE: childEnv.FLUENTCART_INTEGRATION_ALLOW_REMOTE,
		FLUENTCART_INTEGRATION_REMOTE_ORIGIN: childEnv.FLUENTCART_INTEGRATION_REMOTE_ORIGIN,
		FLUENTCART_INTEGRATION_TARGET_FINGERPRINT: childEnv.FLUENTCART_INTEGRATION_TARGET_FINGERPRINT,
	})

	const declaredFingerprint = childEnv.FLUENTCART_INTEGRATION_TARGET_FINGERPRINT
	if (declaredFingerprint) {
		const identity = await fetchTargetIdentity(target.href)
		if (identity.fingerprint !== declaredFingerprint) {
			fail(
				`Target identity fingerprint does not match FLUENTCART_INTEGRATION_TARGET_FINGERPRINT. Observed ${identity.fingerprint}.`,
			)
		}
	}

	const runId = `mcp-${new Date().toISOString().replace(/[:.]/g, '-')}-${randomUUID()}`
	childEnv.FLUENTCART_RUN_INTEGRATION = 'yes'
	childEnv.FLUENTCART_TEST_RUN_ID = runId

	// Origin and run ID only. Never the username, never the application password.
	console.error(`live-tests: target ${target.origin}`)
	console.error(`live-tests: run id ${runId}`)

	return runAbilitiesLauncher({ childEnv, target, runId, runTests: runVitest })
}

const isEntryPoint = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)
if (isEntryPoint) {
	try {
		process.exit(await main())
	} catch (error) {
		// Policy and identity failures are expected refusals, not crashes. Report the message and
		// nothing else: the stack would name the credential file's caller chain to no useful end.
		fail(error instanceof Error ? error.message : String(error))
	}
}
