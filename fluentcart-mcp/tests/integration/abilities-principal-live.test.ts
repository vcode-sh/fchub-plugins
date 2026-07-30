import { beforeAll, describe, expect, it } from 'vitest'
import { AUDITED_READ_ABILITIES, createAbilitiesClient } from '../../src/abilities/client.js'
import { resolveAbilitiesConfig } from '../../src/abilities/config.js'
import { getLiveRun } from './support/live-run.js'

const enabled = process.env.FLUENTCART_ABILITIES_MODE === 'enabled'
const run = getLiveRun()
const config = enabled ? resolveAbilitiesConfig() : null

function authorization(username: string, appPassword: string): string {
	return `Basic ${Buffer.from(`${username}:${appPassword}`).toString('base64')}`
}

describe.skipIf(!enabled)('run-owned Core+Pro Abilities principal', () => {
	if (!config?.enabled) return
	const client = createAbilitiesClient({ url: run.target.href, ...config })
	let discovered: Awaited<ReturnType<typeof client.discover>> = []
	let identity: Record<string, unknown> = {}

	beforeAll(async () => {
		const response = await fetch(new URL('/wp-json/wp/v2/users/me?context=edit', run.target), {
			headers: { Authorization: authorization(config.username, config.appPassword) },
		})
		expect(response.ok, 'run-owned Abilities principal could not resolve itself').toBe(true)
		identity = (await response.json()) as Record<string, unknown>
		discovered = await client.discover()
	})

	it('uses the exact run-owned subscriber principal without manage_options', () => {
		expect(config.username).toMatch(/^mcp-abilities-/)
		expect(identity.slug).toBe(config.username)
		expect(identity.roles).toEqual(['subscriber'])
		// WordPress 7 omits `capabilities` from this self endpoint for subscribers. The lifecycle
		// independently checks `user_can(…, 'manage_options')` before it creates the password.
		expect(
			(identity.capabilities as Record<string, boolean> | undefined)?.manage_options ?? false,
		).toBe(false)
	})

	it('discovers only audited FluentCart read abilities and omits writes', () => {
		expect(discovered.length).toBeGreaterThan(0)
		for (const ability of discovered) {
			expect(AUDITED_READ_ABILITIES).toContain(
				ability.name as (typeof AUDITED_READ_ABILITIES)[number],
			)
		}
	})

	it('executes the representative read bridge through the disposable principal', async () => {
		expect(discovered.map((ability) => ability.name)).toContain('fluent-cart/get-store-context')
		await expect(client.execute('fluent-cart/get-store-context', {})).resolves.toBeTypeOf('object')
	})
})
