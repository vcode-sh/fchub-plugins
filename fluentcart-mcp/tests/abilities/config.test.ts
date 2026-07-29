import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { resolveAbilitiesConfig } from '../../src/abilities/config.js'

const KEYS = [
	'FLUENTCART_ABILITIES_MODE',
	'FLUENTCART_ABILITIES_USERNAME',
	'FLUENTCART_ABILITIES_APP_PASSWORD',
	'FLUENTCART_USERNAME',
	'FLUENTCART_APP_PASSWORD',
]
const original: Record<string, string | undefined> = {}

beforeEach(() => {
	for (const key of KEYS) {
		original[key] = process.env[key]
		delete process.env[key]
	}
})

afterEach(() => {
	for (const key of KEYS) {
		if (original[key] === undefined) delete process.env[key]
		else process.env[key] = original[key]
	}
})

describe('WordPress Abilities bridge configuration', () => {
	it('is disabled by default', () => {
		expect(resolveAbilitiesConfig()).toEqual({ enabled: false })
	})

	it('accepts explicit disabled mode without credentials', () => {
		process.env.FLUENTCART_ABILITIES_MODE = 'disabled'
		expect(resolveAbilitiesConfig()).toEqual({ enabled: false })
	})

	it('requires separate ability credentials when enabled', () => {
		process.env.FLUENTCART_ABILITIES_MODE = 'enabled'
		process.env.FLUENTCART_USERNAME = 'rest-user'
		process.env.FLUENTCART_APP_PASSWORD = 'rest-password'

		expect(() => resolveAbilitiesConfig()).toThrow(
			/FLUENTCART_ABILITIES_USERNAME and FLUENTCART_ABILITIES_APP_PASSWORD/,
		)
	})

	it('rejects partial ability credentials', () => {
		process.env.FLUENTCART_ABILITIES_MODE = 'enabled'
		process.env.FLUENTCART_ABILITIES_USERNAME = 'ability-user'

		expect(() => resolveAbilitiesConfig()).toThrow(/FLUENTCART_ABILITIES_APP_PASSWORD/)
	})

	it('returns the explicit ability principal', () => {
		process.env.FLUENTCART_ABILITIES_MODE = 'enabled'
		process.env.FLUENTCART_ABILITIES_USERNAME = 'ability-user'
		process.env.FLUENTCART_ABILITIES_APP_PASSWORD = 'ability-password'

		expect(resolveAbilitiesConfig()).toEqual({
			enabled: true,
			username: 'ability-user',
			appPassword: 'ability-password',
		})
	})

	it('rejects unknown modes instead of guessing', () => {
		process.env.FLUENTCART_ABILITIES_MODE = 'auto'
		expect(() => resolveAbilitiesConfig()).toThrow(/Invalid FLUENTCART_ABILITIES_MODE/)
	})
})
