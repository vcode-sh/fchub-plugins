import { describe, expect, it } from 'vitest'
import { resolveHttpExposure } from '../../src/transport/http-config.js'

const KEY = 'private-http-key-0123456789abcdef'

describe('HTTP exposure profiles', () => {
	it('keeps the local profile on loopback and supplies fixed loopback allowlists', () => {
		expect(() => resolveHttpExposure({ profile: 'local', host: '0.0.0.0' })).toThrow(/loopback/)

		expect(resolveHttpExposure({ profile: 'local', host: '127.0.0.1' })).toEqual({
			profile: 'local',
			host: '127.0.0.1',
			allowedHosts: ['localhost', '127.0.0.1', '[::1]'],
			allowedOrigins: ['localhost', '127.0.0.1', '[::1]'],
		})
	})

	it('ignores caller allowlists that would weaken the local profile', () => {
		expect(
			resolveHttpExposure({
				profile: 'local',
				host: '127.0.0.1',
				allowedHosts: ['attacker.example'],
			}),
		).toMatchObject({
			allowedHosts: ['localhost', '127.0.0.1', '[::1]'],
		})
		expect(
			resolveHttpExposure({
				profile: 'local',
				host: '127.0.0.1',
				allowedOrigins: ['attacker.example'],
			}),
		).toMatchObject({
			allowedOrigins: ['localhost', '127.0.0.1', '[::1]'],
		})
	})

	it('requires a strong key and both explicit allowlists for the private profile', () => {
		expect(() => resolveHttpExposure({ profile: 'private', host: '0.0.0.0' })).toThrow(
			/FLUENTCART_MCP_API_KEY/,
		)
		expect(() =>
			resolveHttpExposure({
				profile: 'private',
				host: '0.0.0.0',
				bearerKey: KEY,
				allowedOrigins: ['console.example'],
			}),
		).toThrow(/allowed hosts/i)
		expect(() =>
			resolveHttpExposure({
				profile: 'private',
				host: '0.0.0.0',
				bearerKey: KEY,
				allowedHosts: ['mcp.example'],
			}),
		).toThrow(/allowed origins/i)
	})

	it.each([
		['scheme', 'https://mcp.example'],
		['port', 'mcp.example:8443'],
		['default port', 'mcp.example:80'],
		['zero-padded default port', 'mcp.example:080'],
		['bare port separator', 'mcp.example:'],
		['bare path marker', 'mcp.example/'],
		['bare query marker', 'mcp.example?'],
		['bare fragment marker', 'mcp.example#'],
		['empty entry', ''],
		['leading whitespace', ' mcp.example'],
		['trailing whitespace', 'mcp.example '],
	])('rejects a %s in either hostname-only allowlist', (_label, entry) => {
		const base = {
			profile: 'private' as const,
			host: '0.0.0.0',
			bearerKey: KEY,
		}
		expect(() =>
			resolveHttpExposure({
				...base,
				allowedHosts: [entry],
				allowedOrigins: ['console.example'],
			}),
		).toThrow(/hostname/i)
		expect(() =>
			resolveHttpExposure({
				...base,
				allowedHosts: ['mcp.example'],
				allowedOrigins: [entry],
			}),
		).toThrow(/hostname/i)
	})

	it('measures bearer strength in UTF-8 bytes rather than JavaScript characters', () => {
		const base = {
			profile: 'private' as const,
			host: '0.0.0.0',
			allowedHosts: ['mcp.example'],
			allowedOrigins: ['console.example'],
		}
		expect(() => resolveHttpExposure({ ...base, bearerKey: 'é'.repeat(15) })).toThrow(
			/32 UTF-8 bytes/,
		)
		expect(() => resolveHttpExposure({ ...base, bearerKey: 'é'.repeat(16) })).not.toThrow()
		expect(() => resolveHttpExposure({ ...base, bearerKey: 'k'.repeat(31) })).toThrow(
			/32 UTF-8 bytes/,
		)
	})

	it('rejects whitespace padding rather than counting it as key strength', () => {
		expect(() =>
			resolveHttpExposure({
				profile: 'private',
				host: '0.0.0.0',
				allowedHosts: ['mcp.example'],
				allowedOrigins: ['console.example'],
				bearerKey: ' '.repeat(40),
			}),
		).toThrow(/FLUENTCART_MCP_API_KEY/)
	})
})
