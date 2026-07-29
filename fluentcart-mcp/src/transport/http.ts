import type { Express } from 'express'
import {
	createMcpServerFactory,
	createServerFromContextAsync,
	DEFAULT_TOOLSET_MODE,
	resolveRuntimeContext,
	type ServerContext,
	type ToolsetMode,
} from '../server.js'
import type { HttpExposureConfig, HttpExposureInput } from './http-config.js'
import { resolveHttpExposure } from './http-config.js'
import {
	createHttpApplication,
	type HttpServiceHandle,
	type HttpStartOptions,
	startHttpService,
} from './http-service.js'

export {
	createHttpApplication,
	type HttpMiddlewareStage,
	type HttpServiceHandle,
	startHttpService,
} from './http-service.js'

function localExposure(host: string): HttpExposureConfig {
	return resolveHttpExposure({
		profile: 'local',
		host,
		bearerKey: process.env.FLUENTCART_MCP_API_KEY,
	})
}

/** Compatibility fixture entry: production startup resolves one process-lifetime runtime. */
export function createAppFromContext(
	host: string,
	context: ServerContext,
	mode: ToolsetMode = DEFAULT_TOOLSET_MODE,
): Express {
	return createHttpApplication(({ era }) => {
		if (era !== 'legacy' && era !== 'modern') {
			throw new Error(`Unsupported MCP era: ${String(era)}`)
		}
		return createServerFromContextAsync(context, mode)
	}, localExposure(host)).app
}

export async function createApp(
	host: string,
	mode: ToolsetMode = DEFAULT_TOOLSET_MODE,
): Promise<Express> {
	const runtime = await resolveRuntimeContext(mode)
	return createHttpApplication(createMcpServerFactory(runtime, mode), localExposure(host)).app
}

export async function startHttpServer(
	port: number,
	input: HttpExposureInput,
	mode: ToolsetMode = DEFAULT_TOOLSET_MODE,
	options: HttpStartOptions = {},
): Promise<HttpServiceHandle> {
	const config = resolveHttpExposure(input)
	const runtime = await resolveRuntimeContext(mode)
	const handle = await startHttpService(
		createMcpServerFactory(runtime, mode),
		port,
		config,
		options,
	)
	console.error(`FluentCart MCP server listening on ${handle.url}/mcp`)
	return handle
}
