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
  --http-profile <local|private>  HTTP exposure profile (default: local)
  --allowed-hosts <host,...>  Private profile Host allowlist (hostnames only)
  --allowed-origins <host,...>  Private profile Origin allowlist (hostnames only)

Environment variables:
  FLUENTCART_URL              WordPress site URL
  FLUENTCART_USERNAME         WordPress username
  FLUENTCART_APP_PASSWORD     WordPress Application Password
  FLUENTCART_MCP_API_KEY      Private HTTP bearer key (32+ UTF-8 bytes)
  FLUENTCART_MCP_ALLOWED_HOSTS  Private Host allowlist (comma-separated hostnames)
  FLUENTCART_MCP_ALLOWED_ORIGINS  Private Origin allowlist (comma-separated hostnames)

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
	const profile = getFlag('http-profile', 'local')
	const allowedHosts = getFlag('allowed-hosts', process.env.FLUENTCART_MCP_ALLOWED_HOSTS ?? '')
	const allowedOrigins = getFlag(
		'allowed-origins',
		process.env.FLUENTCART_MCP_ALLOWED_ORIGINS ?? '',
	)
	const { startHttpServer } = await import('./transport/http.js')
	const { parseHttpProfile, resolveHttpExposure } = await import('./transport/http-config.js')
	const { installSignalShutdown } = await import('./transport/lifecycle.js')
	try {
		const exposure = resolveHttpExposure({
			profile: parseHttpProfile(profile),
			host,
			...(allowedHosts !== '' && { allowedHosts: allowedHosts.split(',') }),
			...(allowedOrigins !== '' && { allowedOrigins: allowedOrigins.split(',') }),
			bearerKey: process.env.FLUENTCART_MCP_API_KEY,
		})
		const handle = await startHttpServer(port, exposure, mode)
		installSignalShutdown(handle)
	} catch (error) {
		// A refused exposure is a configuration answer, not a crash. Print the reason only.
		console.error(error instanceof Error ? error.message : String(error))
		process.exit(1)
	}
} else {
	const { serveStdio } = await import('@modelcontextprotocol/server/stdio')
	const { createMcpServerFactory, resolveRuntimeContext } = await import('./server.js')
	const { installSignalShutdown, reportOperationalError } = await import('./transport/lifecycle.js')

	try {
		const runtime = await resolveRuntimeContext(mode)
		const factory = createMcpServerFactory(runtime, mode)
		const handle = serveStdio(factory, {
			legacy: 'serve',
			onerror: reportOperationalError,
		})
		installSignalShutdown(handle)
	} catch (error) {
		// Nothing is connected yet, so this line is the entire failure report the user gets — in a
		// desktop client it is the whole crash log. Say what could not be done, not just what threw.
		const detail = error instanceof Error ? error.message : String(error)
		console.error(`fluentcart-mcp could not start: ${detail}`)
		process.exit(1)
	}
}
