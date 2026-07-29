#!/usr/bin/env node
/** Capture public recovery state once. Generators read the resulting file and never call a registry. */

import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'
import { pathToFileURL } from 'node:url'
import { validateReleaseState } from './release-truth.mjs'

const ACCEPT =
	'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json'

async function json(url) {
	const response = await fetch(url, { headers: { 'User-Agent': 'fluentcart-release-capture/2' } })
	if (!response.ok) throw new Error(`${url} returned HTTP ${response.status}`)
	return response.json()
}

async function digest(url, token) {
	const response = await fetch(url, {
		headers: { Accept: ACCEPT, Authorization: `Bearer ${token}` },
	})
	if (!response.ok) throw new Error(`${url} returned HTTP ${response.status}`)
	const value = response.headers.get('docker-content-digest')
	if (!value) throw new Error(`${url} returned no immutable digest`)
	return value
}

async function dockerDigests() {
	const ghToken = (
		await json('https://ghcr.io/token?scope=repository:vcode-sh/fluentcart-mcp:pull')
	).token
	const dhToken = (
		await json(
			'https://auth.docker.io/token?service=registry.docker.io&scope=repository:vcodesh/fluentcart-mcp:pull',
		)
	).token
	return {
		'ghcr.io': await digest(
			'https://ghcr.io/v2/vcode-sh/fluentcart-mcp/manifests/latest',
			ghToken,
		),
		'docker.io': await digest(
			'https://registry-1.docker.io/v2/vcodesh/fluentcart-mcp/manifests/latest',
			dhToken,
		),
	}
}

function args(argv) {
	const value = (name) => {
		const index = argv.indexOf(name)
		if (index < 0 || !argv[index + 1]) throw new Error(`missing required ${name}`)
		return argv[index + 1]
	}
	return { candidate: value('--candidate'), output: value('--output') }
}

export async function captureReleaseState(candidate, now = new Date().toISOString()) {
	const npm = await json('https://registry.npmjs.org/fluentcart-mcp')
	const tag = await fetch(
		`https://api.github.com/repos/vcode-sh/fchub-plugins/git/ref/tags/fluentcart-mcp/v${candidate}`,
		{ headers: { Accept: 'application/vnd.github+json', 'User-Agent': 'fluentcart-release-capture/2' } },
	)
	if (![200, 404].includes(tag.status)) throw new Error(`GitHub tag lookup returned HTTP ${tag.status}`)
	const state = {
		schemaVersion: 1,
		redacted: true,
		capturedAt: now,
		candidate: {
			version: candidate,
			npmPublished: Object.hasOwn(npm.versions ?? {}, candidate),
			remoteTagPresent: tag.status === 200,
		},
		npm: {
			package: 'fluentcart-mcp',
			registry: 'https://registry.npmjs.org',
			previousLatest: npm['dist-tags']?.latest,
		},
		docker: {
			images: {
				'ghcr.io': 'ghcr.io/vcode-sh/fluentcart-mcp:latest',
				'docker.io': 'docker.io/vcodesh/fluentcart-mcp:latest',
			},
			previousLatestDigests: await dockerDigests(),
		},
	}
	return validateReleaseState(state, candidate)
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
	const { candidate, output } = args(process.argv.slice(2))
	const state = await captureReleaseState(candidate)
	mkdirSync(dirname(output), { recursive: true })
	writeFileSync(output, `${JSON.stringify(state, null, 2)}\n`)
	process.stdout.write(`captured redacted previous-release state for ${candidate} in ${output}\n`)
}
