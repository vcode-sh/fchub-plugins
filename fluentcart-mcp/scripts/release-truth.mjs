import { existsSync, readFileSync } from 'node:fs'
import { dirname, isAbsolute, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
export const RELEASE_STATE_PATH = join(
	PACKAGE_ROOT,
	'tests/fixtures/releases/previous-release-state.json',
)
const SEMVER = /^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/
const DIGEST = /^sha256:[a-f0-9]{64}$/
const REGISTRIES = new Set(['ghcr.io', 'docker.io'])
const PROTOCOL_COMPLIANCE_STATES = new Set([
	'implemented',
	'sdk-served',
	'not-advertised',
	'not-applicable',
])
const PROTOCOL_COMPLIANCE_IDS = [
	'stateless-requests',
	'server-discovery',
	'subscriptions',
	'removed-methods',
	'tasks-extension',
	'multi-round-trip-responses',
	'result-types',
	'stream-resumability',
	'extensions',
	'trace-context',
	'deterministic-lists',
	'standard-headers',
	'cache-hints',
	'resource-errors',
	'oauth-issuer-dcr',
	'json-schema-2020-12',
	'error-allocation',
	'deprecated-features',
]

const proof = (path, assertion) => ({ path, assertion })

export const PROTOCOL_COMPLIANCE_ROWS = [
	{
		id: 'stateless-requests',
		requirement: 'Modern HTTP requests work without MCP session state.',
		state: 'sdk-served',
		advertised: true,
		rationale: 'The v2 Streamable HTTP transport accepts independent discovery and tool requests.',
		proofs: [
			proof(
				'tests/protocol/modern-wire-contract.test.mjs',
				'Raw modern HTTP requests complete without an MCP-Session-Id request or response header.',
			),
		],
	},
	{
		id: 'server-discovery',
		requirement: 'The server exposes the 2026-07-28 discovery operation.',
		state: 'sdk-served',
		advertised: true,
		rationale: 'The official v2 SDK serves server discovery for the modern protocol.',
		proofs: [
			proof(
				'tests/protocol/modern-wire-contract.test.mjs',
				'Raw HTTP and STDIO discovery responses report the selected protocol and supported versions.',
			),
		],
	},
	{
		id: 'subscriptions',
		requirement: 'Subscription capabilities are declared only when implemented.',
		state: 'not-advertised',
		advertised: false,
		rationale: 'The server provides request-response tools and does not expose subscription capabilities.',
		proofs: [
			proof(
				'tests/server-protocol-surface.test.ts',
				'The server capability surface omits resource, prompt and tool-list subscriptions.',
			),
		],
	},
	{
		id: 'removed-methods',
		requirement: 'Methods removed from the modern protocol are not exposed.',
		state: 'not-advertised',
		advertised: false,
		rationale: 'The modern surface contains discovery and tool operations, not removed legacy methods.',
		proofs: [
			proof(
				'tests/protocol/modern-wire-contract.test.mjs',
				'The raw modern contract rejects or omits methods outside the declared surface.',
			),
		],
	},
	{
		id: 'tasks-extension',
		requirement: 'Task capabilities are declared only when implemented.',
		state: 'not-advertised',
		advertised: false,
		rationale: 'Long-running MCP tasks are outside this synchronous FluentCart tool server.',
		proofs: [
			proof(
				'tests/protocol/modern-wire-contract.test.mjs',
				'Discovery and capabilities contain no tasks extension.',
			),
		],
	},
	{
		id: 'multi-round-trip-responses',
		requirement: 'Multi-round-trip responses are declared only when implemented.',
		state: 'not-advertised',
		advertised: false,
		rationale: 'Every exposed operation completes as a single request-response exchange.',
		proofs: [
			proof(
				'tests/protocol/modern-wire-contract.test.mjs',
				'The advertised modern surface contains no multi-round-trip response capability.',
			),
		],
	},
	{
		id: 'result-types',
		requirement: 'Modern discovery and tool results carry SDK-defined result type metadata.',
		state: 'sdk-served',
		advertised: true,
		rationale: 'The v2 SDK emits standard result-type metadata for modern responses.',
		proofs: [
			proof(
				'tests/protocol/modern-wire-contract.test.mjs',
				'Discovery, list and tool response metadata is asserted on the raw wire.',
			),
		],
	},
	{
		id: 'stream-resumability',
		requirement: 'Stream resumability is declared only when implemented.',
		state: 'not-advertised',
		advertised: false,
		rationale: 'The server intentionally has no SSE stream, event store or resumption token.',
		proofs: [
			proof(
				'tests/transport/http.test.ts',
				'HTTP GET does not open an SSE stream and the transport creates no resumable session.',
			),
		],
	},
	{
		id: 'extensions',
		requirement: 'Protocol extensions are declared only when implemented.',
		state: 'not-advertised',
		advertised: false,
		rationale: 'No custom protocol extension namespace is exposed.',
		proofs: [
			proof(
				'tests/protocol/modern-wire-contract.test.mjs',
				'Discovery contains no extensions declaration.',
			),
		],
	},
	{
		id: 'trace-context',
		requirement: 'Trace-context propagation is declared only when implemented.',
		state: 'not-advertised',
		advertised: false,
		rationale: 'The server emits structured operational logs but does not advertise trace propagation.',
		proofs: [
			proof(
				'tests/logging-capability.test.ts',
				'Logging remains an internal stderr concern and is not exposed as an MCP capability.',
			),
		],
	},
	{
		id: 'deterministic-lists',
		requirement: 'Repeated list operations return a deterministic order.',
		state: 'implemented',
		advertised: true,
		rationale: 'The server owns and tests the stable order of its exposed tool registry.',
		proofs: [
			proof(
				'tests/protocol/modern-wire-contract.test.mjs',
				'Repeated raw tools/list calls return the same ordered names.',
			),
		],
	},
	{
		id: 'standard-headers',
		requirement: 'Modern HTTP responses use the protocol standard headers.',
		state: 'sdk-served',
		advertised: true,
		rationale: 'The official v2 HTTP transport supplies the selected protocol and metadata headers.',
		proofs: [
			proof(
				'tests/protocol/modern-wire-contract.test.mjs',
				'Raw responses assert the modern protocol and content-type header contract.',
			),
		],
	},
	{
		id: 'cache-hints',
		requirement: 'Cacheable operations expose explicit cache metadata.',
		state: 'implemented',
		advertised: true,
		rationale: 'The server defines private zero-TTL hints for every cache-aware operation.',
		proofs: [
			proof(
				'tests/protocol/modern-wire-contract.test.mjs',
				'The six cache-aware operation families return exact private zero-TTL hints.',
			),
		],
	},
	{
		id: 'resource-errors',
		requirement: 'Resource and request failures use modern structured JSON-RPC errors.',
		state: 'sdk-served',
		advertised: true,
		rationale: 'The v2 SDK maps invalid input and server policy failures to structured error responses.',
		proofs: [
			proof(
				'tests/protocol/modern-wire-contract.test.mjs',
				'Raw requests assert -32602, -32020 and -32022 error allocations.',
			),
		],
	},
	{
		id: 'oauth-issuer-dcr',
		requirement: 'OAuth issuer and dynamic client registration requirements are applied when relevant.',
		state: 'not-applicable',
		advertised: false,
		rationale:
			'This private server authenticates with a static Bearer token and is neither an OAuth client nor an authorisation server.',
		proofs: [
			proof(
				'tests/transport/http.test.ts',
				'Private HTTP authentication is a configured Bearer token boundary with no OAuth endpoints.',
			),
		],
	},
	{
		id: 'json-schema-2020-12',
		requirement: 'Tool schemas use the SDK modern JSON Schema dialect contract.',
		state: 'sdk-served',
		advertised: true,
		rationale: 'Tool schemas are registered and serialised by the official v2 SDK.',
		proofs: [
			proof(
				'tests/tooling/sdk-v2-contract.test.mjs',
				'The SDK v2 registration and generated schema contract is pinned by executable tests.',
			),
		],
	},
	{
		id: 'error-allocation',
		requirement: 'Protocol and application errors occupy their allocated JSON-RPC ranges.',
		state: 'sdk-served',
		advertised: true,
		rationale: 'Protocol validation and server policy failures use distinct standard and server ranges.',
		proofs: [
			proof(
				'tests/protocol/modern-wire-contract.test.mjs',
				'Raw negative cases assert the exact standard and server error codes.',
			),
		],
	},
	{
		id: 'deprecated-features',
		requirement: 'Deprecated transports and optional capability aliases are absent.',
		state: 'not-advertised',
		advertised: false,
		rationale:
			'The server exposes STDIO and stateless Streamable HTTP without Roots, Sampling, MCP Logging, resumability or legacy HTTP+SSE.',
		proofs: [
			proof(
				'tests/protocol/modern-wire-contract.test.mjs',
				'The raw modern surface omits Roots, Sampling, MCP Logging and deprecated HTTP+SSE.',
			),
		],
	},
]

export function validateProtocolCompliance(rows) {
	if (!Array.isArray(rows)) throw new Error('protocol compliance must be an array')
	const byId = new Map()
	for (const row of rows) {
		if (!row || typeof row !== 'object' || Array.isArray(row)) {
			throw new Error('protocol compliance row must be an object')
		}
		if (byId.has(row.id)) throw new Error(`duplicate protocol compliance row: ${row.id}`)
		byId.set(row.id, row)
	}
	for (const id of PROTOCOL_COMPLIANCE_IDS) {
		if (!byId.has(id)) throw new Error(`missing protocol compliance row: ${id}`)
	}
	for (const id of byId.keys()) {
		if (!PROTOCOL_COMPLIANCE_IDS.includes(id)) {
			throw new Error(`unknown protocol compliance row: ${id}`)
		}
	}
	if (rows.some((row, index) => row.id !== PROTOCOL_COMPLIANCE_IDS[index])) {
		throw new Error('protocol compliance rows are not in reviewed order')
	}
	for (const row of rows) {
		if (!PROTOCOL_COMPLIANCE_STATES.has(row.state)) {
			throw new Error(`unknown protocol compliance state: ${row.state}`)
		}
		if (typeof row.advertised !== 'boolean') {
			throw new Error(`protocol compliance advertised flag must be boolean: ${row.id}`)
		}
		if (row.advertised && row.state === 'not-applicable') {
			throw new Error(`advertised feature cannot be not-applicable: ${row.id}`)
		}
		if (row.advertised && row.state === 'not-advertised') {
			throw new Error(`advertised feature cannot be not-advertised: ${row.id}`)
		}
		if (!row.requirement?.trim() || !row.rationale?.trim()) {
			throw new Error(`protocol compliance row lacks reviewed text: ${row.id}`)
		}
		if (!Array.isArray(row.proofs) || row.proofs.length === 0) {
			throw new Error(`protocol compliance row lacks proof: ${row.id}`)
		}
		for (const item of row.proofs) {
			if (!item?.path?.trim() || !item?.assertion?.trim()) {
				throw new Error(`protocol compliance proof is incomplete: ${row.id}`)
			}
			const absolute = resolve(PACKAGE_ROOT, item.path)
			const fromRoot = relative(PACKAGE_ROOT, absolute)
			if (isAbsolute(fromRoot) || fromRoot.startsWith(`..${process.platform === 'win32' ? '\\' : '/'}`)) {
				throw new Error(`protocol compliance proof escapes package root: ${item.path}`)
			}
			if (!existsSync(absolute)) {
				throw new Error(`protocol compliance proof path does not exist: ${item.path}`)
			}
		}
	}
	return rows
}

export const CONFIGURATION_RECIPES = [
	[
		'ChatGPT Desktop',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'STDIO',
			distribution: 'local',
			capabilitySource: 'https://learn.chatgpt.com/docs/extend/mcp',
			reason: 'manual configuration is documented, not certified',
		},
	],
	[
		'Codex CLI',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'STDIO',
			distribution: 'local',
			capabilitySource: 'https://learn.chatgpt.com/docs/extend/mcp',
			reason: 'manual configuration is documented, not certified',
		},
	],
	[
		'Codex IDE extension',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'STDIO',
			distribution: 'local',
			capabilitySource: 'https://learn.chatgpt.com/docs/extend/mcp',
			reason: 'manual configuration is documented, not certified',
		},
	],
	[
		'Claude Desktop',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'MCPB/STDIO',
			distribution: 'extension',
			capabilitySource:
				'https://support.claude.com/en/articles/10949351-getting-started-with-local-mcp-servers-on-claude-desktop',
			reason: 'manual configuration is documented, not certified',
		},
	],
	[
		'Cursor',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'STDIO',
			distribution: 'local',
			capabilitySource: 'https://docs.cursor.com/context/model-context-protocol',
			reason: 'manual configuration is documented, not certified',
		},
	],
	[
		'VS Code with GitHub Copilot',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'STDIO',
			distribution: 'local',
			capabilitySource: 'https://code.visualstudio.com/docs/copilot/chat/mcp-servers',
			reason: 'manual configuration is documented, not certified',
		},
	],
	[
		'Windsurf',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'STDIO',
			distribution: 'local',
			capabilitySource: 'https://docs.windsurf.com/windsurf/cascade/mcp',
			reason: 'manual configuration is documented, not certified',
		},
	],
	[
		'ChatGPT web',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'Secure MCP Tunnel',
			distribution: 'private web',
			capabilitySource: 'https://developers.openai.com/api/docs/guides/secure-mcp-tunnels',
			reason: 'manual configuration is documented, not certified',
		},
	],
]

