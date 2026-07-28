// What the release graph owes us, asserted against the workflows themselves.
//
// The release pipeline's one job is to publish exactly what it validated. Every assertion here
// exists because the opposite was once true: three separate builds, a globally installed packer
// pinned to nothing, and npm publication that completed before the bundle was ever opened.
//
// Read as structure — jobs, steps, commands — rather than as text at a fixed indentation, so
// reformatting does not turn this file red but deleting a gate does.
//
//   node --test tests/tooling/release-workflow-contract.test.mjs

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const REPO_ROOT = dirname(dirname(dirname(dirname(fileURLToPath(import.meta.url)))))
const WORKFLOWS = join(REPO_ROOT, '.github', 'workflows')

/** Full-line comments go first: nothing asserted below is a comment. */
const strip = (yaml) => yaml.replace(/^[ \t]*#.*$/gm, '')

const read = (name) => strip(readFileSync(join(WORKFLOWS, name), 'utf8'))

const release = read('mcp-release.yml')
const docker = read('mcp-docker.yml')
const ci = read('mcp-ci.yml')
const docs = read('docs-ci.yml')
const protocolSmoke = readFileSync(
	join(REPO_ROOT, 'fluentcart-mcp', 'scripts', 'smoke-mcp-http.mjs'),
	'utf8',
)
const dockerAcceptance = readFileSync(
	join(REPO_ROOT, 'fluentcart-mcp', 'tests', 'acceptance', 'docker.test.mjs'),
	'utf8',
)

const ALL_WORKFLOWS = readdirSync(WORKFLOWS)
	.filter((name) => name.endsWith('.yml') || name.endsWith('.yaml'))
	.map((name) => ({ name, body: read(name) }))

/** Everything below the top-level `jobs:` key, so an input named `publish` is not a job. */
function jobsSection(workflow) {
	const start = workflow.search(/^jobs:[ \t]*$/m)
	assert.ok(start !== -1, 'workflow has no jobs section')
	return workflow.slice(start)
}

/** The body of one job, from its key to the next job key at the same depth. */
function job(workflow, name) {
	const section = jobsSection(workflow)
	const header = section.match(new RegExp(`^([ \\t]{2})${name}:[ \\t]*$`, 'm'))
	assert.ok(header, `expected a "${name}" job`)

	const rest = section.slice(header.index + header[0].length)
	const end = rest.search(/^[ ]{2}[A-Za-z0-9_-]+:[ \t]*$/m)
	return end === -1 ? rest : rest.slice(0, end)
}

/** Job names declared by a workflow, in order. */
function jobNames(workflow) {
	return [...jobsSection(workflow).matchAll(/^[ ]{2}([A-Za-z0-9_-]+):[ \t]*$/gm)].map((m) => m[1])
}

/** The `needs:` of a job, whether written inline or as a list. */
function needs(body) {
	const inline = body.match(/^[ \t]*needs:[ \t]*\[([^\]]*)\][ \t]*$/m)
	if (inline)
		return inline[1]
			.split(',')
			.map((entry) => entry.trim())
			.filter(Boolean)

	const block = body.match(/^[ \t]*needs:[ \t]*\n((?:[ \t]*-[ \t]*[A-Za-z0-9_-]+[ \t]*\n)+)/m)
	if (block) return [...block[1].matchAll(/-[ \t]*([A-Za-z0-9_-]+)/g)].map((m) => m[1])

	const scalar = body.match(/^[ \t]*needs:[ \t]*([A-Za-z0-9_-]+)[ \t]*$/m)
	return scalar ? [scalar[1]] : []
}

