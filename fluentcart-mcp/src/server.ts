import { type CacheHint, McpServer, type McpServerFactory } from '@modelcontextprotocol/server'
import { registerPrompts } from './prompts.js'
import { registerResources } from './resources.js'
import { type RuntimeContext, resolveServerContext, type ServerContext } from './server-context.js'
import { type PreparedCodeModeRuntime, registerCodeModeTools } from './tools/code-mode.js'
import { selectCuratedTools } from './tools/curated.js'
import { registerDynamicTools } from './tools/dynamic.js'

export {
	purgeAuthorizationCaches,
	type RuntimeContext,
	resolveRuntimeContext,
	resolveServerContext,
	resolveServerContextAsync,
	resolveWritePolicy,
	type ServerContext,
} from './server-context.js'

export type ToolsetMode = 'dynamic' | 'curated' | 'code' | 'full'

export const TOOLSET_MODES: readonly ToolsetMode[] = ['dynamic', 'curated', 'code', 'full']

/** Dynamic is the default because its definition payload is the smallest by a wide margin. */
export const DEFAULT_TOOLSET_MODE: ToolsetMode = 'dynamic'

export function parseToolsetMode(value: string | undefined): ToolsetMode {
	if (value === undefined || value === '') return DEFAULT_TOOLSET_MODE
	if ((TOOLSET_MODES as readonly string[]).includes(value)) return value as ToolsetMode
	throw new Error(`Invalid toolset mode "${value}". Expected one of: ${TOOLSET_MODES.join(', ')}.`)
}

/**
 * What a client is told about this server before it lists a single tool.
 *
 * The SDK returns this during legacy initialise and modern discovery. Clients may prepend it to
 * a model's context, so it is kept to the handful of things a caller demonstrably gets wrong
 * without being told and no longer.
 */
const SERVER_INSTRUCTIONS = [
	'FluentCart store administration over the WordPress REST API.',
	'Writes are absent unless FLUENTCART_WRITE_MODE exposes them; do not retry a missing tool. Dynamic mode: search, describe, then use its risk-matched executor.',
	'Sales reports require from, to and currency. Timezone is optional and echoed only as a label; FluentCart filters in its store timezone. Read warnings before quoting figures. Entity money uses integer minor units; sales reports use decimals. Never sum currencies.',
].join('\n\n')

const PRIVATE_NO_CACHE = { ttlMs: 0, cacheScope: 'private' } as const

/**
 * Every cacheable 2026 result is deliberately private and immediately stale.
 *
 * The SDK currently uses these values as its defaults. Keeping the complete closed set here
 * prevents a future SDK default from becoming an accidental public freshness claim before the
 * server has invalidation semantics worth claiming.
 */
export const SERVER_CACHE_HINTS = {
	'tools/list': PRIVATE_NO_CACHE,
	'prompts/list': PRIVATE_NO_CACHE,
	'resources/list': PRIVATE_NO_CACHE,
	'resources/templates/list': PRIVATE_NO_CACHE,
	'resources/read': PRIVATE_NO_CACHE,
	'server/discover': PRIVATE_NO_CACHE,
} as const satisfies Record<string, CacheHint>

const SERVER_OPTIONS = {
	capabilities: {
		tools: { listChanged: false },
		resources: { listChanged: false },
		prompts: { listChanged: false },
	},
	instructions: SERVER_INSTRUCTIONS,
	cacheHints: SERVER_CACHE_HINTS,
} as const

/** Fixture-only Code Mode preparation, single-flight per compatibility context. */
const compatibilityCodeModePreparations = new WeakMap<
	ServerContext,
	Promise<PreparedCodeModeRuntime>
>()

function newServer(runtime: ServerContext): McpServer {
	return new McpServer({ name: 'fluentcart-mcp', version: runtime.version }, SERVER_OPTIONS)
}

function registerDirectTools(
	server: McpServer,
	runtime: ServerContext,
	mode: Exclude<ToolsetMode, 'dynamic' | 'code'>,
): Set<string> {
	const selected = mode === 'curated' ? selectCuratedTools(runtime.tools) : runtime.tools
	const names = new Set<string>()

	for (const tool of selected) {
		server.registerTool(
			tool.name,
			{
				title: tool.title,
				description: tool.description,
				inputSchema: tool.schema,
				annotations: tool.annotations,
			},
			async (input, requestContext) =>
				tool.handler(input, { signal: requestContext.mcpReq.signal }),
		)
		names.add(tool.name)
	}
	return names
}

