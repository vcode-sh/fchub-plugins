#!/usr/bin/env node
import { spawn } from 'node:child_process'
import { randomUUID } from 'node:crypto'
import { readFileSync, statSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'
import { parseEnv } from 'node:util'
import { assertAllowedLiveTarget, fetchTargetIdentity } from './live-target-policy.mjs'

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')

// The one and only credential file this project will read. Not a prefix, not a glob, not a
// convention-driven family — an exact path, so nothing can be shadowed in from elsewhere.
const CREDENTIAL_FILE = resolve(packageRoot, '.env.test.local')

const REQUIRED_KEYS = ['FLUENTCART_URL', 'FLUENTCART_USERNAME', 'FLUENTCART_APP_PASSWORD']
const POLICY_KEYS = [
	'FLUENTCART_INTEGRATION_ALLOW_REMOTE',
	'FLUENTCART_INTEGRATION_REMOTE_ORIGIN',
	'FLUENTCART_INTEGRATION_TARGET_FINGERPRINT',
]
// Ambient values for these keys are erased before the child starts, so the exact file is the
// only source of truth rather than merely the first one consulted.
const SHADOWED_KEYS = [...REQUIRED_KEYS, ...POLICY_KEYS, 'FLUENTCART_TEST_RUN_ID']

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

function buildChildEnvironment(fileValues) {
	const childEnv = { ...process.env }
	for (const key of SHADOWED_KEYS) {
		delete childEnv[key]
	}

	for (const key of REQUIRED_KEYS) {
		const value = fileValues[key]
		if (typeof value !== 'string' || value.trim() === '') {
			fail(`${CREDENTIAL_FILE} must define a non-empty ${key}.`)
		}
		childEnv[key] = value
	}

	for (const key of POLICY_KEYS) {
		if (typeof fileValues[key] === 'string' && fileValues[key] !== '') {
			childEnv[key] = fileValues[key]
		}
	}

	return childEnv
}

async function main() {
	const fileValues = readCredentialFile()
	const childEnv = buildChildEnvironment(fileValues)

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

	const vitestBin = resolve(packageRoot, 'node_modules', 'vitest', 'vitest.mjs')
	const args = [vitestBin, 'run', '--config', 'vitest.integration.config.ts', ...process.argv.slice(2)]

	const child = spawn(process.execPath, args, {
		cwd: packageRoot,
		env: childEnv,
		stdio: 'inherit',
	})

	child.on('error', (error) => {
		fail(`could not start the local vitest binary: ${error.message}`)
	})

	child.on('exit', (code, signal) => {
		if (signal) {
			process.exit(1)
		}
		process.exit(code ?? 1)
	})
}

try {
	await main()
} catch (error) {
	// Policy and identity failures are expected refusals, not crashes. Report the message and
	// nothing else: the stack would name the credential file's caller chain to no useful end.
	fail(error instanceof Error ? error.message : String(error))
}
