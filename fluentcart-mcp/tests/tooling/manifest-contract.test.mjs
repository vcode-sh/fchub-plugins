import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const META_NAMESPACE = 'sh.vcode.fluentcart-mcp'

function readJson(relativePath) {
	return JSON.parse(readFileSync(join(PACKAGE_ROOT, relativePath), 'utf8'))
}

const rawManifest = readFileSync(join(PACKAGE_ROOT, 'manifest.json'), 'utf8')
const manifest = JSON.parse(rawManifest)
const pkg = readJson('package.json')
const contract = readJson('release-contract.json')
const schema = readJson('node_modules/@anthropic-ai/mcpb/schemas/mcpb-manifest-v0.3.schema.json')
const meta = manifest._meta[META_NAMESPACE]

/**
 * The meta-tools are fixed by the exposure design, so they are pinned by name: dynamic registers
 * search, describe and two risk-split executors, code registers a sandboxed pair. The curated
 * members are not pinned here — curated membership is a reviewed product decision tracked by the
 * release contract's own `curatedNames` block, and duplicating the roster would only mean editing
 * two files whenever one tool is added.
 */
// Real-money actions are audit-only and never contribute a public executor.
const DYNAMIC_META = [
	'fluentcart_describe_tools',
	'fluentcart_execute_read_tool',
	'fluentcart_execute_reversible_write',
	'fluentcart_search_tools',
]

const CODE_META = ['fluentcart_execute_code', 'fluentcart_search_api']

const SENSITIVE_KEYS = ['abilities_app_password', 'app_password']

/** Environment variables the built server actually reads, taken from the source it reads them in. */
function runtimeEnvironmentVariables(directory = join(PACKAGE_ROOT, 'src')) {
	const found = new Set()
	for (const entry of readdirSync(directory, { withFileTypes: true })) {
		const path = join(directory, entry.name)
		if (entry.isDirectory()) {
			for (const name of runtimeEnvironmentVariables(path)) found.add(name)
		} else if (entry.name.endsWith('.ts')) {
			for (const match of readFileSync(path, 'utf8').matchAll(/process\.env\.([A-Z][A-Z0-9_]*)/g)) {
				found.add(match[1])
			}
		}
	}
	return found
}

function sha256(value) {
	return `sha256:${createHash('sha256').update(value).digest('hex')}`
}

function metaFor(name) {
	return meta.tools.find((tool) => tool.name === name)
}

describe('manifest identity', () => {
	it('carries the package version, name and the pinned manifest schema version', () => {
		assert.equal(manifest.version, pkg.version)
		assert.equal(manifest.name, pkg.name)
		assert.equal(manifest.manifest_version, '0.3')
	})

	it('claims no tool count at all, since exposure varies by store and policy', () => {
		assert.doesNotMatch(manifest.description, /200\+|274|279/)
		assert.doesNotMatch(manifest.description, /\d+\s*\+?\s*tools/i)
		assert.doesNotMatch(manifest.long_description, /\d+\s*\+?\s*tools/i)
	})

	/** Prose only: `_meta` legitimately names the `legacy-1.3.9-runtime-rest-disabled` profile row. */
	it('does not claim FluentCart 1.3.9 runtime support in its prose', () => {
		assert.doesNotMatch(manifest.description, /1\.3\.9/)
		assert.doesNotMatch(manifest.long_description, /1\.3\.9/)
	})

	it('is stored exactly as the generator serialises it', () => {
		assert.equal(rawManifest, `${JSON.stringify(manifest, null, 2)}\n`)
	})
})

describe('manifest server entry', () => {
	it('points at the built entry point and launches it through node', () => {
		assert.equal(manifest.server.entry_point, 'dist/index.js')
		assert.equal(manifest.server.type, 'node')
		assert.equal(manifest.server.mcp_config.command, 'node')
		// biome-ignore lint/suspicious/noTemplateCurlyInString: MCPB resolves ${__dirname} itself; this is a literal manifest placeholder, not a JS template.
		assert.deepEqual(manifest.server.mcp_config.args, ['${__dirname}/dist/index.js'])
	})

	it('requires the Node version the package requires', () => {
		assert.equal(manifest.compatibility.runtimes.node, pkg.engines.node)
	})
})

