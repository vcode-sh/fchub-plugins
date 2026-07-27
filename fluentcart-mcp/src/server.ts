import { createRequire } from 'node:module'
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import type { ApiCapabilities } from './api/capabilities.js'
import { discoverApiCapabilities } from './api/capabilities.js'
import type { FluentCartClient } from './api/client.js'
import { createClient } from './api/client.js'
import { buildCacheScope, PrincipalScopedCache } from './commerce/cache.js'
import { resolveConfig } from './config/resolver.js'
import { resolveApiUrls } from './config/types.js'
import { createLogger } from './logging.js'
import { registerPrompts } from './prompts.js'
import { registerResources } from './resources.js'
import { createGuardRuntime } from './security/guard-config.js'
import { canExposeTool, parseWriteMode, type WritePolicyConfig } from './security/write-policy.js'
import type { ToolDefinition } from './tools/_factory.js'
import { commerceContextTools } from './tools/commerce-context.js'
import { selectCuratedTools } from './tools/curated.js'
import { DYNAMIC_TOOL_COUNT, DYNAMIC_TOOL_NAMES, registerDynamicTools } from './tools/dynamic.js'
import { selectEndpoint } from './tools/endpoints.js'
import { createAllTools } from './tools/index.js'
import { referenceDataTools } from './tools/reference-data.js'

/** One cache per process, shared by tools and resources so their answers cannot diverge. */
const referenceCache = new PrincipalScopedCache()

const require = createRequire(import.meta.url)
const { version } = require('../package.json') as { version: string }

export type ToolsetMode = 'dynamic' | 'curated' | 'code' | 'full'

export const TOOLSET_MODES: readonly ToolsetMode[] = ['dynamic', 'curated', 'code', 'full']

/** Dynamic is the default because its definition payload is the smallest by a wide margin. */
export const DEFAULT_TOOLSET_MODE: ToolsetMode = 'dynamic'

export function parseToolsetMode(value: string | undefined): ToolsetMode {
	if (value === undefined || value === '') return DEFAULT_TOOLSET_MODE
	if ((TOOLSET_MODES as readonly string[]).includes(value)) return value as ToolsetMode
	throw new Error(`Invalid toolset mode "${value}". Expected one of: ${TOOLSET_MODES.join(', ')}.`)
}

export interface ServerContext {
	client: FluentCartClient
	/** Already filtered by the exposure policy. Hidden tools are absent, not merely disabled. */
	tools: ToolDefinition[]
	version: string
	configSource: string
	writePolicy: WritePolicyConfig
	/** Live route evidence, or null when discovery was explicitly skipped for a unit test. */
	capabilities: ApiCapabilities | null
}

/**
 * Read the write-exposure policy from the environment.
 *
 * Guard availability is reported honestly: without a signing secret and a persistent state
 * directory there is no way to prevent a replayed refund, so guarded tools stay hidden even
 * when the operator asked for guarded mode.
 */
/**
 * Decide whether the connected store actually serves what a tool needs.
 *
 * A direct tool needs any ONE of its declared variants, which is what ordered fallbacks are
 * for. A composite needs EVERY route it may call: registering one whose third call is missing
 * would let it fail halfway through, which for a refund is materially worse than never
 * offering it. Without capability evidence nothing is pruned — discovery failure is already a
 * startup error, so this path is reached only by unit tests that supply no registry.
 */
function isRouteSupported(tool: ToolDefinition, capabilities: ApiCapabilities | null): boolean {
	if (!capabilities) return true

	const routes = tool.routes
	if (!routes) return true

	if (routes.kind === 'composite') {
		return routes.variants.every((variant) => selectEndpoint(capabilities, [variant]) !== null)
	}

	return selectEndpoint(capabilities, routes.variants) !== null
}

