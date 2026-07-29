import { execFile } from 'node:child_process'
import { promisify } from 'node:util'

const executeFile = promisify(execFile)

const REQUIRED_KEYS = ['FLUENTCART_URL', 'FLUENTCART_USERNAME', 'FLUENTCART_APP_PASSWORD']
const POLICY_KEYS = [
	'FLUENTCART_INTEGRATION_ALLOW_REMOTE',
	'FLUENTCART_INTEGRATION_REMOTE_ORIGIN',
	'FLUENTCART_INTEGRATION_TARGET_FINGERPRINT',
]
const ABILITIES_KEYS = [
	'FLUENTCART_ABILITIES_MODE',
	'FLUENTCART_ABILITIES_USERNAME',
	'FLUENTCART_ABILITIES_APP_PASSWORD',
]
const APPROVED_DOCKER_TARGET_ORIGIN = 'http://localhost:9081'
const DOCKER_WORDPRESS_SITE_ORIGIN = 'https://fchub.vcode.sh'

function requiredValue(fileValues, key) {
	const value = fileValues[key]
	if (typeof value !== 'string' || value.trim() === '') {
		throw new Error(`Credential file must define a non-empty ${key}.`)
	}
	return value
}

function stripLauncherOverrides(ambient) {
	const childEnv = { ...ambient }
	for (const key of Object.keys(childEnv)) {
		if (key.startsWith('FLUENTCART_ABILITIES_')) delete childEnv[key]
	}
	for (const key of [...REQUIRED_KEYS, ...POLICY_KEYS, 'FLUENTCART_TEST_RUN_ID']) {
		delete childEnv[key]
	}
	return childEnv
}

function addFileOwnedGeneralPrincipal(childEnv, fileValues) {
	for (const key of REQUIRED_KEYS) childEnv[key] = requiredValue(fileValues, key)
	for (const key of POLICY_KEYS) {
		if (typeof fileValues[key] === 'string' && fileValues[key] !== '') {
			childEnv[key] = fileValues[key]
		}
	}
}

function addFileOwnedAbilitiesPrincipal(childEnv, fileValues) {
	const mode = fileValues.FLUENTCART_ABILITIES_MODE
	const hasPersistedPrincipal =
		Object.hasOwn(fileValues, 'FLUENTCART_ABILITIES_USERNAME') ||
		Object.hasOwn(fileValues, 'FLUENTCART_ABILITIES_APP_PASSWORD')
	if (hasPersistedPrincipal) {
		throw new Error(
			'Run-owned Abilities mode must not persist FLUENTCART_ABILITIES_USERNAME or FLUENTCART_ABILITIES_APP_PASSWORD in the live credential file.',
		)
	}
	if (mode === undefined || mode === '' || mode === 'disabled') return
	if (mode !== 'enabled')
		throw new Error('FLUENTCART_ABILITIES_MODE must be "enabled" or "disabled" for live tests.')
	childEnv.FLUENTCART_ABILITIES_MODE = 'enabled'
}

/**
 * Creates the environment for the integration child. Every Abilities-prefixed value inherited
 * from the launcher is removed first; the optional principal may enter only from the credential
 * file, as one complete, distinct pair.
 */
export function buildLiveChildEnvironment(fileValues, ambient = process.env) {
	const childEnv = stripLauncherOverrides(ambient)
	addFileOwnedGeneralPrincipal(childEnv, fileValues)
	addFileOwnedAbilitiesPrincipal(childEnv, fileValues)
	return childEnv
}

export function createDockerWpRunner(container = 'fchub-playground_wpcli') {
	return async function run(args) {
		const { stdout } = await executeFile('docker', ['exec', container, 'wp', ...args], {
			encoding: 'utf8',
			maxBuffer: 1024 * 1024,
		})
		return stdout
	}
}

function originOf(value, label) {
	try {
		return new URL(value).origin
	} catch {
		throw new Error(`Local Docker WordPress returned an invalid ${label}.`)
	}
}

/**
 * Binds the only mutable Abilities lane to the exact Docker WordPress it provisions through.
 * The container declares its canonical public origin through WP_HOME/WP_SITEURL, while the
 * authorised test connection reaches that same site through the fixed localhost port mapping.
 */
export async function assertAbilitiesDockerTarget(target, { run = createDockerWpRunner() } = {}) {
	const targetOrigin = originOf(target.href, 'live target')
	if (targetOrigin !== APPROVED_DOCKER_TARGET_ORIGIN) {
		throw new Error(
			`Run-owned Abilities principals require the exact local Docker WordPress target ${APPROVED_DOCKER_TARGET_ORIGIN}.`,
		)
	}

	const siteOrigin = originOf((await run(['option', 'get', 'siteurl'])).trim(), 'siteurl')
	const homeOrigin = originOf((await run(['option', 'get', 'home'])).trim(), 'home')
	if (siteOrigin !== DOCKER_WORDPRESS_SITE_ORIGIN || homeOrigin !== DOCKER_WORDPRESS_SITE_ORIGIN) {
		throw new Error(
			'The local WP-CLI container is not bound to the approved Docker WordPress site.',
		)
	}
}

