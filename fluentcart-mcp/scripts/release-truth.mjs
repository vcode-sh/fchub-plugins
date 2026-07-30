import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
export const RELEASE_STATE_PATH = join(
	PACKAGE_ROOT,
	'tests/fixtures/releases/previous-release-state.json',
)
const SEMVER = /^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/
const DIGEST = /^sha256:[a-f0-9]{64}$/
const REGISTRIES = new Set(['ghcr.io', 'docker.io'])

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

export function buildReleaseTruth(pkg, releaseState = null) {
	const state = validateReleaseState(
		releaseState ?? JSON.parse(readFileSync(RELEASE_STATE_PATH, 'utf8')),
		pkg.version,
	)
	const truth = {
		version: pkg.version,
		protocols: ['2025-11-25', '2026-07-28'],
		httpProfiles: ['local', 'private'],
		sdk: {
			server: pkg.dependencies['@modelcontextprotocol/server'],
			node: pkg.dependencies['@modelcontextprotocol/node'],
			express: pkg.dependencies['@modelcontextprotocol/express'],
		},
		conformance: {
			package: pkg.devDependencies['@modelcontextprotocol/conformance'],
			expectedFailures: [],
		},
		promotion: {
			npmPublishing: 'trusted-staged',
			npmStageTag: 'latest',
			npmApproval: 'interactive-2fa',
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
