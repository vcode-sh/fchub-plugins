#!/usr/bin/env node

import { createRequire } from 'node:module'
import { parseToolsetMode } from './server.js'

const require = createRequire(import.meta.url)
const { version } = require('../package.json') as { version: string }

const args = process.argv.slice(2)

function getFlag(name: string, fallback: string): string {
	const prefix = `--${name}=`
	const idx = args.findIndex((a) => a.startsWith(prefix) || a === `--${name}`)
	if (idx === -1) return fallback
	const arg = args[idx]!
	if (arg.startsWith(prefix)) return arg.slice(prefix.length)
	return args[idx + 1] ?? fallback
}

if (args.includes('--version') || args.includes('-v')) {
	console.log(version)
	process.exit(0)
}

if (args.includes('--help') || args.includes('-h')) {
	console.log(`
fluentcart-mcp — MCP server for the FluentCart REST API

Usage:
  fluentcart-mcp              Start the MCP server (stdio transport)
  fluentcart-mcp setup        Interactive setup wizard
  fluentcart-mcp --version    Show version
  fluentcart-mcp --help       Show this help

Options:
  --transport <stdio|http>    Transport mode (default: stdio)
  --mode <dynamic|curated|code|full>  Toolset mode (default: dynamic)
  --port <number>             HTTP server port (default: 3000)
  --host <address>            HTTP server bind address (default: 127.0.0.1)

Binding a non-loopback address requires FLUENTCART_MCP_API_KEY (32+ characters);
the server refuses to start otherwise.

Environment variables:
  FLUENTCART_URL              WordPress site URL
  FLUENTCART_USERNAME         WordPress username
  FLUENTCART_APP_PASSWORD     WordPress Application Password

Documentation: https://github.com/vcode-sh/fchub-plugins/tree/main/fluentcart-mcp
`)
	process.exit(0)
}

const transport = getFlag('transport', 'stdio')
const mode = parseToolsetMode(getFlag('mode', ''))

// CLI sub-commands (setup, etc.) only when not using transport flags
if (transport === 'stdio' && args.length > 0 && !args[0]!.startsWith('--')) {
	const { runCli } = await import('./cli/index.js')
	await runCli(args)
	process.exit(0)
}

if (transport === 'http') {
	const port = Number.parseInt(getFlag('port', '3000'), 10)
	const host = getFlag('host', '127.0.0.1')
	const { startHttpServer } = await import('./transport/http.js')
	try {
		await startHttpServer(port, host, mode)
	} catch (error) {
		// A refused exposure is a configuration answer, not a crash. Print the reason only.
		console.error(error instanceof Error ? error.message : String(error))
		process.exit(1)
	}
} else {
	const { StdioServerTransport } = await import('@modelcontextprotocol/sdk/server/stdio.js')
	const { createServerFromContextAsync, resolveServerContextAsync } = await import('./server.js')

	try {
		// Discover the store's real routes before any transport is connected, so a store that
		// cannot describe itself produces a startup error rather than a half-working session.
		const context = await resolveServerContextAsync()
		const server = await createServerFromContextAsync(context, mode)
		const stdioTransport = new StdioServerTransport()
		await server.connect(stdioTransport)
	} catch (error) {
		// Nothing is connected yet, so this line is the entire failure report the user gets — in a
		// desktop client it is the whole crash log. Say what could not be done, not just what threw.
		const detail = error instanceof Error ? error.message : String(error)
		console.error(`fluentcart-mcp could not start: ${detail}`)
		process.exit(1)
	}
}