/** Commands that rebuild or repackage. None of them belongs in a job that publishes. */
const REBUILD_COMMANDS = [
	{ pattern: /\bnpm\s+ci\b/, label: 'npm ci' },
	// `npm install -g npm@…` is exempt: it upgrades the package manager so trusted publishing
	// works, and installs nothing that could alter the artefact being published. Any other
	// install in a publish job would mean the bytes are no longer the inspected ones.
	{ pattern: /\bnpm\s+install\b(?!\s+-g\s+npm@)/, label: 'npm install' },
	{ pattern: /\bnpm\s+run\s+build\b/, label: 'npm run build' },
	{ pattern: /\bnpm\s+prune\b/, label: 'npm prune' },
	{ pattern: /\bnpm\s+run\s+pack:/, label: 'npm run pack:*' },
	{ pattern: /\bmcpb\s+pack\b/, label: 'mcpb pack' },
	{ pattern: /\btsc\b/, label: 'tsc' },
	{ pattern: /\bdocker\s+build\b/, label: 'docker build' },
]

const PUBLISH_JOBS = [
	['mcp-release.yml', release, 'publish-npm'],
	['mcp-release.yml', release, 'github-release'],
	['mcp-docker.yml', docker, 'publish'],
]

describe('one validation job owns every build', () => {
	it('routes the release through the reusable CI workflow rather than its own steps', () => {
		const validate = job(release, 'validate')
		assert.match(validate, /uses:\s*\.\/\.github\/workflows\/mcp-ci\.yml/)
	})

	it('makes manual Docker dispatch run the same validation graph', () => {
		const validate = job(docker, 'validate')
		assert.match(validate, /uses:\s*\.\/\.github\/workflows\/mcp-ci\.yml/)
		assert.match(validate, /if:\s*github\.event_name == 'workflow_dispatch'/)
	})

	it('packages and inspects each artefact exactly once', () => {
		const pkg = job(ci, 'package')
		for (const command of [
			/npm pack --dry-run/,
			/npm run pack:release/,
			/inspect-npm-pack\.mjs/,
			/inspect-mcpb\.mjs/,
		]) {
			assert.match(pkg, command, `packaging job must run ${command}`)
		}
		// One pack, not two.
		assert.equal((pkg.match(/npm run pack:release/g) ?? []).length, 1)
	})

	it('runs every release validation the plan requires', () => {
		const pkg = job(ci, 'package')
		const test = job(ci, 'test')
		const quality = job(ci, 'quality')

		assert.match(quality, /npm run typecheck/)
		assert.match(quality, /npm run typecheck:tests/)
		assert.match(quality, /npm run lint/)
		assert.match(test, /npm run test:unit/)
		assert.match(test, /npm run test:tooling/)

		for (const command of [
			/count-mcp-tools\.mjs --check/,
			/build-api-coverage\.mjs --check/,
			/npm run check:contract/,
			/npm run check:manifest/,
			/npm run build/,
			/npm run check:compatibility/,
			/npm run check:routes/,
		]) {
			assert.match(pkg, command, `packaging job must run ${command}`)
		}
	})

	it('uploads the artefacts as an immutable handoff', () => {
		const pkg = job(ci, 'package')
		assert.match(pkg, /uses:\s*actions\/upload-artifact@v4/)
		assert.match(pkg, /if-no-files-found:\s*error/)
		assert.match(pkg, /path:\s*fluentcart-mcp\/dist-packages\//)
	})

	it('proves packaging did not mutate the dependency tree', () => {
		assert.match(job(ci, 'package'), /git diff --exit-code -- package-lock\.json/)
	})

	it('exposes the validated identity so callers need no second checkout', () => {
		for (const output of ['version', 'source_sha', 'artifact_name']) {
			assert.match(ci, new RegExp(`^\\s{6}${output}:`, 'm'), `mcp-ci must output ${output}`)
		}
	})
})

describe('no publication job rebuilds', () => {
	for (const [file, workflow, name] of PUBLISH_JOBS) {
		it(`${file}:${name} runs no build or packaging command`, () => {
			const body = job(workflow, name)
			for (const { pattern, label } of REBUILD_COMMANDS) {
				assert.ok(!pattern.test(body), `${file}:${name} must not run ${label}`)
			}
		})

		it(`${file}:${name} does not check out the source`, () => {
			const body = job(workflow, name)
			assert.ok(
				!/uses:\s*actions\/checkout/.test(body),
				`${file}:${name} must consume artefacts, not a fresh checkout`,
			)
		})

		it(`${file}:${name} downloads the validated artefact`, () => {
			assert.match(job(workflow, name), /uses:\s*actions\/download-artifact@v4/)
		})
	}

	it('publishes the inspected tarball rather than the working directory', () => {
		const body = job(release, 'publish-npm')
		assert.match(body, /npm publish "\$TARBALL"/)
		assert.match(body, /dist-packages\/fluentcart-mcp-\$\{VERSION\}\.tgz/)
		// A bare `npm publish` would pack the checkout again.
		assert.ok(!/npm publish\s+--/.test(body), 'npm publish must name the validated tarball')
	})

	it('attaches the validated MCPB and checksums to the GitHub release', () => {
		const body = job(release, 'github-release')
		assert.match(body, /dist-packages\/fluentcart-mcp\.mcpb/)
		assert.match(body, /dist-packages\/SHA256SUMS\.json/)
	})
})

describe('the MCPB packer is never installed globally', () => {
	for (const { name, body } of ALL_WORKFLOWS) {
		it(`${name} installs no global mcpb`, () => {
			// Upgrading npm itself is exempt: trusted publishing needs npm 11.5.1+ and Node 22
			// ships npm 10.x. That is bumping the package manager, not pulling in a build tool
			// at publish time, which is what this rule exists to stop.
			const globalInstalls = [...body.matchAll(/npm\s+(?:install|i)\s+-g\s+(\S+)/g)].map(
				(m) => m[1],
			)
			const disallowed = globalInstalls.filter((pkg) => !/^npm@/.test(pkg))
			assert.deepEqual(disallowed, [], `${name} must not install global packages: ${disallowed}`)
			assert.ok(
				!(/@anthropic-ai\/mcpb(?!")/.test(body) && /-g/.test(body)),
				`${name} must use the lockfile-pinned mcpb`,
			)
		})
	}
})

describe('publication depends on validation', () => {
	it('gates every release publication on the validation job', () => {
		for (const name of ['version-gate', 'docker', 'publish-npm', 'github-release']) {
			assert.ok(needs(job(release, name)).includes('validate'), `${name} must need validate`)
		}
	})

	it('proves the image before npm publication becomes irreversible', () => {
		assert.ok(
			needs(job(release, 'publish-npm')).includes('docker'),
			'npm publish must wait for a built and smoked image',
		)
	})

	it('creates the GitHub release only after both publications succeed', () => {
		const dependencies = needs(job(release, 'github-release'))
		assert.ok(dependencies.includes('publish-npm'))
		assert.ok(dependencies.includes('docker'))
	})

	it('checks the tag against the packaged version, not the working tree', () => {
		const gate = job(release, 'version-gate')
		assert.match(gate, /needs\.validate\.outputs\.version/)
		assert.ok(!/uses:\s*actions\/checkout/.test(gate), 'the version gate needs no checkout')
	})

	it('pushes an image only after it was built and smoked', () => {
		assert.deepEqual(needs(job(docker, 'publish')), ['verify'])
		assert.match(job(docker, 'publish'), /if:\s*inputs\.publish/)
	})
})

describe('Docker consumes the validated context', () => {
	it('builds from the checksummed archive with the recorded source SHA', () => {
		const verify = job(docker, 'verify')
		assert.match(verify, /build-validated-docker-image\.mjs/)
		assert.match(verify, /--context dist-packages\/fluentcart-mcp-docker-context\.tar\.gz/)
		assert.match(verify, /--checksums dist-packages\/SHA256SUMS\.json/)
		assert.match(verify, /--source-sha/)
	})

	it('hands the built image to publication instead of rebuilding it', () => {
		assert.match(job(docker, 'verify'), /docker save/)
		assert.match(job(docker, 'publish'), /docker load/)
	})

	it('no longer runs a second, tag-triggered build path', () => {
		assert.ok(!/^on:[\s\S]*?\bpush:/m.test(docker), 'the tag path belongs to mcp-release.yml alone')
		assert.match(docker, /workflow_call:/)
	})

	it('tags with the validated version and the source SHA', () => {
		const verify = job(docker, 'verify')
		const publish = job(docker, 'publish')
		assert.match(verify, /^\s{6}source_sha:\s*\$\{\{\s*steps\.build\.outputs\.source_sha\s*\}\}/m)
		assert.match(publish, /needs\.verify\.outputs\.version/)
		assert.match(publish, /SOURCE_SHA:\s*\$\{\{\s*needs\.verify\.outputs\.source_sha\s*\}\}/)
		assert.match(publish, /docker push/)
	})

	it('claims only the architecture it builds', () => {
		for (const { name, body } of ALL_WORKFLOWS) {
			assert.ok(!/arm64/.test(body), `${name} must not claim arm64; only linux/amd64 is built`)
		}
	})
})

describe('the container smoke proves the security contract', () => {
	const verify = job(docker, 'verify')

	it('proves the server refuses to start with no key and with a short key', () => {
		assert.match(verify, /Refuses to start without a key/)
		assert.match(verify, /Refuses to start with a short key/)
		assert.match(verify, /FLUENTCART_MCP_API_KEY=too-short/)
	})

	it('proves host-port reachability through the published port', () => {
		assert.match(verify, /-p 127\.0\.0\.1:3000:3000/)
		assert.match(verify, /curl -fsS http:\/\/127\.0\.0\.1:3000\/health/)
	})

	it('proves an unauthorised request is refused with 401', () => {
		assert.match(verify, /UNAUTH=/)
		assert.match(verify, /"\$UNAUTH" != "401"/)
	})

	it('proves a wrong bearer is refused and the right one completes an MCP exchange', () => {
		assert.match(verify, /BADKEY=/)
		assert.match(verify, /smoke-mcp-http\.mjs/)
		assert.match(protocolSmoke, /method:\s*'initialize'/)
		assert.match(protocolSmoke, /method:\s*'notifications\/initialized'/)
		assert.match(protocolSmoke, /method:\s*'tools\/list'/)
		assert.match(protocolSmoke, /serverInfo/)
		for (const name of [
			'fluentcart_search_tools',
			'fluentcart_describe_tools',
			'fluentcart_execute_read_tool',
		]) {
			assert.match(protocolSmoke, new RegExp(name))
		}
	})

	it('runs required image acceptance against the verified SHA', () => {
		assert.match(verify, /node --test fluentcart-mcp\/tests\/acceptance\/docker\.test\.mjs/)
		assert.match(verify, /FLUENTCART_ACCEPTANCE_REQUIRED:\s*'yes'/)
		assert.match(
			verify,
			/FLUENTCART_ACCEPTANCE_SOURCE_SHA:\s*\$\{\{\s*steps\.build\.outputs\.source_sha\s*\}\}/,
		)
		assert.match(dockerAcceptance, /FLUENTCART_ACCEPTANCE_REQUIRED/)
		assert.match(dockerAcceptance, /FLUENTCART_ACCEPTANCE_SOURCE_SHA/)
		assert.match(dockerAcceptance, /assert\.equal\(revision,\s*EXPECTED_SOURCE_SHA/)
	})

	it('injects a key long enough to satisfy the exposure guard', () => {
		const fixture = docker.match(/SMOKE_API_KEY:\s*(\S+)/)
		assert.ok(fixture, 'the smoke must inject a key')
		assert.ok(fixture[1].length >= 32, 'the smoke key must be at least 32 characters')
	})
})

describe('no store credential appears in any workflow', () => {
	const CREDENTIALS = [
		'FLUENTCART_URL',
		'FLUENTCART_USERNAME',
		'FLUENTCART_APP_PASSWORD',
		'FLUENTCART_GUARD_SECRET',
	]

	for (const { name, body } of ALL_WORKFLOWS) {
		it(`${name} sets no store credential`, () => {
			for (const credential of CREDENTIALS) {
				assert.ok(
					!new RegExp(`${credential}\\s*[:=]`).test(body),
					`${name} must not set ${credential}; the deterministic lanes take no store credential`,
				)
			}
		})
	}

	it('keeps the live integration lane out of CI entirely', () => {
		for (const { name, body } of ALL_WORKFLOWS) {
			assert.ok(!/test:integration:local/.test(body), `${name} must not run the live lane`)
		}
	})
})

describe('path filters trigger the contracts that cover the change', () => {
	it('reruns MCP CI when any release workflow changes', () => {
		for (const workflow of ['mcp-ci.yml', 'mcp-release.yml', 'mcp-docker.yml']) {
			assert.ok(ci.includes(`.github/workflows/${workflow}`), `mcp-ci must watch ${workflow}`)
		}
	})

	it('reruns docs CI when Docker metadata or the docs scanner changes', () => {
		for (const path of [
			'fluentcart-mcp/docker-mcp-registry/**',
			'fluentcart-mcp/package.json',
			'fluentcart-mcp/release-contract.json',
			'scripts/check-mcp-docs.mjs',
		]) {
			assert.ok(docs.includes(path), `docs-ci must watch ${path}`)
		}
	})

	it('scans current-facing MCP documentation for stale claims', () => {
		assert.match(docs, /node scripts\/check-mcp-docs\.mjs/)
	})
})

describe('the job graph is what it claims to be', () => {
	it('declares exactly the release jobs the graph needs', () => {
		assert.deepEqual(jobNames(release), [
			'validate',
			'version-gate',
			'docker',
			'publish-npm',
			'github-release',
		])
	})

	it('declares exactly the docker jobs the graph needs', () => {
		assert.deepEqual(jobNames(docker), ['validate', 'verify', 'publish'])
	})

	it('declares exactly the CI jobs the graph needs', () => {
		assert.deepEqual(jobNames(ci), ['quality', 'test', 'package'])
	})
})

describe('npm publication uses trusted publishing', () => {
	const publishJob = release.slice(
		release.indexOf('publish-npm:'),
		release.indexOf('github-release:'),
	)

	it('never sets NODE_AUTH_TOKEN on the publish step', () => {
		// A long-lived token is a standing credential that outlives the job and can publish from
		// anywhere. Trusted publishing swaps it for a short-lived identity bound to this repo and
		// this workflow file, which is only an improvement while no token remains as a fallback.
		assert.doesNotMatch(publishJob, /NODE_AUTH_TOKEN/)
	})

	it('references no npm token secret in the publish job', () => {
		assert.doesNotMatch(publishJob, /secrets\.NPM_TOKEN/)
	})

	it('grants the id-token permission the OIDC exchange needs', () => {
		assert.match(publishJob, /id-token:\s*write/)
	})

	it('upgrades npm, because trusted publishing needs 11.5.1+ and Node 22 ships npm 10', () => {
		// Without this npm looks for a token and fails with ENEEDAUTH rather than exchanging the
		// workflow identity — a failure that reads like a missing secret and invites someone to
		// "fix" it by adding one back.
		assert.match(publishJob, /npm install -g npm@latest/)
	})

	it('publishes the inspected tarball rather than a rebuild', () => {
		assert.match(publishJob, /npm publish "\$TARBALL"/)
		assert.doesNotMatch(publishJob, /npm run build/)
	})

	it('still requests provenance', () => {
		assert.match(publishJob, /--provenance/)
	})
})