describe('manifest user configuration', () => {
	const runtimeVariables = runtimeEnvironmentVariables()
	const env = manifest.server.mcp_config.env

	it('maps every declared key to an environment variable the server reads', () => {
		for (const [key, variable] of Object.entries(meta.userConfigEnvironment)) {
			assert.ok(key in manifest.user_config, `${key} is mapped but not declared`)
			assert.ok(runtimeVariables.has(variable), `${variable} is declared but never read by src/`)
		}
	})

	it('declares no key without a mapping, and no mapping without a key', () => {
		const mapped = Object.keys(meta.userConfigEnvironment).sort()
		assert.deepEqual(Object.keys(manifest.user_config).sort(), mapped)
	})

	it('wires each mapping through the launch environment exactly once', () => {
		const expected = {}
		for (const [key, variable] of Object.entries(meta.userConfigEnvironment)) {
			expected[variable] = `\${user_config.${key}}`
		}
		assert.deepEqual(env, expected)
	})

	it('marks the credential-bearing fields sensitive', () => {
		for (const key of SENSITIVE_KEYS) {
			assert.equal(manifest.user_config[key].sensitive, true, key)
		}
	})

	it('marks nothing else sensitive, so the flag keeps meaning something', () => {
		for (const [key, entry] of Object.entries(manifest.user_config)) {
			if (SENSITIVE_KEYS.includes(key)) continue
			assert.notEqual(entry.sensitive, true, key)
		}
	})

	it('requires only the three credentials the server cannot start without', () => {
		const required = Object.entries(manifest.user_config)
			.filter(([, entry]) => entry.required === true)
			.map(([key]) => key)
		assert.deepEqual(required.sort(), ['app_password', 'store_url', 'username'])
	})

	it('defaults the write mode to the read-only policy', () => {
		assert.equal(manifest.user_config.write_mode.default, 'disabled')
	})

	it('defaults the native abilities bridge off and gives it separate credentials', () => {
		assert.equal(manifest.user_config.abilities_mode.default, 'disabled')
		assert.equal(manifest.user_config.abilities_username.required, false)
		assert.equal(manifest.user_config.abilities_app_password.required, false)
		assert.notEqual(
			meta.userConfigEnvironment.abilities_username,
			meta.userConfigEnvironment.username,
		)
		assert.notEqual(
			meta.userConfigEnvironment.abilities_app_password,
			meta.userConfigEnvironment.app_password,
		)
	})

	it('does not advertise inert real-money installer settings', () => {
		assert.equal(contract.writePolicyExposure.realMoneyExposable, undefined)
		assert.equal(manifest.user_config.guard_secret, undefined)
		assert.equal(manifest.user_config.guard_state_dir, undefined)
		assert.doesNotMatch(manifest.user_config.write_mode.description, /guarded|real-money/i)
		assert.doesNotMatch(manifest.long_description, /guarded|real-money|signing secret/i)
	})
})

describe('manifest tool inventory', () => {
	it('advertises a sorted union of FluentCart names and nothing else', () => {
		const names = manifest.tools.map((tool) => tool.name)
		assert.deepEqual(names, [...names].sort())
		for (const name of names) assert.match(name, /^fluentcart_[a-z_]+$/)
	})

	it('advertises exactly the meta-tools each mode registers', () => {
		const named = (provenance) =>
			meta.tools
				.filter((tool) => tool.provenance === provenance)
				.map((tool) => tool.name)
				.sort()
		assert.deepEqual(named('dynamic-meta'), DYNAMIC_META)
		assert.deepEqual(named('code-meta'), CODE_META)
	})

	it('advertises each name once, with a description', () => {
		const names = manifest.tools.map((tool) => tool.name)
		assert.equal(new Set(names).size, names.length)
		for (const tool of manifest.tools) assert.match(tool.description, /\S/, tool.name)
		assert.equal(meta.advertisedToolCount, manifest.tools.length)
		assert.equal(meta.tools.length, manifest.tools.length)
	})

	it('advertises every curated name that resolves, and no stale one', () => {
		const curated = meta.tools.filter((tool) => tool.provenance === 'curated')
		assert.equal(curated.length, contract.curatedNames.resolvable)
		for (const name of contract.curatedNames.unresolved) {
			assert.equal(metaFor(name), undefined, `${name} resolves to nothing`)
		}
	})

	it('advertises the four dynamic and two code-mode meta-tools', () => {
		const byProvenance = (value) => meta.tools.filter((tool) => tool.provenance === value).length
		assert.equal(byProvenance('dynamic-meta'), 4)
		assert.equal(byProvenance('code-meta'), 2)
	})
})

