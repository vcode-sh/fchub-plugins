import { describe, expect, it } from 'vitest'
import { REDACTED, redactSensitive } from '../../src/security/redaction.js'

describe('redactSensitive', () => {
	it('redacts obviously sensitive keys', () => {
		const input = {
			password: 'hunter2',
			app_password: 'abcd efgh ijkl',
			token: 'tok_live_123',
			secret: 's3cr3t',
			api_key: 'ak_123',
			authorization: 'Basic dXNlcjpwYXNz',
		}

		const output = redactSensitive(input) as Record<string, unknown>
		for (const key of Object.keys(input)) {
			expect(output[key]).toBe(REDACTED)
		}
	})

	it('matches sensitive key names case-insensitively and across separators', () => {
		const input = {
			APP_PASSWORD: 'x',
			'Api-Key': 'x',
			clientSecret: 'x',
			accessToken: 'x',
			IdempotencyKey: 'x',
			confirm_token: 'x',
		}

		const output = redactSensitive(input) as Record<string, unknown>
		for (const key of Object.keys(input)) {
			expect(output[key]).toBe(REDACTED)
		}
	})

	it('leaves ordinary commerce fields untouched', () => {
		const input = { order_id: 42, currency: 'GBP', total: 1999, status: 'paid' }
		expect(redactSensitive(input)).toEqual(input)
	})

	it('redacts Basic and Bearer credentials embedded in free text', () => {
		const output = redactSensitive({
			message: 'Request failed with Authorization: Basic dXNlcjpwYXNzd29yZA== on retry',
		}) as Record<string, string>

		expect(output.message).not.toContain('dXNlcjpwYXNzd29yZA==')
		expect(output.message).toContain(REDACTED)

		const bearer = redactSensitive('called with Bearer sk-abcdef123456 and failed') as string
		expect(bearer).not.toContain('sk-abcdef123456')
		expect(bearer).toContain(REDACTED)
	})

	it('recurses through nested objects and arrays', () => {
		const input = {
			settings: [
				{ provider: 'stripe', secret_key: 'sk_live_1' },
				{ provider: 'paypal', token: 't' },
			],
			nested: { deep: { password: 'p' } },
		}

		const output = redactSensitive(input) as {
			settings: Array<Record<string, unknown>>
			nested: { deep: Record<string, unknown> }
		}

		expect(output.settings[0]?.secret_key).toBe(REDACTED)
		expect(output.settings[0]?.provider).toBe('stripe')
		expect(output.settings[1]?.token).toBe(REDACTED)
		expect(output.nested.deep.password).toBe(REDACTED)
	})

	it('survives a cyclic structure without recursing forever', () => {
		const input: Record<string, unknown> = { password: 'p', name: 'loop' }
		input.self = input

		const output = redactSensitive(input) as Record<string, unknown>
		expect(output.password).toBe(REDACTED)
		expect(output.name).toBe('loop')
		expect(output.self).toBe('[Circular]')
	})

	it('redacts sensitive values carried by an Error message', () => {
		const output = redactSensitive(new Error('bad key Bearer sk-secret-value-here')) as {
			message: string
		}
		expect(output.message).not.toContain('sk-secret-value-here')
	})

	it('passes primitives through unchanged', () => {
		expect(redactSensitive(42)).toBe(42)
		expect(redactSensitive(null)).toBe(null)
		expect(redactSensitive(undefined)).toBe(undefined)
		expect(redactSensitive(true)).toBe(true)
	})

	it('does not mutate its input', () => {
		const input = { password: 'original' }
		redactSensitive(input)
		expect(input.password).toBe('original')
	})

	it('bounds absurdly deep structures instead of blowing the stack', () => {
		let deep: Record<string, unknown> = { password: 'p' }
		for (let i = 0; i < 200; i += 1) deep = { child: deep }

		expect(() => redactSensitive(deep)).not.toThrow()
	})
})