function registerCommonSurface(
	server: McpServer,
	runtime: ServerContext,
	toolNames: ReadonlySet<string>,
): McpServer {
	registerResources(server, runtime.client, runtime.resourceDeps)
	registerPrompts(server, toolNames)
	return server
}

function createSynchronousServer(runtime: ServerContext, mode: ToolsetMode): McpServer {
	if (mode === 'code') {
		throw new Error(
			'Toolset mode "code" requires a prepared RuntimeContext and createMcpServerFactory() (or the compatibility createServerFromContextAsync()).',
		)
	}

	const server = newServer(runtime)
	const names =
		mode === 'dynamic'
			? new Set(registerDynamicTools(server, runtime.tools))
			: registerDirectTools(server, runtime, mode)
	return registerCommonSurface(server, runtime, names)
}

async function createRegisteredServer(
	runtime: RuntimeContext,
	mode: ToolsetMode,
): Promise<McpServer> {
	if (mode !== 'code') return createSynchronousServer(runtime, mode)
	if (!runtime.codeMode) {
		throw new Error('Code Mode runtime was not prepared before the MCP server factory was created.')
	}

	const server = newServer(runtime)
	const registration = await registerCodeModeTools(server, runtime.tools, {
		runtime: runtime.codeMode,
	})
	if (!registration.registered) {
		throw new Error(registration.reason ?? 'Code mode is unavailable on this platform.')
	}
	return registerCommonSurface(server, runtime, new Set(registration.toolNames))
}

/**
 * Create a per-serving-unit factory over one process-lifetime runtime.
 *
 * The era is validated for diagnostics only. Registration deliberately has no era branch, so the
 * modern discovery probe and legacy initialize path see the same surface.
 */
export function createMcpServerFactory(
	runtime: RuntimeContext,
	mode: ToolsetMode,
): McpServerFactory {
	return async ({ era }) => {
		if (era !== 'modern' && era !== 'legacy') throw new Error(`Unsupported MCP era: ${String(era)}`)
		return createRegisteredServer(runtime, mode)
	}
}

/** Compatibility constructor for fixture and acceptance tests outside the serving entries. */
export function createServerFromContext(
	ctx: ServerContext,
	mode: ToolsetMode = DEFAULT_TOOLSET_MODE,
): McpServer {
	return createSynchronousServer(ctx, mode)
}

/**
 * Compatibility constructor retained until the serving entries switch to `createMcpServerFactory`.
 * Production startup prepares Code Mode through `resolveRuntimeContext`; this fallback exists for
 * fixture callers that intentionally build a context without live discovery.
 */
export async function createServerFromContextAsync(
	ctx: ServerContext,
	mode: ToolsetMode = DEFAULT_TOOLSET_MODE,
	codeModeRuntime?: PreparedCodeModeRuntime,
): Promise<McpServer> {
	if (mode !== 'code') return createSynchronousServer(ctx, mode)
	const runtime = ctx as RuntimeContext
	if (codeModeRuntime) runtime.codeMode ??= codeModeRuntime
	if (!runtime.codeMode) {
		let preparation = compatibilityCodeModePreparations.get(ctx)
		if (!preparation) {
			preparation = import('./tools/code-mode.js').then(({ prepareCodeModeRuntime }) =>
				prepareCodeModeRuntime(ctx.tools),
			)
			compatibilityCodeModePreparations.set(ctx, preparation)
		}
		try {
			runtime.codeMode = await preparation
		} catch (error) {
			if (compatibilityCodeModePreparations.get(ctx) === preparation) {
				compatibilityCodeModePreparations.delete(ctx)
			}
			throw error
		}
	}
	return createRegisteredServer(runtime, mode)
}

export function createServer(mode: ToolsetMode = DEFAULT_TOOLSET_MODE): McpServer {
	return createServerFromContext(resolveServerContext(), mode)
}
