import { describe, expect, it } from 'vitest'
import { buildLiveChildEnvironment, provisionAbilitiesPrincipal } from './abilities-principal.mjs'

const generalPrincipal = {
	FLUENTCART_URL: 'http://localhost:9081',
	FLUENTCART_USERNAME: 'rest-reader',
	FLUENTCART_APP_PASSWORD: 'rest-password',
}

function localWordPress({
	proActive = true,
	applicationPasswords = true,
	passwordCreateFails = false,
	mcpActive = 'no',
}: {
	proActive?: boolean
	applicationPasswords?: boolean
	passwordCreateFails?: boolean
	mcpActive?: string
} = {}) {
	const state = {
		proActive,
		applicationPasswords,
		passwordCreateFails,
		mcpActive,
		users: new Map<number, { login: string; role: string; accountant: boolean }>(),
		passwords: new Map<string, number>(),
		nextUserId: 41,
	}

	return {
		state,
		// biome-ignore lint/complexity/noExcessiveCognitiveComplexity: This test-only command fake keeps each observable WordPress operation explicit.
		async run(args: string[]) {
			const command = args.join(' ')
			if (command === 'plugin is-active fluent-cart-pro') {
				if (!state.proActive) throw new Error('fluent-cart-pro is inactive')
				return ''
			}
			if (command.startsWith('eval /* abilities-principal-preflight */')) {
				if (!state.applicationPasswords) throw new Error('application passwords unavailable')
				return ''
			}
			if (command.startsWith('eval /* abilities-principal-read-toggle */')) {
				return JSON.stringify({ present: true, active: state.mcpActive })
			}
			if (command.startsWith('eval /* abilities-principal-set-toggle ')) {
				state.mcpActive = command.includes('/* abilities-principal-set-toggle yes */')
					? 'yes'
					: 'no'
				return ''
			}
			if (args[0] === 'user' && args[1] === 'create') {
				const id = state.nextUserId++
				state.users.set(id, { login: args[2], role: 'subscriber', accountant: false })
				return `${id}\n`
			}
			if (args[0] === 'user' && args[1] === 'meta' && args[2] === 'update') {
				const user = state.users.get(Number(args[3]))
				if (!user) throw new Error('missing user')
				user.accountant = args[5] === 'accountant'
				return ''
			}
			if (command.startsWith('eval /* abilities-principal-verify-non-admin */')) return 'no'
			if (command.startsWith('eval /* abilities-principal-create-password ')) {
				if (state.passwordCreateFails) throw new Error('application password creation refused')
				const id = state.users.keys().next().value
				if (id === undefined) throw new Error('missing user')
				const uuid = '0d7a56d0-0e77-4e00-9f9d-7d7bf1e41111'
				state.passwords.set(uuid, id)
				return JSON.stringify({ uuid, password: 'ability-password-plaintext' })
			}
			if (command.startsWith('eval /* abilities-principal-delete-password ')) {
				state.passwords.clear()
				return ''
			}
			if (args[0] === 'user' && args[1] === 'delete') {
				state.users.delete(Number(args[2]))
				return ''
			}
			if (command.startsWith('eval /* abilities-principal-verify-absent ')) {
				return JSON.stringify({
					userMissing: state.users.size === 0,
					loginMissing: state.users.size === 0,
					passwordMissing: state.passwords.size === 0,
				})
			}
			throw new Error(`unexpected WP command: ${command}`)
		},
	}
}

