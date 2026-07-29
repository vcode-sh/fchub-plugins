import { EventEmitter } from 'node:events'
import { describe, expect, it } from 'vitest'
// @ts-expect-error The JavaScript launcher intentionally has no production declaration surface.
import { runVitest } from '../../scripts/run-live-tests.mjs'
import { runAbilitiesLauncher } from './abilities-principal.mjs'

function dockerSite(siteOrigin = 'https://fchub.vcode.sh') {
	const state = { provisioned: 0, cleaned: 0, testRuns: 0 }
	const observed = { childEnv: null as NodeJS.ProcessEnv | null }
	return {
		state,
		observed,
		async run(args: string[]) {
			if (args.join(' ') === 'option get siteurl') return `${siteOrigin}\n`
			if (args.join(' ') === 'option get home') return `${siteOrigin}\n`
			throw new Error(`unexpected mutating command: ${args.join(' ')}`)
		},
		async provision() {
			state.provisioned += 1
			return {
				principal: { username: 'run-owned', password: 'child-only' },
				async cleanup() {
					state.cleaned += 1
				},
			}
		},
		async runTests(environment: NodeJS.ProcessEnv) {
			state.testRuns += 1
			observed.childEnv = { ...environment }
			return 0
		},
	}
}

const childEnv = {
	FLUENTCART_ABILITIES_MODE: 'enabled',
	FLUENTCART_USERNAME: 'rest-reader',
	FLUENTCART_APP_PASSWORD: 'rest-password',
}

describe('Abilities live launcher target binding', () => {
	it('accepts only the approved local origin bound to the Docker WordPress site', async () => {
		const site = dockerSite()

		await expect(
			runAbilitiesLauncher({
				childEnv: { ...childEnv },
				target: new URL('http://localhost:9081'),
				runId: 'mcp-target-success-abcdef123456',
				run: site.run,
				provision: site.provision,
				runTests: site.runTests,
			}),
		).resolves.toBe(0)
		expect(site.state).toEqual({ provisioned: 1, cleaned: 1, testRuns: 1 })
		expect(site.observed.childEnv).toMatchObject({
			FLUENTCART_ABILITIES_MODE: 'enabled',
			FLUENTCART_ABILITIES_USERNAME: 'run-owned',
			FLUENTCART_ABILITIES_APP_PASSWORD: 'child-only',
		})
		expect(site.observed.childEnv?.FLUENTCART_ABILITIES_USERNAME).not.toBe(
			site.observed.childEnv?.FLUENTCART_USERNAME,
		)
		expect(site.observed.childEnv?.FLUENTCART_ABILITIES_APP_PASSWORD).not.toBe(
			site.observed.childEnv?.FLUENTCART_APP_PASSWORD,
		)
	})

	it.each(['http://localhost:9082', 'https://fchub.vcode.sh'])(
		'rejects non-bound target %s before any principal or MCP mutation',
		async (target) => {
			const site = dockerSite()

			await expect(
				runAbilitiesLauncher({
					childEnv: { ...childEnv },
					target: new URL(target),
					runId: 'mcp-target-rejection-abcdef123456',
					run: site.run,
					provision: site.provision,
					runTests: site.runTests,
				}),
			).rejects.toThrow(/exact local Docker WordPress target/)
			expect(site.state).toEqual({ provisioned: 0, cleaned: 0, testRuns: 0 })
		},
	)

	it('rejects localhost:9081 when WP-CLI resolves a different WordPress site', async () => {
		const site = dockerSite('https://other.example')

		await expect(
			runAbilitiesLauncher({
				childEnv: { ...childEnv },
				target: new URL('http://localhost:9081'),
				runId: 'mcp-site-rejection-abcdef123456',
				run: site.run,
				provision: site.provision,
				runTests: site.runTests,
			}),
		).rejects.toThrow(/not bound to the approved Docker WordPress site/)
		expect(site.state).toEqual({ provisioned: 0, cleaned: 0, testRuns: 0 })
	})
})

describe('Abilities live launcher child lifecycle', () => {
	it('cleans the provisioned principal when the Vitest child cannot spawn', async () => {
		const site = dockerSite()
		const spawnFailure = () => {
			const child = new EventEmitter()
			queueMicrotask(() => child.emit('error', new Error('spawn refused')))
			return child
		}

		await expect(
			runAbilitiesLauncher({
				childEnv: { ...childEnv },
				target: new URL('http://localhost:9081'),
				runId: 'mcp-spawn-failure-abcdef123456',
				run: site.run,
				provision: site.provision,
				runTests: (environment: NodeJS.ProcessEnv) =>
					runVitest(environment, { spawnProcess: spawnFailure, argv: [] }),
			}),
		).rejects.toThrow(/could not start the local vitest binary: spawn refused/)
		expect(site.state).toEqual({ provisioned: 1, cleaned: 1, testRuns: 0 })
	})
})
