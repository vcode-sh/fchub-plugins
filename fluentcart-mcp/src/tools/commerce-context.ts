import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import type { CommerceContext, RuntimeProfile } from '../commerce/context.js'
import { buildCommerceContext, SAFE_SHOP_KEYS, storeOrigin } from '../commerce/context.js'
import { parseWriteMode } from '../security/write-policy.js'
import { createTool, type ToolDefinition } from './_factory.js'
import { direct } from './endpoints.js'

/** `/app/init` also returns `rest`, `trans` and `asset_url`. Only `shop` is read, by allowlist. */
interface AppInit {
	shop?: Record<string, unknown>
}

function safeShop(payload: unknown): Record<string, unknown> | null {
	const shop = (payload as AppInit | null)?.shop
	if (shop === undefined || shop === null || typeof shop !== 'object') return null

	const picked: Record<string, unknown> = {}
	for (const key of SAFE_SHOP_KEYS) {
		if (key in shop) picked[key] = (shop as Record<string, unknown>)[key]
	}
	return picked
}

export interface CommerceContextDeps {
	/** Component versions proven by plan 03's discovery. Absent means the runtime is unproven. */
	profile: RuntimeProfile | null
	/** Canonical operations behind the profile, so the digest matches the captured fixture. */
	operations?: readonly unknown[] | null
	/** Names surviving capability discovery and the write-exposure policy. */
	exposedToolNames: readonly string[]
	storeUrl: string
}

export async function loadCommerceContext(
	client: FluentCartClient,
	deps: CommerceContextDeps,
): Promise<CommerceContext> {
	if (deps.profile === null) {
		// Refuse rather than report a store whose runtime nobody established. A context that
		// guessed its own WordPress version would be worse than no context at all.
		throw new Error(
			'Store context needs a verified runtime profile. Start the server through capability discovery so the component versions are proven.',
		)
	}

	// Permission and authentication failures travel outward unchanged: an operator who cannot read
	// `/app/init` needs to be told so, not handed a context with empty fields.
	const response = await client.get('/app/init')

	return buildCommerceContext({
		origin: storeOrigin(deps.storeUrl),
		shop: safeShop(response.data),
		profile: deps.profile,
		operations: deps.operations ?? null,
		exposedToolNames: deps.exposedToolNames,
		writeMode: parseWriteMode(process.env.FLUENTCART_WRITE_MODE),
	})
}

const DESCRIPTION = [
	'Get compact orientation for the connected FluentCart store before doing anything else.',
	'Returns the store origin, display name, currency and timezone; the WordPress, FluentCart core',
	'and FluentCart Pro versions with a digest identifying the verified route profile; and the',
	'entity and report capability names this configuration actually exposes, alongside the current',
	'write mode (disabled, reversible or guarded).',
	'Unconfigured optional values are returned as null with a short warning rather than a guess, so',
	'an absent currency is visible instead of assumed. Raw settings, payment configuration, user',
	'identity and the full route list are deliberately not included.',
].join(' ')

export function commerceContextTools(
	client: FluentCartClient,
	deps: CommerceContextDeps,
): ToolDefinition[] {
	return [
		createTool(client, {
			name: 'fluentcart_get_store_context',
			routes: direct('GET', '/app/init'),
			title: 'Get FluentCart Store Context',
			description: DESCRIPTION,
			// No parameters: the context describes the connected store, and there is nothing about it
			// for a caller to select. A knob the handler then ignored would only mislead.
			schema: z.object({}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			handler: (apiClient) => loadCommerceContext(apiClient, deps),
		}),
	]
}
