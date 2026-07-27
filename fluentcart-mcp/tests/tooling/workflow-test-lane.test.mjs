import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..')

const ci = readFileSync(resolve(repoRoot, '.github/workflows/mcp-ci.yml'), 'utf8')
const release = readFileSync(resolve(repoRoot, '.github/workflows/mcp-release.yml'), 'utf8')

describe('workflow test lanes', () => {
	it('runs the named unit lane in CI', () => {
		assert.match(ci, /npm run test:unit/)
	})

	it('runs the named unit lane during release validation', () => {
		// Release does not repeat the steps: it calls mcp-ci.yml as a reusable workflow, so the
		// tag path and the pull-request path validate through exactly one definition. Assert the
		// delegation AND that the callee runs the lane, or a dropped step would pass unnoticed.
		assert.match(
			release,
			/uses:\s*\.\/\.github\/workflows\/mcp-ci\.yml/,
			'release must delegate validation to the CI workflow rather than duplicating it',
		)
		assert.match(ci, /workflow_call/, 'mcp-ci.yml must be callable as a reusable workflow')
		assert.match(ci, /npm run test:unit/)
	})

	it('runs the Node tooling contract lane in CI', () => {
		assert.match(ci, /npm run test:tooling/)
	})

	it('runs the Node tooling contract lane during release validation', () => {
		assert.match(release, /uses:\s*\.\/\.github\/workflows\/mcp-ci\.yml/)
		assert.match(ci, /npm run test:tooling/)
	})

	it('never hands a store application password to CI', () => {
		assert.doesNotMatch(ci, /FLUENTCART_APP_PASSWORD/)
	})

	it('never hands a store application password to release validation', () => {
		assert.doesNotMatch(release, /FLUENTCART_APP_PASSWORD/)
	})

	it('never hands a store username or URL to either workflow', () => {
		for (const [name, workflow] of [
			['mcp-ci.yml', ci],
			['mcp-release.yml', release],
		]) {
			assert.doesNotMatch(
				workflow,
				/FLUENTCART_USERNAME/,
				`${name} must not receive a store username`,
			)
			assert.doesNotMatch(workflow, /FLUENTCART_URL/, `${name} must not receive a store URL`)
		}
	})

	it('never invokes the live integration lane from a workflow', () => {
		for (const [name, workflow] of [
			['mcp-ci.yml', ci],
			['mcp-release.yml', release],
		]) {
			assert.doesNotMatch(
				workflow,
				/test:integration:local|run-live-tests/,
				`${name} must not start the live lane`,
			)
		}
	})

	it('does not run an unqualified vitest that could collect integration files', () => {
		for (const [name, workflow] of [
			['mcp-ci.yml', ci],
			['mcp-release.yml', release],
		]) {
			assert.doesNotMatch(
				workflow,
				/run:\s*npx vitest(?!.*--config)/,
				`${name} must select a lane by npm script, not a bare vitest invocation`,
			)
		}
	})
})
