import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..')
const read = (name) =>
	readFileSync(join(ROOT, '.github', 'workflows', name), 'utf8').replace(/^[ \t]*#.*$/gm, '')
const ci = read('mcp-ci.yml')
const stage = read('mcp-release.yml')
const docker = read('mcp-docker.yml')
const promote = read('mcp-promote.yml')
const stageState = readFileSync(
	join(ROOT, 'fluentcart-mcp', 'scripts', 'write-staging-state.mjs'),
	'utf8',
)
const artifactBuilder = readFileSync(
	join(ROOT, 'fluentcart-mcp', 'scripts', 'build-release-artifacts.mjs'),
	'utf8',
)

function jobs(workflow) {
	const start = workflow.search(/^jobs:\s*$/m)
	return [...workflow.slice(start).matchAll(/^ {2}([A-Za-z0-9_-]+):\s*$/gm)].map((m) => m[1])
}

function job(workflow, name) {
	const section = workflow.slice(workflow.search(/^jobs:\s*$/m))
	const start = section.search(new RegExp(`^  ${name}:\\s*$`, 'm'))
	assert.notEqual(start, -1, `missing ${name} job`)
	const body = section.slice(start + name.length + 3)
	const end = body.search(/^ {2}[A-Za-z0-9_-]+:\s*$/m)
	return end < 0 ? body : body.slice(0, end)
}

describe('single-build candidate graph', () => {
	it('gates package on independent conformance and protocol jobs', () => {
		assert.deepEqual(jobs(ci), [
			'quality',
			'test',
			'sdk-current',
			'conformance',
			'protocol',
			'package',
		])
		assert.match(job(ci, 'conformance'), /needs:\s*\[quality,\s*test\]/)
		assert.match(job(ci, 'protocol'), /needs:\s*\[quality,\s*test\]/)
		assert.match(job(ci, 'package'), /needs:\s*\[conformance,\s*protocol,\s*sdk-current\]/)
	})

	it('runs named quality, unit, tooling, acceptance, conformance and dual-era lanes', () => {
		for (const command of [
			'npm run typecheck',
			'npm run typecheck:tests',
			'npm run lint',
			'npm run test:unit',
			'npm run test:tooling',
			'npm run test:acceptance',
			'npm run test:conformance',
			'stdio-dual-era.test.mjs',
			'http-dual-era.test.mjs',
		]) {
			assert.match(ci, new RegExp(command.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
		}
	})

	it('packages once with the explicit captured state and uploads the exact handoff', () => {
		assert.equal((ci.match(/npm run pack:release/g) ?? []).length, 1)
		assert.match(ci, /--release-state tests\/fixtures\/releases\/previous-release-state\.json/)
		assert.match(ci, /actions\/upload-artifact@v7/)
		assert.match(ci, /source_tree_digest:/)
		assert.match(ci, /smoke-public-stdio\.mjs/)
		assert.match(artifactBuilder, /'scripts\/smoke-public-stdio\.mjs'/)
	})
})

describe('tag-triggered publication', () => {
	it('publishes through OIDC only after candidate Docker succeeds, then promotes automatically', () => {
		assert.deepEqual(jobs(stage), [
			'validate',
			'sdk-current',
			'version-gate',
			'docker',
			'publish-npm',
			'promote',
		])
		assert.match(job(stage, 'publish-npm'), /needs:\s*\[validate,\s*version-gate,\s*docker\]/)
		assert.match(job(stage, 'publish-npm'), /TARBALL="\.\/dist-packages\//)
		assert.match(
			job(stage, 'publish-npm'),
			/npm publish "\$TARBALL".*--provenance.*--access public.*--tag latest/,
		)
		assert.match(job(stage, 'promote'), /needs:\s*\[validate,\s*publish-npm\]/)
		assert.match(job(stage, 'promote'), /uses:\s*\.\/\.github\/workflows\/mcp-promote\.yml/)
		assert.match(job(stage, 'promote'), /staging_run_id:\s*\$\{\{\s*github\.run_id\s*\}\}/)
	})

	it('grants the nested Docker publisher the package permission it cannot elevate itself', () => {
		const body = job(stage, 'docker')
		assert.match(body, /permissions:\s*\n\s+contents:\s*read/)
		assert.match(body, /permissions:[\s\S]*?\n\s+packages:\s*write/)
	})

	it('records local npm integrity before evidence upload', () => {
		const body = job(stage, 'publish-npm')
		assert.match(body, /write-staging-state\.mjs/)
		assert.match(body, /actions\/upload-artifact@v7/)
		assert.doesNotMatch(body, /npm view|npm pack "fluentcart-mcp@/)
		assert.match(stageState, /direct/)
		assert.match(stageState, /expectedIntegrity/)
		assert.match(stageState, /staging-state\.json/)
		assert.match(stageState, /createHash\('sha512'\)|sha\(tarballBytes,\s*'sha512'/)
	})

	it('never rebuilds in the npm publication job', () => {
		assert.doesNotMatch(stage, /dist-tag add .* latest/)
		assert.doesNotMatch(job(stage, 'publish-npm'), /\bnpm ci\b|\bnpm run build\b|\bdocker build\b/)
	})

	it('uses a pinned reviewed npm CLI for trusted publication', () => {
		const body = job(stage, 'publish-npm')
		assert.doesNotMatch(body, /npm@latest|registry-url/)
		assert.match(body, /node-version:\s*'26\.7\.0'/)
		assert.match(body, /npm install --global npm@11\.19\.0/)
		assert.match(body, /test "\$\(npm --version\)" = "11\.19\.0"/)
		assert.match(body, /--registry https:\/\/registry\.npmjs\.org/)
		assert.ok(body.indexOf('npm --version') < body.indexOf('npm publish'))
	})
})

describe('versioned Docker candidate', () => {
	it('requires the authenticated dual-era candidate smoke before image acceptance', () => {
		const verify = job(docker, 'verify')
		assert.match(verify, /Private candidate speaks authenticated dual-era MCP/)
		assert.match(verify, /scripts\/smoke-mcp-http\.mjs/)
		assert.match(verify, /FLUENTCART_ACCEPTANCE_REQUIRED:\s*'yes'/)
	})

	it('checks out before downloading the validated handoff so checkout cannot delete it', () => {
		const verify = job(docker, 'verify')
		const checkout = verify.indexOf('actions/checkout@v7')
		const download = verify.indexOf('actions/download-artifact@v8')
		const build = verify.indexOf('Build candidate from validated context')
		assert.ok(checkout < download)
		assert.ok(download < build)
	})

	it('installs the locked script dependencies before building the validated image', () => {
		const verify = job(docker, 'verify')
		assert.match(verify, /fluentcart-mcp\/package-lock\.json/)
		assert.match(verify, /npm ci --prefix fluentcart-mcp/)
		assert.ok(
			verify.indexOf('npm ci --prefix fluentcart-mcp') <
				verify.indexOf('Build candidate from validated context'),
		)
	})

	it('proves missing key and allowlists fail before the happy path', () => {
		const verify = job(docker, 'verify')
		assert.match(verify, /Refuses missing private key before listen/)
		assert.match(verify, /Refuses missing private allowlists before listen/)
		assert.match(verify, /FLUENTCART_MCP_ALLOWED_HOSTS/)
		assert.match(verify, /FLUENTCART_MCP_ALLOWED_ORIGINS/)
	})

	it('hands a saved image to publication and pushes no mutable or short-SHA tags', () => {
		assert.match(job(docker, 'verify'), /docker save/)
		const publish = job(docker, 'publish')
		assert.match(publish, /if:\s*\$\{\{\s*always\(\)/)
		assert.match(publish, /inputs\.publish/)
		assert.match(publish, /needs\.verify\.result\s*==\s*'success'/)
		assert.match(publish, /docker load/)
		assert.match(publish, /"\$\{TARGET\}:\$\{VERSION\}"/)
		assert.doesNotMatch(publish, /SHORT_SHA|:latest/)
		assert.doesNotMatch(publish, /docker build\b/)
	})

	it('records immutable public digests as reusable workflow outputs', () => {
		assert.match(docker, /ghcr_digest:/)
		assert.match(docker, /dockerhub_digest:/)
		assert.match(job(docker, 'publish'), /imagetools inspect/)
	})

	it('validates reusable source_sha through an environment variable before shell use', () => {
		const verify = job(docker, 'verify')
		assert.match(verify, /SOURCE_SHA:\s*\$\{\{\s*inputs\.source_sha/)
		assert.match(verify, /\^\[0-9a-f\]\{40\}\$/)
		for (const run of verify.matchAll(/run:\s*\|([\s\S]*?)(?=\n {6}- |\n {2}[A-Za-z]|\s*$)/g)) {
			assert.doesNotMatch(run[1], /\$\{\{\s*inputs\./)
		}
		assert.match(verify, /--source-sha "\$SOURCE_SHA"/)
	})
})

describe('owner evidence-bound promotion', () => {
	it('supports automatic workflow calls and exact owner recovery dispatches', () => {
		assert.match(promote, /workflow_call:/)
		assert.match(promote, /workflow_dispatch:/)
		for (const input of ['version', 'source_sha', 'staging_run_id']) {
			assert.match(promote, new RegExp(`${input}:\\n[\\s\\S]*?required: true`))
		}
		assert.match(job(promote, 'promote'), /environment:\s*mcp-production/)
	})

	it('redownloads the exact run and verifies approved npm latest plus both image digests', () => {
		const body = job(promote, 'promote')
		assert.match(body, /run-id:\s*\$\{\{\s*inputs\.staging_run_id\s*\}\}/)
		assert.match(body, /staging identity mismatch/)
		assert.match(body, /npm view "fluentcart-mcp@\$\{VERSION\}" dist\.integrity/)
		assert.match(body, /npm view fluentcart-mcp dist-tags\.latest/)
		assert.equal((body.match(/imagetools inspect/g) ?? []).length, 4)
	})

	it('verifies every staged byte and both inspectors before mutable container tags', () => {
		const body = job(promote, 'promote')
		assert.match(body, /verify-staged-release\.mjs/)
		assert.match(body, /SHA256SUMS\.json/)
		assert.match(body, /inspect-npm-pack\.mjs/)
		assert.match(body, /inspect-mcpb\.mjs/)
		const verification = body.indexOf('verify-staged-release.mjs')
		for (const mutation of ['docker/login-action', 'imagetools create', 'gh release create']) {
			assert.ok(
				verification < body.indexOf(mutation),
				`${mutation} precedes local evidence verification`,
			)
		}
	})

	it('verifies public bytes, retags the same image digests and creates the release', () => {
		const body = job(promote, 'promote')
		assert.match(body, /npm pack "fluentcart-mcp@\$\{VERSION\}"/)
		assert.match(body, /npm add --ignore-scripts --registry https:\/\/registry\.npmjs\.org/)
		assert.match(body, /verification\/scripts\/smoke-public-stdio\.mjs/)
		assert.match(
			body,
			/repos\/\$\{GITHUB_REPOSITORY\}\/contents\/fluentcart-mcp\/scripts\/smoke-public-stdio\.mjs\?ref=\$\{SOURCE_SHA\}/,
		)
		assert.match(body, /Accept: application\/vnd\.github\.raw\+json/)
		assert.match(body, /test "\$SCHEMA_VERSION" = "2"/)
		assert.match(body, /cp "\$SMOKE" clean\/smoke-public-stdio\.mjs/)
		assert.equal((body.match(/imagetools create/g) ?? []).length, 2)
		assert.equal((body.match(/--prefer-index=false/g) ?? []).length, 2)
		assert.match(body, /test "\$GHCR_LATEST" = "\$GHCR_DIGEST"/)
		assert.match(body, /test "\$DOCKERHUB_LATEST" = "\$DOCKERHUB_DIGEST"/)
		assert.match(body, /gh release view "\$TAG"/)
		assert.match(body, /gh release download "\$TAG"/)
		assert.match(body, /cmp "\$ASSET" "\$RELEASE_DIR\/\$ASSET"/)
		assert.match(body, /gh release create/)
		assert.doesNotMatch(body, /npm dist-tag/)
		assert.doesNotMatch(body, /\bnpm ci\b|\bnpm run build\b|\bdocker build\b|\bpack:release\b/)
		assert.doesNotMatch(body, /actions\/checkout/)
	})
})

describe('workflow credential boundary', () => {
	it('uses direct trusted publishing and no stored npm credential', () => {
		assert.match(job(stage, 'publish-npm'), /id-token:\s*write/)
		assert.match(job(stage, 'publish-npm'), /npm publish/)
		assert.match(job(stage, 'publish-npm'), /--provenance/)
		assert.doesNotMatch(stage, /NPM_TOKEN|NPM_PROMOTION_TOKEN|NODE_AUTH_TOKEN|npm dist-tag/)
		assert.doesNotMatch(
			promote,
			/NPM_TOKEN|NPM_PROMOTION_TOKEN|NODE_AUTH_TOKEN|npm dist-tag|npm publish/,
		)
	})

	it('never injects a real store credential into deterministic release jobs', () => {
		for (const workflow of [ci, stage, docker, promote]) {
			assert.doesNotMatch(workflow, /FLUENTCART_APP_PASSWORD\s*[:=]/)
			assert.doesNotMatch(workflow, /FLUENTCART_URL\s*[:=]/)
		}
	})

	it('uses the reviewed promotion toolchain without npm install or a mutable npm selector', () => {
		const body = job(promote, 'promote')
		assert.doesNotMatch(body, /npm install --global|npm@latest|registry-url/)
		assert.match(body, /node-version:\s*'26\.7\.0'/)
		assert.match(body, /test "\$\(npm --version\)" = "11\.19\.0"/)
		assert.ok(body.indexOf('npm --version') < body.indexOf('github-token'))
	})
})
