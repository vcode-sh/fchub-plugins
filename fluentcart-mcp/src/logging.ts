import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import { redactSensitive } from './security/redaction.js'

export interface Logger {
	debug: (data: string) => void
	info: (data: string) => void
	warn: (data: string) => void
	error: (data: string) => void
}

export function createLogger(server: McpServer): Logger {
	function log(level: 'debug' | 'info' | 'warning' | 'error', data: string) {
		// Redact at the boundary rather than trusting every call site to have thought about it.
		server.server.sendLoggingMessage({
			level,
			logger: 'fluentcart-mcp',
			data: redactSensitive(data) as string,
		})
	}

	return {
		debug: (data: string) => log('debug', data),
		info: (data: string) => log('info', data),
		warn: (data: string) => log('warning', data),
		error: (data: string) => log('error', data),
	}
}