export function validateReleaseState(state, candidateVersion) {
	if (state?.redacted !== true || state?.schemaVersion !== 1) {
		throw new Error('release state must be schema version 1 and explicitly redacted')
	}
	if (!SEMVER.test(state.npm?.previousLatest ?? '')) {
		throw new Error('release state is missing a concrete npm previousLatest semver')
	}
	if (state.npm.previousLatest === candidateVersion) {
		throw new Error('candidate version already equals npm latest')
	}
	if (state.candidate?.version !== candidateVersion) {
		throw new Error('release-state candidate does not match package version')
	}
	if (state.candidate.npmPublished !== false || state.candidate.remoteTagPresent !== false) {
		throw new Error('candidate version or tag already exists')
	}
	const digests = state.docker?.previousLatestDigests
	if (!digests || Object.keys(digests).length !== REGISTRIES.size) {
		throw new Error('release state must capture both public Docker registries')
	}
	for (const [registry, digest] of Object.entries(digests)) {
		if (!REGISTRIES.has(registry)) throw new Error(`unknown Docker registry: ${registry}`)
		if (!DIGEST.test(digest)) throw new Error(`${registry} reference is not an immutable digest`)
	}
	return state
}

export function buildReleaseTruth(pkg, releaseState = null, packageLock = null) {
	const state = validateReleaseState(
		releaseState ?? JSON.parse(readFileSync(RELEASE_STATE_PATH, 'utf8')),
		pkg.version,
	)
	const lock =
		packageLock ??
		JSON.parse(readFileSync(join(PACKAGE_ROOT, 'package-lock.json'), 'utf8'))
	const coreVersion = lock.packages?.['node_modules/@modelcontextprotocol/core']?.version
	if (typeof coreVersion !== 'string') {
		throw new Error('package-lock.json does not resolve @modelcontextprotocol/core')
	}
	const truth = {
		version: pkg.version,
		protocols: ['2025-11-25', '2026-07-28'],
		httpProfiles: ['local', 'private'],
		sdk: {
			server: pkg.dependencies['@modelcontextprotocol/server'],
			node: pkg.dependencies['@modelcontextprotocol/node'],
			express: pkg.dependencies['@modelcontextprotocol/express'],
			client: pkg.devDependencies['@modelcontextprotocol/client'],
			core: coreVersion,
		},
		conformance: {
			package: pkg.devDependencies['@modelcontextprotocol/conformance'],
			expectedFailures: [],
		},
		protocolCompliance: validateProtocolCompliance(PROTOCOL_COMPLIANCE_ROWS),
		promotion: {
			npmPublishing: 'trusted-oidc',
			npmTag: 'latest',
			npmApproval: 'automatic',
			npmPromotionWrite: false,
			previousLatest: state.npm.previousLatest,
			previousDockerDigests: state.docker.previousLatestDigests,
		},
		clients: Object.fromEntries(
			[
				[
					'automated',
					Object.fromEntries(
						[
							['MCP Inspector', ['stdio', 'streamable-http']],
							['Claude Code', ['stdio', 'streamable-http']],
							['Docker smoke', ['docker-http']],
						].map(([name, transports]) => [
							name,
							{
								status: 'AUTOMATED_CANDIDATE',
								transports,
								requirement: 'candidate-bound MCP handshake',
							},
						]),
					),
				],
				[
					'configurationRecipes',
					Object.fromEntries(CONFIGURATION_RECIPES),
				],
			],
		),
		evidence: {
			live: [
				'FluentCart Core 1.6.0 and FluentCart Pro 1.6.0 REST index',
				'WordPress 7.0.2 Abilities catalogue',
			],
			fixtures: [
				'legacy 1.3.9 route surface',
				'isolated FluentCart 1.6.0 Core route surface',
				'isolated FluentCart 1.6.0 Core and Pro route surface',
				'deterministic protocol and capability lanes',
			],
			finalCandidate: 'AUTOMATED_CLIENT_CERTIFICATION_REQUIRED',
		},
	}
	const support = JSON.parse(readFileSync(join(PACKAGE_ROOT, 'compatibility-support.json'), 'utf8'))
	const evidence = support.releaseEvidence
	if (
		evidence?.version !== truth.version ||
		JSON.stringify(evidence.protocols) !== JSON.stringify(truth.protocols) ||
		JSON.stringify(evidence.httpProfiles) !== JSON.stringify(truth.httpProfiles) ||
		evidence.finalCandidate !== truth.evidence.finalCandidate ||
		evidence.namedClients !== 'AUTOMATED_FIVE_REQUIRED' ||
		evidence.configurationRecipes !== 'DOCUMENTED_NOT_CERTIFIED'
	) {
		throw new Error('compatibility-support.json release evidence does not match generated truth')
	}
	return truth
}