export function resolveWritePolicy(): WritePolicyConfig {
	return {
		writeMode: parseWriteMode(process.env.FLUENTCART_WRITE_MODE),
		guard: {
			persistentState: Boolean(process.env.FLUENTCART_GUARD_STATE_DIR),
			signingSecret: (process.env.FLUENTCART_GUARD_SECRET ?? '').length >= 32,
		},
	}
}

/**
 * Build the process-wide context without contacting the store.
 *
 * Used by unit tests and by callers that supply their own capability evidence. Production
 * startup goes through `resolveServerContextAsync`, which additionally proves the routes exist.
 */
export function resolveServerContext(capabilities: ApiCapabilities | null = null): ServerContext {
	const config = resolveConfig()
	const resolved = resolveApiUrls(config)
	const client = createClient(resolved)
	const writePolicy = resolveWritePolicy()

	// One filtered, immutable registry shared by every exposure mode. A tool removed here
	// cannot be listed, searched, described or called by name in any mode.
	// Resolve guard state once per process; a handler must never construct it per request.
	const guard = writePolicy.writeMode === 'guarded' ? createGuardRuntime() : null
	const tools = createAllTools(client, { guard, capabilities: capabilities ?? undefined })
		.filter((tool) => canExposeTool(tool.safety, writePolicy))
		.filter((tool) => isRouteSupported(tool, capabilities))
	// Reference data shares one principal-scoped cache with the MCP resources, so a list read
	// through a resource and the same list read through the tool cannot disagree. The scope is
	// keyed by store origin, a principal digest and the route profile — never raw credentials.
	const cacheScope = buildCacheScope({
		storeUrl: resolved.url,
		username: resolved.username,
		routeProfile: capabilities ? String(capabilities.operations.size) : 'undiscovered',
	})
	const referenceTools = referenceDataTools(client, {
		cache: referenceCache,
		scope: cacheScope,
	})
		.filter((tool) => canExposeTool(tool.safety, writePolicy))
		.filter((tool) => isRouteSupported(tool, capabilities))

	// Store context is composed AFTER filtering, because it reports which tools survived the
	// capability and write-policy filters. Building it inside createAllTools would require the
	// filtered registry before the filter had run.
	const contextTools = commerceContextTools(client, {
		profile: null,
		operations: capabilities ? [...capabilities.operations] : null,
		exposedToolNames: [...tools, ...referenceTools].map((tool) => tool.name),
		storeUrl: resolved.url,
	}).filter((tool) => canExposeTool(tool.safety, writePolicy))

	const configSource = process.env.FLUENTCART_URL ? 'env' : 'file'

	return {
		client,
		tools: [...tools, ...referenceTools, ...contextTools],
		version,
		configSource,
		writePolicy,
		capabilities,
	}
}

/**
 * Production startup: resolve configuration, then discover what the store actually exposes.
 *
 * Discovery failure is fatal by design. Registering a tool whose route may not exist trades a
 * clear startup error for a confusing runtime 404 in the middle of somebody's workflow.
 */
export async function resolveServerContextAsync(): Promise<ServerContext> {
	const config = resolveConfig()
	const capabilities = await discoverApiCapabilities(config.url)
	return resolveServerContext(capabilities)
}

/**
 * Options every server instance is built with.
 *
 * `logging` has to be declared or the SDK drops log notifications on the floor: its
 * `sendLoggingMessage` is wrapped in `if (this._capabilities.logging)` and returns silently
 * otherwise. Without this the whole of `createLogger` was dead code — the startup line naming the
 * mode and tool count was assembled, redacted and then discarded, and the Inspector's
 * Notifications pane stayed empty no matter what the server did.
 *
 * Declaring it also makes the SDK register the `logging/setLevel` handler and filter messages
 * below the client's chosen level, so the capability is honoured rather than merely advertised.
 */
const SERVER_OPTIONS = { capabilities: { logging: {} } } as const