describe('manifest tool provenance', () => {
	const measured = contract.profiles.filter((profile) => profile.status === 'MEASURED')
	const measuredProfiles = new Set(measured.map((profile) => profile.name))

	it('checksums the description and input schema of every advertised name', () => {
		for (const tool of meta.tools) {
			assert.match(tool.descriptionSha256, /^sha256:[0-9a-f]{64}$/, tool.name)
			assert.match(tool.inputSchemaSha256, /^sha256:[0-9a-f]{64}$/, tool.name)
		}
	})

	it('checksums the description it actually advertises', () => {
		for (const tool of manifest.tools) {
			assert.equal(metaFor(tool.name).descriptionSha256, sha256(tool.description), tool.name)
		}
	})

	it('names at least one measured profile and mode able to expose each entry', () => {
		for (const tool of meta.tools) {
			assert.ok(tool.exposedBy.length > 0, `${tool.name} is advertised but nothing exposes it`)
			for (const row of tool.exposedBy) {
				assert.ok(measuredProfiles.has(row.profile), `${tool.name} cites unmeasured ${row.profile}`)
				assert.ok(row.modes.length > 0, tool.name)
				for (const mode of row.modes) {
					assert.ok(['dynamic', 'curated', 'code', 'full'].includes(mode), `${tool.name}/${mode}`)
				}
			}
		}
	})

	/**
	 * Raising the write mode may add names, never remove them. A read that vanished when writes
	 * were enabled would mean the wider policy had narrowed exposure, which is backwards.
	 */
	it('never drops a name from a wider write mode than the one that showed it', () => {
		const namesFor = (profile) =>
			new Set(
				meta.tools
					.filter((tool) => tool.exposedBy.some((row) => row.profile === profile.name))
					.map((tool) => tool.name),
			)
		const byMode = new Map(measured.map((profile) => [profile.writeMode, namesFor(profile)]))

		for (const [narrow, wide] of [['disabled', 'reversible']]) {
			const from = byMode.get(narrow)
			const to = byMode.get(wide)
			if (from && to) for (const name of from) assert.ok(to.has(name), `${wide} dropped ${name}`)
		}
	})

	it('ties the manifest to the exact source tree the contract measured', () => {
		assert.equal(meta.packageVersion, pkg.version)
		assert.equal(meta.sourceTreeDigest, contract.sourceTreeDigest)
		assert.equal(meta.serializer, contract.serializer)
	})
})

describe('manifest schema conformance', () => {
	it('carries every key the MCPB 0.3 schema requires and none it rejects', () => {
		const allowed = new Set(Object.keys(schema.properties))
		for (const key of Object.keys(manifest)) assert.ok(allowed.has(key), key)
		for (const key of schema.required) assert.ok(key in manifest, key)
	})

	it('keeps tool entries to name and description, which is all the schema permits', () => {
		for (const tool of manifest.tools) {
			assert.deepEqual(Object.keys(tool).sort(), ['description', 'name'])
		}
	})

	it('uses only permitted user config keys and types', () => {
		const entrySchema = schema.properties.user_config.additionalProperties
		const allowed = new Set(Object.keys(entrySchema.properties))
		for (const [key, entry] of Object.entries(manifest.user_config)) {
			for (const field of Object.keys(entry)) assert.ok(allowed.has(field), `${key}.${field}`)
			for (const field of entrySchema.required) assert.ok(field in entry, `${key}.${field}`)
			assert.ok(entrySchema.properties.type.enum.includes(entry.type), `${key}.type`)
		}
	})

	it('keeps its extra metadata inside _meta, the one place the schema leaves open', () => {
		assert.ok(manifest._meta[META_NAMESPACE])
		assert.equal(typeof manifest._meta[META_NAMESPACE], 'object')
	})
})