/**
 * Owns provisioning and cleanup around the launcher child. Injected dependencies exist only so
 * focused tests can exercise refusal and spawn failure without creating real WordPress records.
 */
export async function runAbilitiesLauncher({
	childEnv,
	target,
	runId,
	run = createDockerWpRunner(),
	provision = provisionAbilitiesPrincipal,
	runTests,
}) {
	let lifecycle
	try {
		if (childEnv.FLUENTCART_ABILITIES_MODE === 'enabled') {
			await assertAbilitiesDockerTarget(target, { run })
			lifecycle = await provision({ run, runId })
			childEnv.FLUENTCART_ABILITIES_USERNAME = lifecycle.principal.username
			childEnv.FLUENTCART_ABILITIES_APP_PASSWORD = lifecycle.principal.password
		}
		return await runTests(childEnv)
	} finally {
		if (lifecycle) await lifecycle.cleanup()
	}
}

function parseJson(output, label) {
	try {
		return JSON.parse(output)
	} catch {
		throw new Error(`Could not parse ${label} from local WordPress.`)
	}
}

function usernameFor(runId) {
	const suffix = runId
		.replace(/[^a-z0-9]/gi, '')
		.slice(-20)
		.toLowerCase()
	if (suffix.length < 8) throw new Error('Run ID is too short to create an Abilities principal.')
	return `mcp-abilities-${suffix}`
}

async function readMcpToggle(run) {
	const output = await run([
		'eval',
		'/* abilities-principal-read-toggle */\n$s = get_option("fluent_cart_modules_settings", []); if (!is_array($s)) { $s = []; } echo wp_json_encode(["present" => array_key_exists("mcp", $s), "active" => $s["mcp"]["active"] ?? null]);',
	])
	const snapshot = parseJson(output, 'the MCP toggle')
	if (typeof snapshot?.present !== 'boolean')
		throw new Error('Local WordPress returned an invalid MCP toggle.')
	if (snapshot.present && snapshot.active !== 'yes' && snapshot.active !== 'no') {
		throw new Error('Local WordPress returned an invalid MCP toggle state.')
	}
	return snapshot
}

async function setMcpToggle(run, enabled) {
	const value = enabled ? 'yes' : 'no'
	await run([
		'eval',
		`/* abilities-principal-set-toggle ${value} */\n$s = get_option("fluent_cart_modules_settings", []); if (!is_array($s)) { $s = []; } $m = isset($s["mcp"]) && is_array($s["mcp"]) ? $s["mcp"] : []; $m["active"] = "${value}"; $s["mcp"] = $m; update_option("fluent_cart_modules_settings", $s);`,
	])
}

async function restoreMcpToggle(run, snapshot) {
	if (snapshot.present) {
		await setMcpToggle(run, snapshot.active === 'yes')
	} else {
		await run([
			'eval',
			'/* abilities-principal-restore-toggle-absent */\n$s = get_option("fluent_cart_modules_settings", []); if (!is_array($s)) { $s = []; } unset($s["mcp"]); update_option("fluent_cart_modules_settings", $s);',
		])
	}
	const restored = await readMcpToggle(run)
	if (restored.present !== snapshot.present || restored.active !== snapshot.active) {
		throw new Error('Local WordPress did not restore the original MCP toggle.')
	}
}

function passwordCreateScript(userId) {
	return `/* abilities-principal-create-password */\n$user = get_user_by("id", ${userId}); if (!$user) { throw new RuntimeException("run-owned user missing"); } $created = WP_Application_Passwords::create_new_application_password($user->ID, ["name" => "FluentCart MCP Abilities"]); if (is_wp_error($created)) { throw new RuntimeException($created->get_error_message()); } echo wp_json_encode(["uuid" => $created[1]["uuid"], "password" => $created[0]]);`
}

function passwordDeleteScript(userId, passwordUuid) {
	return `/* abilities-principal-delete-password */\nif (!WP_Application_Passwords::delete_application_password(${userId}, "${passwordUuid}")) { throw new RuntimeException("run-owned application password was not deleted"); }`
}

function absenceScript(userId, username, passwordUuid) {
	return `/* abilities-principal-verify-absent */\n$id = ${userId}; $login = "${username}"; $uuid = "${passwordUuid ?? ''}"; $passwords = WP_Application_Passwords::get_user_application_passwords($id); $found = array_filter($passwords, static fn($item) => ($item["uuid"] ?? "") === $uuid); echo wp_json_encode(["userMissing" => !get_user_by("id", $id), "loginMissing" => !username_exists($login), "passwordMissing" => count($found) === 0]);`
}

