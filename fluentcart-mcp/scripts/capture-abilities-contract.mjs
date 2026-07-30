#!/usr/bin/env node

import { spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import {
	digest,
	fingerprintAbilityRow,
	methodsFrom,
	projectAbility,
} from './abilities-contract-projection.mjs'
import { assertAllowedLiveTarget } from './live-target-policy.mjs'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const OUTPUT = 'tests/fixtures/abilities/fluentcart-1.6.0-wordpress-7.0.2.json'
const REQUEST_TIMEOUT_MS = 15_000

function fail(message) {
	throw new Error(`capture-abilities-contract: ${message}`)
}

function credentials() {
	const url = process.env.FLUENTCART_URL
	const username = process.env.FLUENTCART_USERNAME
	const appPassword = process.env.FLUENTCART_APP_PASSWORD
	if (!(url && username && appPassword)) {
		fail('FLUENTCART_URL, FLUENTCART_USERNAME and FLUENTCART_APP_PASSWORD are required')
	}
	return { url, username, appPassword }
}

function runtimeProfile() {
	const flag = process.argv.indexOf('--profile')
	const path = flag === -1 ? undefined : process.argv[flag + 1]
	if (!path) fail('--profile <runtime-profile.json> is required')
	const absolute = resolve(PACKAGE_ROOT, path)
	if (!existsSync(absolute)) fail(`runtime profile not found: ${path}`)
	const profile = JSON.parse(readFileSync(absolute, 'utf8'))
	if (!profile?.wordpress || !Array.isArray(profile.activeComponents)) {
		fail(`${path} is not a runtime profile`)
	}
	return profile
}

async function requestJson(base, auth, path, method = 'GET', requestBody) {
	const response = await fetch(new URL(path, base), {
		method,
		headers: {
			Accept: 'application/json',
			Authorization: auth,
			...(requestBody === undefined ? {} : { 'Content-Type': 'application/json' }),
		},
		body: requestBody === undefined ? undefined : JSON.stringify(requestBody),
		redirect: 'error',
		signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
	})
	const text = await response.text()
	let body
	try {
		body = JSON.parse(text)
	} catch {
		fail(`${method} ${path} returned non-JSON HTTP ${response.status}`)
	}
	return { status: response.status, body }
}

function readMcpState(result) {
	const payload = result.body?.data ?? result.body
	if (
		result.status !== 200 ||
		typeof payload?.mcp_enabled !== 'boolean' ||
		typeof payload?.adapter_available !== 'boolean'
	) {
		fail(`MCP status returned HTTP ${result.status} or an invalid status payload`)
	}
	return {
		enabled: payload.mcp_enabled,
		adapterAvailable: payload.adapter_available,
	}
}

async function setMcpEnabled(base, auth, enabled) {
	const result = await requestJson(base, auth, 'fluent-cart/v2/settings/mcp/toggle', 'POST', {
		mcp_enabled: enabled,
	})
	if (result.status !== 200) fail(`MCP toggle returned HTTP ${result.status}`)
	const observed = readMcpState(
		await requestJson(base, auth, 'fluent-cart/v2/settings/mcp'),
	)
	if (observed.enabled !== enabled) fail(`MCP toggle did not persist ${enabled}`)
}

async function requestExecutionStatus(base, auth, path, method) {
	const url = new URL(path, base)
	const init = {
		method,
		headers: {
			Accept: 'application/json',
			Authorization: auth,
			...(method === 'POST' ? { 'Content-Type': 'application/json' } : {}),
		},
		redirect: 'error',
		signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
	}
	if (method === 'GET') url.searchParams.set('input', '{}')
	else init.body = '{"input":{}}'
	const response = await fetch(url, init)
	let code = null
	if (method === 'GET') {
		const body = JSON.parse(await response.text())
		code = typeof body?.code === 'string' ? body.code : null
	} else {
		await response.body?.cancel()
	}
	return { status: response.status, code }
}

function admittedReadNames() {
	const support = JSON.parse(
		readFileSync(resolve(PACKAGE_ROOT, 'compatibility-support.json'), 'utf8'),
	)
	return support.abilityParity.rows
		.filter((row) => row.bridgeAvailable === true)
		.map((row) => row.ability)
		.sort()
}

async function captureEnabled(profile, base, auth, adapterAvailable) {
	const unauthenticated = await fetch(
		new URL('wp-abilities/v1/abilities?category=fluent-cart&per_page=100', base),
		{ signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS) },
	)
	if (unauthenticated.status !== 401) {
		fail(`unauthenticated discovery returned HTTP ${unauthenticated.status}, expected 401`)
	}

	const list = await requestJson(
		base,
		auth,
		'wp-abilities/v1/abilities?category=fluent-cart&per_page=100',
	)
	if (list.status !== 200 || !Array.isArray(list.body)) {
		fail(`authenticated discovery returned HTTP ${list.status}, expected a JSON array`)
	}

	const abilities = []
	for (const ability of list.body) {
		const path = `wp-abilities/v1/abilities/${ability.name}/run`
		const options = await requestJson(base, auth, path, 'OPTIONS')
		if (options.status !== 200) fail(`OPTIONS ${path} returned HTTP ${options.status}`)
		abilities.push(projectAbility(ability, methodsFrom(options.body)))
	}
	abilities.sort((left, right) => left.name.localeCompare(right.name))
	if (new Set(abilities.map((ability) => ability.name)).size !== abilities.length) {
		fail('ability discovery returned duplicate names')
	}
	const admitted = admittedReadNames()
	const fallbackRows = abilities.filter(
		(row) =>
			admitted.includes(row.name) &&
			row.annotations.abilitiesReadonly === null &&
			row.annotations.abilitiesDestructive !== true &&
			row.annotations.mcpReadOnlyHint === true &&
			row.annotations.mcpDestructiveHint !== true,
	)
	if (JSON.stringify(fallbackRows.map((row) => row.name)) !== JSON.stringify(admitted)) {
		fail('captured missing-readonly rows differ from the reviewed bridge admission')
	}
	const fallbackFingerprints = fallbackRows.map((row) => ({
		ability: row.name,
		fingerprint: fingerprintAbilityRow(row),
	}))
	if (
		new Set(fallbackFingerprints.map((entry) => entry.fingerprint)).size !==
		fallbackFingerprints.length
	) {
		fail('captured fallback fingerprints are not unique')
	}

	const representativeAbility = 'fluent-cart/get-store-context'
	const runPath = `wp-abilities/v1/abilities/${representativeAbility}/run`
	const get = await requestExecutionStatus(base, auth, runPath, 'GET')
	const post = await requestExecutionStatus(base, auth, runPath, 'POST')
	if (
		get.status !== 405 ||
		get.code !== 'rest_ability_invalid_method' ||
		post.status !== 200
	) {
		fail(
			`representative execution returned GET ${get.status}/${get.code ?? 'no-code'} and POST ${post.status}`,
		)
	}
	const compatibility = {
		representativeAbility,
		getStatus: get.status,
		getCode: get.code,
		postStatus: post.status,
		responseBodiesPersisted: false,
	}

	return {
		schemaVersion: 2,
		generatedBy: 'scripts/capture-abilities-contract.mjs',
		evidenceScope: 'all-active-compatibility',
		capturedAt: new Date().toISOString(),
		profile,
		profileDigest: digest(profile),
		authentication: {
			scheme: 'wordpress-application-password-basic',
			unauthenticatedDiscoveryStatus: 401,
			authenticatedDiscoveryStatus: 200,
		},
		adapter: {
			status: adapterAvailable ? 'AVAILABLE' : 'BLOCKED',
			reason: adapterAvailable
				? 'The runtime reports an available MCP adapter.'
				: 'The runtime reports no available MCP adapter. No adapter was activated for this audit.',
			endpoint: '/fluent-cart/mcp',
		},
		abilities,
		fallbackFingerprints,
		compatibility,
		contractDigest: digest({ abilities, compatibility, fallbackFingerprints }),
	}
}

