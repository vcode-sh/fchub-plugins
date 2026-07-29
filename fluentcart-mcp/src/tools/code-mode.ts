import type { McpServer } from '@modelcontextprotocol/server'
import { z } from 'zod'
import { buildApiIndex, MAX_SEARCH_RESULTS, type ReadOnlyApiIndex } from '../code-mode/api-index.js'
import { CODE_MODE_LIMITS } from '../code-mode/limits.js'
import { CodeSandbox, type SandboxOptions } from '../code-mode/sandbox.js'
import type { ToolDefinition } from './_factory.js'

/** Code mode registers exactly two tools: one to discover reads, one to run them. */
export const CODE_MODE_TOOL_COUNT = 2

export interface CodeModeRegistration {
	registered: boolean
	/** Why registration was skipped. Present only when `registered` is false. */
	reason?: string
	toolNames: readonly string[]
}

export interface CodeModeOptions extends SandboxOptions {
	/** Injected in tests so registration can be exercised without a real WebAssembly start. */
	sandbox?: CodeSandbox
	/** Skip the WebAssembly self-test. Only ever set by tests that supply their own sandbox. */
	skipSelfTest?: boolean
	/** App-lifetime host already self-tested by the HTTP transport. */
	runtime?: PreparedCodeModeRuntime
}

export interface PreparedCodeModeRuntime {
	index: ReadOnlyApiIndex
	sandbox: CodeSandbox
}

const EXECUTION_GUIDE = [
	'Write plain JavaScript (ES2023) and `return` one JSON-serialisable value.',
	'Call reads with `await fluentcart.call(operation, input)`.',
	'There is no network, filesystem, environment, timer, module system or console.',
	`At most ${CODE_MODE_LIMITS.maxApiCalls} calls, ${CODE_MODE_LIMITS.maxWallClockMs / 1000} seconds and ${CODE_MODE_LIMITS.maxOutputCharacters} characters of output per execution.`,
].join(' ')

const searchSchema = z.object({
	query: z
		.string()
		.min(1)
		.describe(
			'Business keywords describing the data you need, for example "paid orders last month" or "customer lifetime value". Matched against operation names, titles and descriptions.',
		),
	limit: z
		.number()
		.int()
		.min(1)
		.max(MAX_SEARCH_RESULTS)
		.optional()
		.describe(
			`Maximum number of operation declarations to return (default: ${MAX_SEARCH_RESULTS}, maximum: ${MAX_SEARCH_RESULTS}). Lower this if a search is rejected for exceeding the response budget.`,
		),
})

const executeSchema = z.object({
	code: z
		.string()
		.min(1)
		.max(CODE_MODE_LIMITS.maxSourceCharacters)
		.describe(
			`JavaScript executed inside a read-only WebAssembly sandbox. ${EXECUTION_GUIDE} Discover operation names with fluentcart_search_api first.`,
		),
})

function textResult(payload: unknown, isError = false) {
	return {
		content: [{ type: 'text' as const, text: JSON.stringify(payload) }],
		...(isError ? { isError: true } : {}),
	}
}

function handleSearch(index: ReadOnlyApiIndex, query: string, limit?: number) {
	const operations = index.search(query, limit ?? MAX_SEARCH_RESULTS)

	if (operations.length === 0) {
		return textResult({
			matches: 0,
			read_operations_available: index.size,
			hint: 'No read operation matched. Try broader commerce vocabulary such as "order", "customer", "product", "subscription" or "report".',
		})
	}

	const payload = { matches: operations.length, operations, usage: EXECUTION_GUIDE }
	const serialised = JSON.stringify(payload)

	// The whole-response rule: never hand back a partial declaration list dressed as a complete
	// one. A caller who asked for five and cannot have five is told to ask for fewer.
	if (serialised.length > CODE_MODE_LIMITS.maxOutputCharacters) {
		return textResult(
			{
				error: 'RESPONSE_TOO_LARGE',
				message: `These ${operations.length} declarations serialise to ${serialised.length} characters, over the ${CODE_MODE_LIMITS.maxOutputCharacters} character budget. Repeat the search with a smaller limit or a narrower query.`,
			},
			true,
		)
	}

	return textResult(payload)
}

