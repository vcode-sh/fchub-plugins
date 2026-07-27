import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const dockerfile = readFileSync(resolve(packageRoot, 'Dockerfile'), 'utf8')

function cmdLine(text) {
	const match = /^CMD\s+(\[.*\])\s*$/m.exec(text)
	assert.ok(match, 'Dockerfile must declare an exec-form CMD')
	return JSON.parse(match[1])
}

describe('docker bind contract', () => {
	it('selects the public bind explicitly rather than relying on a default', () => {
		const cmd = cmdLine(dockerfile)
		const hostIndex = cmd.indexOf('--host')
		assert.notEqual(hostIndex, -1, 'CMD must pass --host explicitly')
		assert.equal(
			cmd[hostIndex + 1],
			'0.0.0.0',
			'a container must bind 0.0.0.0 to be reachable through its published port',
		)
	})

	it('uses the http transport', () => {
		const cmd = cmdLine(dockerfile)
		const transportIndex = cmd.indexOf('--transport')
		assert.notEqual(transportIndex, -1)
		assert.equal(cmd[transportIndex + 1], 'http')
	})

	it('never bakes a bearer key, store URL or credential into the image', () => {
		for (const forbidden of [
			/ENV\s+FLUENTCART_MCP_API_KEY/,
			/ENV\s+FLUENTCART_APP_PASSWORD/,
			/ENV\s+FLUENTCART_USERNAME/,
			/ENV\s+FLUENTCART_URL/,
			/ENV\s+FLUENTCART_GUARD_SECRET/,
		]) {
			assert.doesNotMatch(dockerfile, forbidden, `Dockerfile must not bake ${forbidden}`)
		}
	})

	it('does not copy an environment file into the image', () => {
		assert.doesNotMatch(dockerfile, /COPY\s+[^\n]*\.env/, 'Dockerfile must not copy a .env file')
	})

	it('states that the public bind requires an injected bearer key', () => {
		// Because the image binds 0.0.0.0, assertSafeHttpExposure refuses to start without a
		// strong FLUENTCART_MCP_API_KEY. That requirement must be documented at the image.
		assert.match(
			dockerfile,
			/FLUENTCART_MCP_API_KEY/,
			'Dockerfile must document the required bearer key for its public bind',
		)
	})

	it('does not advertise a tool count that varies by store and policy', () => {
		assert.doesNotMatch(dockerfile, /200\+|\b274\b|\b279\b/)
	})
})
