import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import { describe, expect, it, vi } from 'vitest'
import { createLogger } from '../src/logging.js'

function mockServer(): McpServer {
	return {
		server: {
			sendLoggingMessage: vi.fn(),
		},
	} as unknown as McpServer
}

describe('createLogger', () => {
	it('returns object with debug, info, warn, error methods', () => {
		const logger = createLogger(mockServer())
		expect(typeof logger.debug).toBe('function')
		expect(typeof logger.info).toBe('function')
		expect(typeof logger.warn).toBe('function')
		expect(typeof logger.error).toBe('function')
	})

	it('debug sends logging message with level debug', () => {
		const server = mockServer()
		const logger = createLogger(server)
		logger.debug('test debug')

		expect(server.server.sendLoggingMessage).toHaveBeenCalledWith({
			level: 'debug',
			logger: 'fluentcart-mcp',
			data: 'test debug',
		})
	})

	it('info sends logging message with level info', () => {
		const server = mockServer()
		const logger = createLogger(server)
		logger.info('server started')

		expect(server.server.sendLoggingMessage).toHaveBeenCalledWith({
			level: 'info',
			logger: 'fluentcart-mcp',
			data: 'server started',
		})
	})

	it('warn sends logging message with level warning', () => {
		const server = mockServer()
		const logger = createLogger(server)
		logger.warn('something dodgy')

		expect(server.server.sendLoggingMessage).toHaveBeenCalledWith({
			level: 'warning',
			logger: 'fluentcart-mcp',
			data: 'something dodgy',
		})
	})

	it('error sends logging message with level error', () => {
		const server = mockServer()
		const logger = createLogger(server)
		logger.error('everything is on fire')

		expect(server.server.sendLoggingMessage).toHaveBeenCalledWith({
			level: 'error',
			logger: 'fluentcart-mcp',
			data: 'everything is on fire',
		})
	})
})