describe('Abilities principal launcher environment', () => {
	it('erases every ambient Abilities key and accepts file-owned run provisioning consent', () => {
		const child = buildLiveChildEnvironment(
			{ ...generalPrincipal, FLUENTCART_ABILITIES_MODE: 'enabled' },
			{
				...generalPrincipal,
				FLUENTCART_ABILITIES_MODE: 'enabled',
				FLUENTCART_ABILITIES_USERNAME: 'administrator',
				FLUENTCART_ABILITIES_APP_PASSWORD: 'ambient-password',
				FLUENTCART_ABILITIES_UNRELATED: 'must-not-cross-boundary',
			},
		)

		expect(child.FLUENTCART_USERNAME).toBe('rest-reader')
		expect(child.FLUENTCART_ABILITIES_MODE).toBe('enabled')
		expect(child.FLUENTCART_ABILITIES_USERNAME).toBeUndefined()
		expect(child.FLUENTCART_ABILITIES_APP_PASSWORD).toBeUndefined()
		expect(child.FLUENTCART_ABILITIES_UNRELATED).toBeUndefined()
	})

	it.each([
		{ FLUENTCART_ABILITIES_USERNAME: 'persisted-reader' },
		{ FLUENTCART_ABILITIES_APP_PASSWORD: 'persisted-password' },
		{
			FLUENTCART_ABILITIES_MODE: 'enabled',
			FLUENTCART_ABILITIES_USERNAME: 'persisted-reader',
			FLUENTCART_ABILITIES_APP_PASSWORD: 'persisted-password',
		},
	])('rejects package-local Abilities principal persistence', (fileValues) => {
		expect(() => buildLiveChildEnvironment({ ...generalPrincipal, ...fileValues }, {})).toThrow(
			/must not persist/,
		)
	})

	it.each([{}, { FLUENTCART_ABILITIES_MODE: 'disabled' }])(
		'preserves absent and disabled Abilities behaviour',
		(fileValues) => {
			const child = buildLiveChildEnvironment({ ...generalPrincipal, ...fileValues }, {})
			expect(child.FLUENTCART_ABILITIES_MODE).toBeUndefined()
			expect(child.FLUENTCART_ABILITIES_USERNAME).toBeUndefined()
			expect(child.FLUENTCART_ABILITIES_APP_PASSWORD).toBeUndefined()
		},
	)
})

describe('run-owned Abilities principal lifecycle', () => {
	it('creates a subscriber accountant, enables MCP, then revokes exact records and restores the toggle', async () => {
		const wordpress = localWordPress()
		const lifecycle = await provisionAbilitiesPrincipal({
			run: wordpress.run,
			runId: 'mcp-2026-07-29-abilities-abcdef123456',
		})

		expect(lifecycle.principal.username).toMatch(/^mcp-abilities-/)
		expect(lifecycle.principal.username).not.toBe('rest-reader')
		expect(lifecycle.principal.password).toBe('ability-password-plaintext')
		expect([...wordpress.state.users.values()]).toEqual([
			{ login: lifecycle.principal.username, role: 'subscriber', accountant: true },
		])
		expect(wordpress.state.mcpActive).toBe('yes')

		await lifecycle.cleanup()

		expect(wordpress.state.users.size).toBe(0)
		expect(wordpress.state.passwords.size).toBe(0)
		expect(wordpress.state.mcpActive).toBe('no')
	})

	it('excludes Core-only stores before creating a user or changing the MCP toggle', async () => {
		const wordpress = localWordPress({ proActive: false })

		await expect(
			provisionAbilitiesPrincipal({ run: wordpress.run, runId: 'mcp-core-only-abcdef123456' }),
		).rejects.toThrow(/FluentCart Pro/)
		expect(wordpress.state.users.size).toBe(0)
		expect(wordpress.state.mcpActive).toBe('no')
	})

	it('cleans up the exact subscriber and restores MCP when password creation fails', async () => {
		const wordpress = localWordPress({ passwordCreateFails: true })

		await expect(
			provisionAbilitiesPrincipal({
				run: wordpress.run,
				runId: 'mcp-password-failure-abcdef123456',
			}),
		).rejects.toThrow(/application password creation refused/)
		expect(wordpress.state.users.size).toBe(0)
		expect(wordpress.state.passwords.size).toBe(0)
		expect(wordpress.state.mcpActive).toBe('no')
	})
})
