#!/usr/bin/env node

import assert from 'node:assert/strict'
import { existsSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { loadEnvFile } from 'node:process'
import { fileURLToPath } from 'node:url'
import { Client } from '@modelcontextprotocol/client'
import { StdioClientTransport } from '@modelcontextprotocol/client/stdio'
import { LEGACY_PROTOCOL, MODERN_PROTOCOL } from './protocol-wire.mjs'

const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
const SUPPORTED_PROTOCOLS = new Set([LEGACY_PROTOCOL, MODERN_PROTOCOL])

export function parseCallToolArgs(argv) {
	const positional = []
	let protocol = MODERN_PROTOCOL
	for (let index = 0; index < argv.length; index += 1) {
		if (argv[index] === '--protocol') {
			protocol = argv[index + 1]
			if (!protocol) throw new Error('--protocol requires a revision')
			index += 1
		} else {
			positional.push(argv[index])
		}
	}
	if (!SUPPORTED_PROTOCOLS.has(protocol)) {
		throw new Error(
			`unsupported protocol ${protocol}; expected ${LEGACY_PROTOCOL} or ${MODERN_PROTOCOL}`,
		)
	}
	if (positional.length < 1 || positional.length > 2) {
		throw new Error(
			'usage: call-tool.mjs <tool-name> [json-arguments] [--protocol 2025-11-25|2026-07-28]',
		)
	}
	let input
	try {
		input = JSON.parse(positional[1] ?? '{}')
	} catch (error) {
		throw new Error(`tool arguments are not valid JSON: ${error.message}`)
	}
	if (!input || typeof input !== 'object' || Array.isArray(input)) {
		throw new Error('tool arguments must be a JSON object')
	}
	return { toolName: positional[0], arguments: input, protocol }
}

export function clientOptionsForProtocol(protocol) {
	assert.ok(SUPPORTED_PROTOCOLS.has(protocol), `unsupported protocol ${protocol}`)
	return {
		capabilities: {},
		supportedProtocolVersions: [protocol],
		versionNegotiation: {
			mode: protocol === MODERN_PROTOCOL ? { pin: MODERN_PROTOCOL } : 'legacy',
		},
	}
}

async function main() {
	const options = parseCallToolArgs(process.argv.slice(2))
	const envPath = join(PACKAGE_ROOT, '.env')
	if (existsSync(envPath)) loadEnvFile(envPath)

	const client = new Client(
		{ name: 'fluentcart-mcp-call-tool', version: '1.0.0' },
		clientOptionsForProtocol(options.protocol),
	)
	const transport = new StdioClientTransport({
		command: process.execPath,
		args: [join(PACKAGE_ROOT, 'dist/index.js')],
		cwd: PACKAGE_ROOT,
		env: { ...process.env },
		stderr: 'inherit',
	})
	try {
		await client.connect(transport, { timeout: 15_000 })
		assert.equal(
			client.getNegotiatedProtocolVersion(),
			options.protocol,
			`server negotiated ${client.getNegotiatedProtocolVersion()} instead of ${options.protocol}`,
		)
		const result = await client.callTool({
			name: options.toolName,
			arguments: options.arguments,
		})
		process.stdout.write(`${JSON.stringify(result, null, 2)}\n`)
		if (result.isError) process.exitCode = 1
	} finally {
		await client.close()
	}
}

const direct = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)
if (direct) {
	main().catch((error) => {
		process.stderr.write(`${error.message}\n`)
		process.exitCode = 1
	})
}
