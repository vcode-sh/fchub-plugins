#!/usr/bin/env node

import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { Transform } from 'node:stream'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { writeJsonAtomic } from './acceptance/evidence-writer.mjs'

const receipt = process.argv[2]
assert.ok(receipt, 'stdio observer requires a receipt path')
const imageId = process.env.FCMCP_CLIENT_IMAGE_ID
const storeUrl = process.env.FCMCP_CLIENT_STORE_URL
assert.match(imageId ?? '', /^sha256:[a-f0-9]{64}$/, 'stdio observer requires an image ID')
assert.match(storeUrl ?? '', /^http:\/\/host\.docker\.internal:\d+$/, 'stdio observer requires a store URL')

function parser(direction, destination) {
	let pending = ''
	return new Transform({
		transform(chunk, _encoding, callback) {
			pending += chunk.toString()
			const lines = pending.split('\n')
			pending = lines.pop()
			for (const line of lines) observe(direction, line)
			destination.write(chunk)
			callback()
		},
		flush(callback) {
			if (pending) observe(direction, pending)
			callback()
		},
	})
}

let initializeId = null
let recorded = false
function observe(direction, line) {
	try {
		const message = JSON.parse(line)
		if (direction === 'client' && message.method === 'initialize') initializeId = message.id
		if (
			direction === 'server' &&
			initializeId !== null &&
			message.id === initializeId &&
			typeof message.result?.protocolVersion === 'string' &&
			!recorded
		) {
			recorded = true
			writeJsonAtomic(receipt, {
				protocolVersion: message.result.protocolVersion,
				observedAt: new Date().toISOString(),
				candidateImageId: imageId,
			})
		}
	} catch {}
}

const child = spawn(
	'docker',
	[
		'run',
		'--rm',
		'-i',
		'--add-host',
		'host.docker.internal:host-gateway',
		'-e',
		`FLUENTCART_URL=${storeUrl}`,
		'-e',
		'FLUENTCART_USERNAME=fixture',
		'-e',
		'FLUENTCART_APP_PASSWORD=fixture',
		'-e',
		'FLUENTCART_WRITE_MODE=disabled',
		imageId,
		'node',
		'dist/index.js',
		'--transport',
		'stdio',
	],
	{ stdio: ['pipe', 'pipe', 'inherit'] },
)

process.stdin.pipe(parser('client', child.stdin))
child.stdout.pipe(parser('server', process.stdout))
for (const signal of ['SIGINT', 'SIGTERM']) {
	process.once(signal, () => child.kill(signal))
}
child.once('error', (error) => {
	process.stderr.write(`${error.message}\n`)
	process.exitCode = 1
})
child.once('exit', (code, signal) => {
	if (signal) process.kill(process.pid, signal)
	else process.exitCode = code ?? 1
})

const direct = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)
assert.ok(direct, 'stdio observer is executable-only')
