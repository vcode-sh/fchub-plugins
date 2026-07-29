import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const dockerfiles = ['Dockerfile'].map((name) => ({
	name,
	text: readFileSync(resolve(packageRoot, name), 'utf8'),
}))

function cmdLine(text) {
	const match = /^CMD\s+(\[.*\])\s*$/m.exec(text)
	assert.ok(match, 'Dockerfile must declare an exec-form CMD')
	return JSON.parse(match[1])
}

describe('docker bind contract', () => {
	it('selects the public bind explicitly rather than relying on a default', () => {
		for (const { name, text } of dockerfiles) {
			const cmd = cmdLine(text)
			const hostIndex = cmd.indexOf('--host')
			assert.notEqual(hostIndex, -1, `${name} CMD must pass --host explicitly`)
			assert.equal(
				cmd[hostIndex + 1],
				'0.0.0.0',
				'a container must bind 0.0.0.0 to be reachable through its published port',
			)
		}
	})

	it('uses the http transport', () => {
		for (const { text } of dockerfiles) {
			const cmd = cmdLine(text)
			const transportIndex = cmd.indexOf('--transport')
			assert.notEqual(transportIndex, -1)
			assert.equal(cmd[transportIndex + 1], 'http')
		}
	})

	it('selects the private profile for its non-loopback bind', () => {
		for (const { name, text } of dockerfiles) {
			const cmd = cmdLine(text)
			const profileIndex = cmd.indexOf('--http-profile')
			assert.notEqual(profileIndex, -1, `${name} CMD must pass --http-profile explicitly`)
			assert.equal(cmd[profileIndex + 1], 'private')
		}
	})

	it('never bakes a bearer key, store URL or credential into the image', () => {
		for (const { name, text } of dockerfiles) {
			for (const forbidden of [
				/ENV\s+FLUENTCART_MCP_API_KEY/,
				/ENV\s+FLUENTCART_APP_PASSWORD/,
				/ENV\s+FLUENTCART_USERNAME/,
				/ENV\s+FLUENTCART_URL/,
				/ENV\s+FLUENTCART_GUARD_SECRET/,
			]) {
				assert.doesNotMatch(text, forbidden, `${name} must not bake ${forbidden}`)
			}
		}
	})

	it('does not copy an environment file into the image', () => {
		for (const { name, text } of dockerfiles) {
			assert.doesNotMatch(text, /COPY\s+[^\n]*\.env/, `${name} must not copy a .env file`)
		}
	})

	it('states every injected private-profile requirement', () => {
		for (const { name, text } of dockerfiles) {
			for (const variable of [
				'FLUENTCART_MCP_API_KEY',
				'FLUENTCART_MCP_ALLOWED_HOSTS',
				'FLUENTCART_MCP_ALLOWED_ORIGINS',
			]) {
				assert.match(text, new RegExp(variable), `${name} must document ${variable}`)
			}
		}
	})

	it('does not advertise a tool count that varies by store and policy', () => {
		for (const { text } of dockerfiles) {
			assert.doesNotMatch(text, /200\+|\b274\b|\b279\b/)
		}
	})
})
