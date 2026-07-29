import { randomUUID } from 'node:crypto'
import {
	type AbilityMethod,
	APPROVED_FALLBACK_FINGERPRINTS,
	selectAbilityMethod,
} from './compatibility.js'
import {
	canonicalJson,
	type DiscoveredAbility,
	methodsFromAbilityOptions,
	potentialAbilityNames,
	projectAbilityRows,
} from './schema.js'

export type {
	AbilityAnnotations,
	DiscoveredAbility,
	JsonSchema,
} from './schema.js'

export const AUDITED_READ_ABILITIES = Object.freeze([
	'fluent-cart/get-customer',
	'fluent-cart/get-inventory',
	'fluent-cart/get-order',
	'fluent-cart/get-order-activity',
	'fluent-cart/get-product',
	'fluent-cart/get-product-financials',
	'fluent-cart/get-refund-report',
	'fluent-cart/get-sales-report',
	'fluent-cart/get-sales-trend',
	'fluent-cart/get-search-schema',
	'fluent-cart/get-store-context',
	'fluent-cart/get-subscription',
	'fluent-cart/get-top-products',
	'fluent-cart/get-upcoming-payments',
	'fluent-cart/list-coupons',
	'fluent-cart/list-customers',
	'fluent-cart/list-orders',
	'fluent-cart/list-products',
	'fluent-cart/list-reference-data',
	'fluent-cart/list-subscriptions',
	'fluent-cart/list-transactions',
	'fluent-cart/query-customers',
	'fluent-cart/query-orders',
	'fluent-cart/query-products',
	'fluent-cart/query-sources',
	'fluent-cart/query-subscriptions',
] as const)

const ALLOWED = new Set<string>(AUDITED_READ_ABILITIES)
const TIMEOUT_MS = 30_000

export interface AbilitiesClientConfig {
	url: string
	username: string
	appPassword: string
	approvedFallbackFingerprints?: ReadonlySet<string>
}

export function admitAuditedReadAbilities(
	abilities: readonly DiscoveredAbility[],
	approvedFallbackFingerprints: ReadonlySet<string> = APPROVED_FALLBACK_FINGERPRINTS,
): DiscoveredAbility[] {
	const counts = new Map<string, number>()
	for (const ability of abilities) {
		counts.set(ability.name, (counts.get(ability.name) ?? 0) + 1)
	}

	return abilities
		.filter((ability) => {
			if (counts.get(ability.name) !== 1 || !ALLOWED.has(ability.name)) return false
			if (ability.category !== 'fluent-cart') return false
			const discoveryPath = `/wp-abilities/v1/abilities/${ability.name}`
			if (
				ability.rest.discoveryPath !== discoveryPath ||
				ability.rest.runPath !== `${discoveryPath}/run`
			) {
				return false
			}
			const method = selectAbilityMethod(ability, approvedFallbackFingerprints)
			return method !== null && ability.rest.methods.includes(method)
		})
		.sort((left, right) => left.name.localeCompare(right.name))
}

export function discoverAuditedReadAbilities(
	value: unknown,
	approvedFallbackFingerprints: ReadonlySet<string> = APPROVED_FALLBACK_FINGERPRINTS,
	restMethods: ReadonlyMap<string, readonly string[]> = new Map(),
): DiscoveredAbility[] {
	return admitAuditedReadAbilities(
		projectAbilityRows(value, ALLOWED, restMethods),
		approvedFallbackFingerprints,
	)
}

async function parseJson(response: Response, context: string): Promise<unknown> {
	const text = await response.text()
	try {
		return text === '' ? {} : JSON.parse(text)
	} catch {
		throw new Error(`${context} returned non-JSON HTTP ${response.status}.`)
	}
}

export function createAbilitiesClient(config: AbilitiesClientConfig) {
	const base = new URL('/wp-json/wp-abilities/v1/', config.url.replace(/\/+$/, ''))
	const approved = config.approvedFallbackFingerprints ?? APPROVED_FALLBACK_FINGERPRINTS
	const authorization = `Basic ${Buffer.from(`${config.username}:${config.appPassword}`).toString(
		'base64',
	)}`
	let abilities = new Map<string, { ability: DiscoveredAbility; method: AbilityMethod }>()

	async function request(
		path: string,
		init: RequestInit,
		context: string,
		externalSignal?: AbortSignal,
	): Promise<unknown> {
		const timeoutSignal = AbortSignal.timeout(TIMEOUT_MS)
		const signal = externalSignal ? AbortSignal.any([externalSignal, timeoutSignal]) : timeoutSignal
		const response = await fetch(new URL(path, base), {
			...init,
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				Authorization: authorization,
				'X-Request-Id': randomUUID(),
			},
			signal,
		})
		const body = await parseJson(response, context)
		if (!response.ok) throw new Error(`${context} failed with HTTP ${response.status}.`)
		return body
	}

	return {
		async discover(): Promise<DiscoveredAbility[]> {
			const body = await request(
				'abilities?category=fluent-cart&per_page=100',
				{ method: 'GET' },
				'Ability discovery',
			)
			const entries = await Promise.all(
				potentialAbilityNames(body, ALLOWED).map(async (name) => {
					const options = await request(
						`abilities/${name}/run`,
						{ method: 'OPTIONS' },
						'Ability route discovery',
					)
					return [name, methodsFromAbilityOptions(options)] as const
				}),
			)
			const found = discoverAuditedReadAbilities(body, approved, new Map(entries))
			abilities = new Map(
				found.map((ability) => [
					ability.name,
					{ ability, method: selectAbilityMethod(ability, approved) as AbilityMethod },
				]),
			)
			return found
		},

		async execute(
			name: string,
			input: Record<string, unknown>,
			signal?: AbortSignal,
		): Promise<unknown> {
			const selected = abilities.get(name)
			if (!selected) {
				throw new Error(`Ability "${name}" is not an audited, discovered read ability.`)
			}
			const path = selected.ability.rest.runPath.replace(/^\/wp-abilities\/v1\//, '')
			if (selected.method === 'GET') {
				return request(
					`${path}?input=${encodeURIComponent(canonicalJson(input))}`,
					{ method: 'GET' },
					'Ability execution',
					signal,
				)
			}
			return request(
				path,
				{ method: 'POST', body: JSON.stringify({ input }) },
				'Ability execution',
				signal,
			)
		},
	}
}

export type AbilitiesClient = ReturnType<typeof createAbilitiesClient>