async function handleExecute(sandbox: CodeSandbox, code: string, signal?: AbortSignal) {
	const result = await sandbox.execute(code, signal)

	if (!result.ok || result.json === undefined) {
		const error = result.error
		return textResult(
			{
				error: error?.code ?? 'UNCAUGHT_EXCEPTION',
				message: error?.message ?? 'Execution failed.',
				...(error?.details === undefined ? {} : { details: error.details }),
				api_calls: result.callCount,
			},
			true,
		)
	}

	// One complete JSON document. `result.json` is already the exact serialisation the budget
	// was measured against, so it is spliced in rather than re-serialised.
	return {
		content: [
			{
				type: 'text' as const,
				text: `{"result":${result.json},"api_calls":${result.callCount}}`,
			},
		],
	}
}

/**
 * Build and self-test the immutable Code Mode host once.
 *
 * The host serialises access to one Asyncify module, while CodeSandbox still creates and destroys
 * a fresh runtime and context for every execution. Sharing this object therefore removes repeated
 * WebAssembly load/self-test work without sharing a JavaScript realm between requests.
 */
export async function prepareCodeModeRuntime(
	tools: readonly ToolDefinition[],
	options: Omit<CodeModeOptions, 'runtime'> = {},
): Promise<PreparedCodeModeRuntime> {
	const index = buildApiIndex(tools)
	const sandbox = options.sandbox ?? new CodeSandbox(index, options)

	if (!options.skipSelfTest) {
		const selfTest = await sandbox.selfTest()
		if (!selfTest.ok) {
			throw new Error(
				`Code mode sandbox failed its startup self-test: ${selfTest.reason ?? 'unknown reason'}`,
			)
		}
	}

	return { index, sandbox }
}

/**
 * Register the read-only code-mode surface.
 *
 * The registry passed in must already be capability and write-policy filtered; this function
 * narrows it further to reads and never sees a write executor afterwards. Registration is
 * refused outright when the WebAssembly runtime cannot start, so the server does not advertise
 * a sandbox it cannot actually provide.
 */
export async function registerCodeModeTools(
	server: McpServer,
	tools: readonly ToolDefinition[],
	options: CodeModeOptions = {},
): Promise<CodeModeRegistration> {
	let runtime: PreparedCodeModeRuntime
	try {
		runtime = options.runtime ?? (await prepareCodeModeRuntime(tools, options))
	} catch (error) {
		return {
			registered: false,
			reason: error instanceof Error ? error.message : String(error),
			toolNames: [],
		}
	}
	const { index, sandbox } = runtime

	server.registerTool(
		'fluentcart_search_api',
		{
			title: 'Search FluentCart Read API',
			description: `Find read-only FluentCart operations by keyword. Returns up to ${MAX_SEARCH_RESULTS} compact TypeScript declarations you can call from fluentcart_execute_code. Only operations that read data are ever returned; writes, refunds and deletions are not available in code mode.`,
			inputSchema: searchSchema,
			annotations: {
				readOnlyHint: true,
				destructiveHint: false,
				idempotentHint: true,
				openWorldHint: false,
			},
		},
		async (input) => handleSearch(index, input.query, input.limit),
	)

	server.registerTool(
		'fluentcart_execute_code',
		{
			title: 'Execute Read-Only FluentCart Code',
			description: `Run JavaScript in an isolated WebAssembly sandbox to compose several FluentCart reads into one answer, avoiding a round trip per call. ${EXECUTION_GUIDE} The sandbox cannot write, refund, delete or reach anything except the read operations returned by fluentcart_search_api.`,
			inputSchema: executeSchema,
			annotations: {
				readOnlyHint: true,
				destructiveHint: false,
				idempotentHint: true,
				openWorldHint: true,
			},
		},
		async (input, requestContext) =>
			handleExecute(sandbox, input.code, requestContext.mcpReq.signal),
	)

	return {
		registered: true,
		toolNames: Object.freeze(['fluentcart_search_api', 'fluentcart_execute_code']),
	}
}