async function capture() {
	const profile = runtimeProfile()
	const creds = credentials()
	const target = assertAllowedLiveTarget(creds.url, process.env)
	const base = new URL('/wp-json/', target)
	const auth = `Basic ${Buffer.from(`${creds.username}:${creds.appPassword}`).toString('base64')}`
	const initial = readMcpState(await requestJson(base, auth, 'fluent-cart/v2/settings/mcp'))
	let restoreRequired = false
	let primaryError
	try {
		if (!initial.enabled) {
			restoreRequired = true
			await setMcpEnabled(base, auth, true)
		}
		return await captureEnabled(profile, base, auth, initial.adapterAvailable)
	} catch (error) {
		primaryError = error
		throw error
	} finally {
		if (restoreRequired) {
			try {
				await setMcpEnabled(base, auth, initial.enabled)
			} catch (restorationError) {
				if (primaryError) {
					throw new AggregateError(
						[primaryError, restorationError],
						'Ability capture failed and the exact prior MCP state could not be verified.',
					)
				}
				throw restorationError
			}
		}
	}
}

const document = await capture()
const output = resolve(PACKAGE_ROOT, OUTPUT)
mkdirSync(dirname(output), { recursive: true })
writeFileSync(output, `${JSON.stringify(document, null, 2)}\n`)
const biome = resolve(
	PACKAGE_ROOT,
	'node_modules',
	'.bin',
	process.platform === 'win32' ? 'biome.cmd' : 'biome',
)
if (!existsSync(biome)) fail('Biome is required to canonicalise the captured fixture; run npm ci')
const formatted = spawnSync(biome, ['format', '--write', output], { stdio: 'inherit' })
if (formatted.status !== 0) fail('Biome failed to canonicalise the captured fixture')
process.stdout.write(`wrote ${OUTPUT} (${document.abilities.length} abilities)\n`)
