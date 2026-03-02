import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'

export interface Logger {
	debug: (data: string) => void
	info: (data: string) => void
	warn: (data: string) => void
	error: (data: string) => void
}

export function createLogger(server: McpServer): Logger {
	function log(level: 'debug' | 'info' | 'warning' | 'error', data: string) {
		server.server.sendLoggingMessage({ level, logger: 'fluentcart-mcp', data })
	}

	return {
		debug: (data: string) => log('debug', data),
		info: (data: string) => log('info', data),
		warn: (data: string) => log('warning', data),
		error: (data: string) => log('error', data),
	}
}
