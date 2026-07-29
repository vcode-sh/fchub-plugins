import { describe, expect, it } from 'vitest'
import type { DiscoveredAbility } from '../../src/abilities/client.js'
import { fingerprintAbility, selectAbilityMethod } from '../../src/abilities/compatibility.js'

const definition = {
	name: 'fluent-cart/get-store-context',
	label: 'Get Store Context',
	description: 'Return the current store context.',
	category: 'fluent-cart',
	inputSchema: { type: 'object', properties: {} },
	outputSchema: [],
	annotations: {
		abilitiesReadonly: null,
		abilitiesDestructive: null,
		abilitiesIdempotent: null,
		mcpReadOnlyHint: true,
		mcpDestructiveHint: false,
		mcpIdempotentHint: null,
		mcpOpenWorldHint: null,
	},
	rest: {
		discoveryPath: '/wp-abilities/v1/abilities/fluent-cart/get-store-context',
		runPath: '/wp-abilities/v1/abilities/fluent-cart/get-store-context/run',
		methods: ['DELETE', 'GET', 'PATCH', 'POST', 'PUT'],
	},
} as DiscoveredAbility

describe('FluentCart Ability method compatibility', () => {
	it('fingerprints only the canonical execution contract', () => {
		expect(fingerprintAbility(definition)).toBe(
			'sha256:1bd792c89fcc7373bb959c970bb00d2ad7ea03c8f61ae56de3a6859e82159be6',
		)

		const cosmeticAndSensitiveNoise = {
			...definition,
			label: 'Renamed by an administrator',
			description: 'Changed prose',
			capturedAt: '2099-01-01T00:00:00.000Z',
			credentials: { password: 'must-not-enter-the-fingerprint' },
			response: { store_name: 'must-not-enter-the-fingerprint' },
			inputSchema: { properties: {}, type: 'object' },
		} as DiscoveredAbility
		expect(fingerprintAbility(cosmeticAndSensitiveNoise)).toBe(fingerprintAbility(definition))

		const changedSchema = {
			...definition,
			inputSchema: {
				type: 'object',
				properties: { include: { type: 'string' } },
			},
		} as DiscoveredAbility
		expect(fingerprintAbility(changedSchema)).not.toBe(fingerprintAbility(definition))
	})

	it.each([
		{
			name: 'uses GET for WordPress readonly plus MCP readonly',
			abilitiesReadonly: true,
			abilitiesDestructive: false,
			mcpReadOnlyHint: true,
			fingerprint: 'any',
			want: 'GET',
		},
		{
			name: 'uses GET for WordPress readonly when MCP readonly is absent',
			abilitiesReadonly: true,
			abilitiesDestructive: null,
			mcpReadOnlyHint: null,
			fingerprint: 'any',
			want: 'GET',
		},
		{
			name: 'uses POST for the exact approved missing-readonly row',
			abilitiesReadonly: null,
			abilitiesDestructive: false,
			mcpReadOnlyHint: true,
			fingerprint: 'approved',
			want: 'POST',
		},
		{
			name: 'omits a changed missing-readonly row',
			abilitiesReadonly: null,
			abilitiesDestructive: false,
			mcpReadOnlyHint: true,
			fingerprint: 'changed',
			want: null,
		},
		{
			name: 'omits an explicit WordPress non-read row',
			abilitiesReadonly: false,
			abilitiesDestructive: null,
			mcpReadOnlyHint: true,
			fingerprint: 'any',
			want: null,
		},
		{
			name: 'omits a destructive row',
			abilitiesReadonly: null,
			abilitiesDestructive: true,
			mcpReadOnlyHint: true,
			fingerprint: 'any',
			want: null,
		},
		{
			name: 'omits contradictory WordPress annotations',
			abilitiesReadonly: true,
			abilitiesDestructive: true,
			mcpReadOnlyHint: true,
			fingerprint: 'any',
			want: null,
		},
		{
			name: 'omits an unannotated row',
			abilitiesReadonly: null,
			abilitiesDestructive: null,
			mcpReadOnlyHint: null,
			fingerprint: 'any',
			want: null,
		},
	] as const)(
		'$name',
		({ abilitiesReadonly, abilitiesDestructive, mcpReadOnlyHint, fingerprint, want }) => {
			const ability = {
				...definition,
				annotations: {
					...definition.annotations,
					abilitiesReadonly,
					abilitiesDestructive,
					mcpReadOnlyHint,
				},
				inputSchema:
					fingerprint === 'changed'
						? { type: 'object', properties: { changed: { type: 'boolean' } } }
						: definition.inputSchema,
			} as DiscoveredAbility
			const approved =
				fingerprint === 'approved'
					? new Set([fingerprintAbility(ability)])
					: new Set([fingerprintAbility(definition)])

			expect(selectAbilityMethod(ability, approved)).toBe(want)
		},
	)

	it('fails closed for an MCP destructive contradiction', () => {
		const contradictory = {
			...definition,
			annotations: {
				...definition.annotations,
				abilitiesReadonly: true,
				mcpDestructiveHint: true,
			},
		} as DiscoveredAbility

		expect(selectAbilityMethod(contradictory, new Set())).toBeNull()
	})
})
