#!/usr/bin/env node

import { createRequire } from 'node:module'
import type { ToolsetMode } from './server.js'

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
  --mode <static|dynamic>     Toolset mode (default: static)
  --port <number>             HTTP server port (default: 3000)
  --host <address>            HTTP server bind address (default: 0.0.0.0)

Environment variables:
  FLUENTCART_URL              WordPress site URL
  FLUENTCART_USERNAME         WordPress username
  FLUENTCART_APP_PASSWORD     WordPress Application Password

Documentation: https://github.com/vcode-sh/fchub-plugins/tree/main/fluentcart-mcp
`)
	process.exit(0)
}

const transport = getFlag('transport', 'stdio')
const mode = getFlag('mode', 'static') as ToolsetMode

// CLI sub-commands (setup, etc.) only when not using transport flags
if (transport === 'stdio' && args.length > 0 && !args[0]!.startsWith('--')) {
	const { runCli } = await import('./cli/index.js')
	await runCli(args)
	process.exit(0)
}

if (transport === 'http') {
	const port = Number.parseInt(getFlag('port', '3000'), 10)
	const host = getFlag('host', '0.0.0.0')
	const { startHttpServer } = await import('./transport/http.js')
	await startHttpServer(port, host, mode)
} else {
	const { StdioServerTransport } = await import('@modelcontextprotocol/sdk/server/stdio.js')
	const { createServer } = await import('./server.js')

	const server = createServer(mode)
	const stdioTransport = new StdioServerTransport()
	await server.connect(stdioTransport)
}
