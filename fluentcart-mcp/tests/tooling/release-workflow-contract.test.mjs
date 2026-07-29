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
		assert.deepEqual(jobs(ci), ['quality', 'test', 'conformance', 'protocol', 'package'])
		assert.match(job(ci, 'conformance'), /needs:\s*\[quality,\s*test\]/)
		assert.match(job(ci, 'protocol'), /needs:\s*\[quality,\s*test\]/)
		assert.match(job(ci, 'package'), /needs:\s*\[conformance,\s*protocol\]/)
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
		assert.match(ci, /actions\/upload-artifact@v4/)
		assert.match(ci, /source_tree_digest:/)
		assert.match(ci, /smoke-public-stdio\.mjs/)
	})
})

describe('tag-triggered staging', () => {
	it('stages only after candidate Docker succeeds', () => {
		assert.deepEqual(jobs(stage), [
			'validate',
			'version-gate',
			'docker',
			'stage-npm',
			'verify-public',
		])
		assert.match(job(stage, 'stage-npm'), /needs:\s*\[validate,\s*version-gate,\s*docker\]/)
		assert.match(job(stage, 'stage-npm'), /npm publish "\$TARBALL".*--tag next/)
	})

	it('grants the nested Docker publisher the package permission it cannot elevate itself', () => {
		const body = job(stage, 'docker')
		assert.match(body, /permissions:\s*\n\s+contents:\s*read/)
		assert.match(body, /permissions:[\s\S]*?\n\s+packages:\s*write/)
	})

	it('verifies public checksum, clean install and dual-era stdio before evidence upload', () => {
		const body = job(stage, 'verify-public')
		assert.match(body, /npm pack "fluentcart-mcp@\$\{VERSION\}"/)
		assert.match(body, /sha256sum/)
		assert.match(body, /npm add --ignore-scripts --registry https:\/\/registry\.npmjs\.org/)
		assert.match(body, /smoke-public-stdio\.mjs/)
		assert.match(body, /staging-state\.json/)
	})

	it('never moves latest, creates a public release or rebuilds in a publication job', () => {
		assert.doesNotMatch(stage, /dist-tag add .* latest/)
		assert.doesNotMatch(stage, /gh release create/)
		assert.doesNotMatch(stage, /:latest/)
		for (const name of ['stage-npm', 'verify-public']) {
			assert.doesNotMatch(job(stage, name), /\bnpm ci\b|\bnpm run build\b|\bdocker build\b/)
		}
	})

	it('uses one exact reviewed Node and npm toolchain without installing another CLI', () => {
		assert.doesNotMatch(stage, /\bnpm install\b|npm@latest/)
		assert.match(stage, /node-version:\s*'24\.13\.0'/)
		assert.match(stage, /test "\$\(npm --version\)" = "11\.6\.2"/)
		assert.ok(stage.indexOf('npm --version') < stage.indexOf('npm publish'))
	})
})

describe('versioned Docker candidate', () => {
	it('checks out before downloading the validated handoff so checkout cannot delete it', () => {
		const verify = job(docker, 'verify')
		const checkout = verify.indexOf('actions/checkout@v4')
		const download = verify.indexOf('actions/download-artifact@v4')
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
		assert.match(job(docker, 'publish'), /docker load/)
		assert.match(job(docker, 'publish'), /"\$\{TARGET\}:\$\{VERSION\}"/)
		assert.doesNotMatch(job(docker, 'publish'), /SHORT_SHA|:latest/)
		assert.doesNotMatch(job(docker, 'publish'), /docker build\b/)
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
	it('requires exact dispatch inputs and a protected production environment', () => {
		assert.match(promote, /workflow_dispatch:/)
		for (const input of ['version', 'source_sha', 'staging_run_id']) {
			assert.match(promote, new RegExp(`${input}:\\n[\\s\\S]*?required: true`))
		}
		assert.match(job(promote, 'promote'), /environment:\s*mcp-production/)
	})

	it('redownloads the exact run and verifies npm plus both versioned image digests', () => {
		const body = job(promote, 'promote')
		assert.match(body, /run-id:\s*\$\{\{\s*inputs\.staging_run_id\s*\}\}/)
		assert.match(body, /staging identity mismatch/)
		assert.match(body, /npm view "fluentcart-mcp@\$\{VERSION\}" dist\.integrity/)
		assert.equal((body.match(/imagetools inspect/g) ?? []).length, 2)
	})

	it('verifies every staged byte and both inspectors before exposing credentials or mutating tags', () => {
		const body = job(promote, 'promote')
		assert.match(body, /verify-staged-release\.mjs/)
		assert.match(body, /SHA256SUMS\.json/)
		assert.match(body, /inspect-npm-pack\.mjs/)
		assert.match(body, /inspect-mcpb\.mjs/)
		const verification = body.indexOf('verify-staged-release.mjs')
		for (const mutation of [
			'docker/login-action',
			'NODE_AUTH_TOKEN',
			'npm dist-tag add',
			'imagetools create',
			'gh release create',
		]) {
			assert.ok(
				verification < body.indexOf(mutation),
				`${mutation} precedes local evidence verification`,
			)
		}
	})

	it('retags the same digests, then creates the release and removes next', () => {
		const body = job(promote, 'promote')
		assert.match(body, /npm dist-tag add "fluentcart-mcp@\$\{VERSION\}" latest/)
		assert.equal((body.match(/imagetools create/g) ?? []).length, 2)
		assert.match(body, /gh release create/)
		assert.match(body, /npm dist-tag rm fluentcart-mcp next/)
		assert.doesNotMatch(body, /\bnpm ci\b|\bnpm run build\b|\bdocker build\b|\bpack:release\b/)
		assert.doesNotMatch(body, /actions\/checkout/)
	})
})

describe('workflow credential boundary', () => {
	it('uses npm trusted publishing and no long-lived npm token', () => {
		assert.match(job(stage, 'stage-npm'), /id-token:\s*write/)
		assert.match(job(stage, 'stage-npm'), /--provenance/)
		assert.doesNotMatch(stage, /NPM_TOKEN|NODE_AUTH_TOKEN/)
		assert.match(promote, /secrets\.NPM_PROMOTION_TOKEN/)
		assert.match(promote, /NODE_AUTH_TOKEN/)
		assert.doesNotMatch(promote, /secrets\.NPM_TOKEN/)
	})

	it('never injects a real store credential into deterministic release jobs', () => {
		for (const workflow of [ci, stage, docker, promote]) {
			assert.doesNotMatch(workflow, /FLUENTCART_APP_PASSWORD\s*[:=]/)
			assert.doesNotMatch(workflow, /FLUENTCART_URL\s*[:=]/)
		}
	})

	it('uses the reviewed promotion toolchain without npm install or a mutable npm selector', () => {
		assert.doesNotMatch(promote, /\bnpm install\b|npm@latest/)
		assert.match(promote, /node-version:\s*'24\.13\.0'/)
		assert.match(promote, /test "\$\(npm --version\)" = "11\.6\.2"/)
		assert.ok(promote.indexOf('npm --version') < promote.indexOf('github-token'))
		assert.ok(promote.indexOf('npm --version') < promote.indexOf('NPM_PROMOTION_TOKEN'))
	})
})
