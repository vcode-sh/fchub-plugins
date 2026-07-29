import { createRequire } from 'node:module'
import { createAbilitiesClient } from './abilities/client.js'
import { resolveAbilitiesConfig } from './abilities/config.js'
import { createAbilityBridgeTools } from './abilities/tools.js'
import type { ApiCapabilities } from './api/capabilities.js'
import { discoverApiCapabilities } from './api/capabilities.js'
import type { FluentCartClient } from './api/client.js'
import { createClient } from './api/client.js'
import {
	buildCacheScope,
	PrincipalScopedCache,
	routeProfileDigestFromOperations,
} from './commerce/cache.js'
import { resolveConfig } from './config/resolver.js'
import { type ResolvedConfig, resolveApiUrls } from './config/types.js'
import type { ResourceDeps } from './resources.js'
import { canExposeTool, parseWriteMode, type WritePolicyConfig } from './security/write-policy.js'
import type { ToolsetMode } from './server.js'
import type { ToolDefinition } from './tools/_factory.js'
import { type PreparedCodeModeRuntime, prepareCodeModeRuntime } from './tools/code-mode.js'
import { commerceContextTools } from './tools/commerce-context.js'
import { selectEndpoint } from './tools/endpoints.js'
import { createAllTools } from './tools/index.js'
import { referenceDataTools } from './tools/reference-data.js'

/** One process cache shared by tools and resources so authorised reference answers cannot diverge. */
const referenceCache = new PrincipalScopedCache()

export function purgeAuthorizationCaches(): void {
	referenceCache.clear()
}

const require = createRequire(import.meta.url)
export const serverVersion = (require('../package.json') as { version: string }).version

export interface ServerContext {
	client: FluentCartClient
	/** Already filtered by the exposure policy. Hidden tools are absent, not merely disabled. */
	tools: ToolDefinition[]
	version: string
	configSource: string
	writePolicy: WritePolicyConfig
	/** Live route evidence, or null when discovery was explicitly skipped for a unit test. */
	capabilities: ApiCapabilities | null
	/** Shared authorised-read cache used by endpoint tools, reference tools and MCP resources. */
	resourceDeps: ResourceDeps
}

export interface RuntimeContext extends ServerContext {
	/** Prepared only for Code Mode, once before a serving factory is created. */
	codeMode?: PreparedCodeModeRuntime
}

/** A composite needs every branch; a direct/fallback declaration needs one served variant. */
function isRouteSupported(tool: ToolDefinition, capabilities: ApiCapabilities | null): boolean {
	if (!(capabilities && tool.routes)) return true
	if (tool.routes.kind === 'composite') {
		return tool.routes.variants.every((variant) => selectEndpoint(capabilities, [variant]) !== null)
	}
	return selectEndpoint(capabilities, tool.routes.variants) !== null
}

export function resolveWritePolicy(): WritePolicyConfig {
	return {
		writeMode: parseWriteMode(process.env.FLUENTCART_WRITE_MODE),
	}
}

/** Assemble the filtered process context from already-resolved startup inputs. */
function buildServerContext(
	resolved: ResolvedConfig,
	capabilities: ApiCapabilities | null = null,
	additionalTools: readonly ToolDefinition[] = [],
): ServerContext {
	const client = createClient(resolved)
	const writePolicy = resolveWritePolicy()
	const cacheScope = buildCacheScope({
		storeUrl: resolved.url,
		username: resolved.username,
		routeProfile: capabilities
			? routeProfileDigestFromOperations(capabilities.operations)
			: 'undiscovered',
	})
	const resourceDeps = { cache: referenceCache, scope: cacheScope }

	const tools = createAllTools(client, {
		capabilities: capabilities ?? undefined,
		cache: resourceDeps,
	})
		.filter((tool) => canExposeTool(tool.safety, writePolicy))
		.filter((tool) => isRouteSupported(tool, capabilities))
	const admittedAdditionalTools = additionalTools.filter((tool) =>
		canExposeTool(tool.safety, writePolicy),
	)

	const referenceTools = referenceDataTools(client, {
		cache: referenceCache,
		scope: cacheScope,
	})
		.filter((tool) => canExposeTool(tool.safety, writePolicy))
		.filter((tool) => isRouteSupported(tool, capabilities))

	const contextTools = commerceContextTools(client, {
		profile: null,
		operations: capabilities ? [...capabilities.operations] : null,
		exposedToolNames: [...tools, ...referenceTools, ...admittedAdditionalTools].map(
			(tool) => tool.name,
		),
		storeUrl: resolved.url,
	}).filter((tool) => canExposeTool(tool.safety, writePolicy))

	return {
		client,
		tools: [...tools, ...referenceTools, ...contextTools, ...admittedAdditionalTools],
		version: serverVersion,
		configSource: process.env.FLUENTCART_URL ? 'env' : 'file',
		writePolicy,
		capabilities,
		resourceDeps,
	}
}

/** Build a filtered fixture context without contacting the store. */
export function resolveServerContext(
	capabilities: ApiCapabilities | null = null,
	additionalTools: readonly ToolDefinition[] = [],
): ServerContext {
	return buildServerContext(resolveApiUrls(resolveConfig()), capabilities, additionalTools)
}

async function discoverAbilityTools(config: ResolvedConfig): Promise<ToolDefinition[]> {
	const abilityConfig = resolveAbilitiesConfig()
	if (!abilityConfig.enabled) return []

	const client = createAbilitiesClient({ url: config.url, ...abilityConfig })
	const abilities = await client.discover()
	if (abilities.length === 0) {
		throw new Error(
			'The WordPress Abilities bridge is enabled, but no audited FluentCart read abilities were discovered.',
		)
	}
	return createAbilityBridgeTools({
		abilities,
		execute: (name, input, signal) => client.execute(name, input, signal),
	})
}

/**
 * Resolve every store- and process-lifetime dependency once.
 *
 * Fresh MCP instances only register this prepared state; they never rediscover routes, rebuild
 * or reload the Code Mode host.
 */
export async function resolveRuntimeContext(mode: ToolsetMode): Promise<RuntimeContext> {
	const config = resolveConfig()
	const resolved = resolveApiUrls(config)
	const [capabilities, abilityTools] = await Promise.all([
		discoverApiCapabilities(config.url),
		discoverAbilityTools(resolved),
	])
	const runtime: RuntimeContext = buildServerContext(resolved, capabilities, abilityTools)
	if (mode === 'code') runtime.codeMode = await prepareCodeModeRuntime(runtime.tools)
	return runtime
}

/** Compatibility name for callers that do not yet need a prepared Code Mode host. */
export async function resolveServerContextAsync(): Promise<ServerContext> {
	return resolveRuntimeContext('full')
}