async function assertPreflight(run) {
	try {
		await run(['plugin', 'is-active', 'fluent-cart-pro'])
	} catch {
		throw new Error(
			'FluentCart Pro is required for a non-admin Abilities principal; Core-only Abilities-on is unsupported.',
		)
	}
	await run([
		'eval',
		'/* abilities-principal-preflight */\nif (!class_exists("WP_Application_Passwords") || !function_exists("wp_register_ability")) { throw new RuntimeException("WordPress Abilities or Application Passwords are unavailable"); } if (!class_exists("\\FluentCart\\App\\App") || !\\FluentCart\\App\\App::isProActive()) { throw new RuntimeException("FluentCart Pro is inactive"); }',
	])
}

async function verifyAbsent(run, userId, username, passwordUuid) {
	const result = parseJson(
		await run(['eval', absenceScript(userId, username, passwordUuid)]),
		'the Abilities principal cleanup check',
	)
	if (!(result?.userMissing && result?.loginMissing && result?.passwordMissing)) {
		throw new Error(
			'Run-owned Abilities principal cleanup could not prove user, login and password absence.',
		)
	}
}

function describe(error) {
	return error instanceof Error ? error.message : String(error)
}

async function recordCleanupFailure(failures, label, operation) {
	try {
		await operation()
	} catch (error) {
		failures.push(`${label}: ${describe(error)}`)
	}
}

/**
 * Creates one disposable local principal. Its cleanup closure retains only exact record
 * identifiers and the toggle snapshot; the application-password plaintext remains only in the
 * returned child credential object.
 */
export async function provisionAbilitiesPrincipal({ run = createDockerWpRunner(), runId }) {
	if (typeof runId !== 'string' || runId === '') throw new Error('A launcher run ID is required.')
	await assertPreflight(run)

	const username = usernameFor(runId)
	const email = `${username}@invalid.example`
	const toggle = await readMcpToggle(run)
	let userId = null
	let passwordUuid = null
	let cleaned = false

	const cleanup = async () => {
		if (cleaned) return
		cleaned = true
		const failures = []
		if (userId !== null && passwordUuid !== null) {
			await recordCleanupFailure(failures, 'password revoke failed', async () => {
				await run(['eval', passwordDeleteScript(userId, passwordUuid)])
			})
		}
		if (userId !== null) {
			await recordCleanupFailure(failures, 'user deletion failed', async () => {
				await run(['user', 'delete', String(userId), '--yes'])
			})
			await recordCleanupFailure(failures, 'absence verification failed', async () => {
				await verifyAbsent(run, userId, username, passwordUuid)
			})
		}
		await recordCleanupFailure(failures, 'MCP toggle restore failed', async () => {
			await restoreMcpToggle(run, toggle)
		})
		if (failures.length > 0)
			throw new Error(`Abilities principal cleanup incomplete: ${failures.join('; ')}`)
	}

	try {
		const createdId = Number(
			(await run(['user', 'create', username, email, '--role=subscriber', '--porcelain'])).trim(),
		)
		if (!Number.isSafeInteger(createdId) || createdId <= 0) {
			throw new Error('Local WordPress did not return an exact run-owned user ID.')
		}
		userId = createdId
		await run(['user', 'meta', 'update', String(userId), '_fluent_cart_admin_role', 'accountant'])
		const canManageOptions = await run([
			'eval',
			`/* abilities-principal-verify-non-admin */\n$user = get_user_by("id", ${userId}); echo user_can($user, "manage_options") ? "yes" : "no";`,
		])
		if (canManageOptions.trim() !== 'no') {
			throw new Error('Run-owned Abilities principal unexpectedly has manage_options.')
		}

		await setMcpToggle(run, true)
		const createdPassword = parseJson(
			await run(['eval', passwordCreateScript(userId)]),
			'the run-owned Application Password',
		)
		if (
			typeof createdPassword?.uuid !== 'string' ||
			createdPassword.uuid === '' ||
			typeof createdPassword?.password !== 'string' ||
			createdPassword.password === ''
		) {
			throw new Error('Local WordPress did not return a complete run-owned Application Password.')
		}
		if (!/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i.test(createdPassword.uuid)) {
			throw new Error('Local WordPress did not return an Application Password UUID.')
		}
		passwordUuid = createdPassword.uuid

		return {
			principal: { username, password: createdPassword.password },
			cleanup,
		}
	} catch (error) {
		try {
			await cleanup()
		} catch (cleanupError) {
			throw new Error(
				`Abilities principal setup failed and cleanup also failed: ${error instanceof Error ? error.message : String(error)}; ${cleanupError instanceof Error ? cleanupError.message : String(cleanupError)}`,
			)
		}
		throw error
	}
}

export const abilitiesEnvironmentKeys = Object.freeze(ABILITIES_KEYS)
