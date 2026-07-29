#!/usr/bin/env node

import { inputRequired, McpServer } from '@modelcontextprotocol/server'
import { z } from 'zod'
import { startHttpService } from '../dist/transport/http-service.js'

const LOCAL_EXPOSURE = {
	profile: 'local',
	host: '127.0.0.1',
}

const JSON_SCHEMA_2020_12 = {
	$schema: 'https://json-schema.org/draft/2020-12/schema',
	type: 'object',
	$defs: {
		address: {
			$anchor: 'addressDef',
			type: 'object',
			properties: {
				street: { type: 'string' },
				city: { type: 'string' },
			},
		},
	},
	properties: {
		name: { type: 'string' },
		address: { $ref: '#/$defs/address' },
		contactMethod: { type: 'string', enum: ['phone', 'email'] },
		phone: { type: 'string' },
		email: { type: 'string' },
	},
	allOf: [{ anyOf: [{ required: ['phone'] }, { required: ['email'] }] }],
	if: {
		properties: { contactMethod: { const: 'phone' } },
		required: ['contactMethod'],
	},
	then: { required: ['phone'] },
	else: { required: ['email'] },
	additionalProperties: false,
}

const schema2020 = {
	'~standard': {
		version: 1,
		vendor: 'fluentcart-mcp-conformance',
		validate(value) {
			if (typeof value === 'object' && value !== null && !Array.isArray(value)) return { value }
			return { issues: [{ message: 'Expected an object.' }] }
		},
		jsonSchema: {
			input: () => JSON_SCHEMA_2020_12,
			output: () => JSON_SCHEMA_2020_12,
		},
	},
}

export const DIAGNOSTIC_SURFACE = Object.freeze({
	tools: [
		'json_schema_2020_12_tool',
		'test_logging_tool',
		'test_missing_capability',
		'test_simple_text',
		'test_streaming_elicitation',
	],
	resources: ['test://static-text'],
	prompts: ['test_prompt_with_arguments', 'test_simple_prompt'],
})

function userMessage(text) {
	return { messages: [{ role: 'user', content: { type: 'text', text } }] }
}

function createFixtureServer() {
	const server = new McpServer(
		{ name: 'fluentcart-mcp-conformance-fixture', version: '1.0.0' },
		{
			capabilities: {
				tools: { listChanged: false },
				resources: { listChanged: false },
				prompts: { listChanged: false },
			},
		},
	)

	server.registerTool(
		'test_simple_text',
		{
			description: 'Return the official conformance simple-text fixture.',
			inputSchema: z.object({}),
		},
		async () => ({
			content: [{ type: 'text', text: 'This is a simple text response for testing.' }],
		}),
	)
	server.registerTool(
		'json_schema_2020_12_tool',
		{
			description: 'Tool with JSON Schema 2020-12 features.',
			inputSchema: schema2020,
		},
		async () => ({ content: [{ type: 'text', text: 'Schema accepted.' }] }),
	)
	server.registerTool(
		'test_missing_capability',
		{
			description: 'Exercise the modern missing-client-capability error contract.',
			inputSchema: z.object({}),
		},
		async () =>
			inputRequired({
				inputRequests: {
					sample: inputRequired.createMessage({
						messages: [
							{
								role: 'user',
								content: { type: 'text', text: 'Synthetic capability probe.' },
							},
						],
						maxTokens: 1,
					}),
				},
			}),
	)
	server.registerTool(
		'test_streaming_elicitation',
		{
			description: 'Emit a related progress notification before the terminal result.',
			inputSchema: z.object({}),
		},
		async (_input, context) => {
			await context.mcpReq.notify({
				method: 'notifications/progress',
				params: { progressToken: 'fixture-progress', progress: 1, total: 1 },
			})
			return { content: [{ type: 'text', text: 'Streaming complete.' }] }
		},
	)
	server.registerTool(
		'test_logging_tool',
		{
			description: 'Return without emitting deprecated MCP logging notifications.',
			inputSchema: z.object({}),
		},
		async () => ({ content: [{ type: 'text', text: 'Logging evaluated.' }] }),
	)

	server.registerResource(
		'static-text',
		'test://static-text',
		{ description: 'Official conformance static text resource.', mimeType: 'text/plain' },
		async (uri) => ({
			contents: [
				{
					uri: uri.href,
					mimeType: 'text/plain',
					text: 'This is the content of the static text resource.',
				},
			],
		}),
	)

	server.registerPrompt(
		'test_simple_prompt',
		{ description: 'Official conformance simple prompt.' },
		() => userMessage('This is a simple prompt for testing.'),
	)
	server.registerPrompt(
		'test_prompt_with_arguments',
		{
			description: 'Official conformance prompt with two arguments.',
			argsSchema: z.object({
				arg1: z.string().describe('First test argument.'),
				arg2: z.string().describe('Second test argument.'),
			}),
		},
		({ arg1, arg2 }) =>
			userMessage(`Prompt with arguments: arg1='${arg1}', arg2='${arg2}'`),
	)
	return server
}

export function createConformanceFactory() {
	return async ({ era }) => {
		if (era !== 'legacy' && era !== 'modern') {
			throw new Error(`Unsupported MCP era: ${String(era)}`)
		}
		return createFixtureServer()
	}
}

export function startConformanceFixture() {
	return startHttpService(createConformanceFactory(), 0, LOCAL_EXPOSURE)
}