export function createServerFromContext(
	ctx: ServerContext,
	mode: ToolsetMode = DEFAULT_TOOLSET_MODE,
): McpServer {
	const server = new McpServer(
		{
			name: 'fluentcart-mcp',
			version: ctx.version,
		},
		SERVER_OPTIONS,
	)

	// What actually got registered, so prompts can route around whatever this mode omits.
	const registered = new Set<string>()

	// Every mode draws from the same already-filtered registry, so no mode can widen exposure.
	if (mode === 'dynamic') {
		registerDynamicTools(server, ctx.tools)
		for (const name of DYNAMIC_TOOL_NAMES) registered.add(name)
	} else {
		if (mode === 'code') {
			// Code mode registers exactly two meta-tools over a QuickJS sandbox, and starting that
			// sandbox is asynchronous. Registering every read tool instead would be full mode with
			// the writes removed — precisely the definition payload code mode exists to avoid.
			throw new Error(
				'Toolset mode "code" requires createServerFromContextAsync(); the sandbox starts asynchronously.',
			)
		}

		const selected = mode === 'curated' ? selectCuratedTools(ctx.tools) : ctx.tools

		for (const tool of selected) {
			server.registerTool(
				tool.name,
				{
					title: tool.title,
					description: tool.description,
					inputSchema: tool.schema,
					annotations: tool.annotations,
				},
				tool.handler,
			)
			registered.add(tool.name)
		}
	}

	registerResources(server, ctx.client)
	// Prompts name a tool only when that tool is registered here, so no mode can advertise a
	// workflow it cannot actually run.
	registerPrompts(server, registered)

	const toolCount =
		mode === 'dynamic'
			? DYNAMIC_TOOL_COUNT
			: mode === 'curated'
				? selectCuratedTools(ctx.tools).length
				: ctx.tools.length
	announceStartup(
		server,
		`fluentcart-mcp v${version} started — ${toolCount} tools registered (${mode} mode)`,
		ctx.configSource,
	)

	return server
}

/**
 * Send the startup lines once a client is actually listening.
 *
 * These used to be sent during construction, which is before any transport is connected. That
 * only appeared to work because the SDK was silently discarding every log notification for want
 * of a declared `logging` capability; declaring it turned the same call into a "Not connected"
 * throw. A log notification has no meaning before a client has initialised anyway, so the lines
 * are emitted from the `initialized` notification instead — the point at which somebody is there
 * to read them.
 */
function announceStartup(server: McpServer, summary: string, configSource: string): void {
	server.server.oninitialized = () => {
		const logger = createLogger(server)
		logger.info(summary)
		logger.debug(`config source: ${configSource}`)
	}
}

/**
 * Asynchronous server construction, required for code mode.
 *
 * Code mode must prove its WebAssembly runtime actually starts before advertising a sandbox,
 * so registration cannot be synchronous. Every other mode is delegated unchanged.
 */
export async function createServerFromContextAsync(
	ctx: ServerContext,
	mode: ToolsetMode = DEFAULT_TOOLSET_MODE,
): Promise<McpServer> {
	if (mode !== 'code') return createServerFromContext(ctx, mode)

	const server = new McpServer({ name: 'fluentcart-mcp', version: ctx.version }, SERVER_OPTIONS)
	const { registerCodeModeTools } = await import('./tools/code-mode.js')
	const registration = await registerCodeModeTools(server, ctx.tools)

	if (!registration.registered) {
		// Refuse rather than fall back to a non-sandboxed dispatcher wearing the same name.
		throw new Error(registration.reason ?? 'Code mode is unavailable on this platform.')
	}

	registerResources(server, ctx.client)
	registerPrompts(server, new Set(registration.toolNames))

	announceStartup(
		server,
		`fluentcart-mcp v${version} started — ${registration.toolNames.length} tools registered (code mode)`,
		ctx.configSource,
	)

	return server
}

export function createServer(mode: ToolsetMode = DEFAULT_TOOLSET_MODE): McpServer {
	return createServerFromContext(resolveServerContext(), mode)
}
