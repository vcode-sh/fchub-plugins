#!/usr/bin/env node

const args = process.argv.slice(2)

if (args.includes('--version') || args.includes('-v')) {
	console.log('0.1.0')
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

Environment variables:
  FLUENTCART_URL              WordPress site URL
  FLUENTCART_USERNAME         WordPress username
  FLUENTCART_APP_PASSWORD     WordPress Application Password

Documentation: https://github.com/vcode-sh/fluentcart-mcp
`)
	process.exit(0)
}

if (args.length > 0) {
	const { runCli } = await import('./cli/index.js')
	await runCli(args)
	process.exit(0)
}

// MCP server mode
const { StdioServerTransport } = await import('@modelcontextprotocol/sdk/server/stdio.js')
const { createServer } = await import('./server.js')

const server = createServer()
const transport = new StdioServerTransport()
await server.connect(transport)
