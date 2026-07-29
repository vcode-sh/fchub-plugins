import assert from 'node:assert/strict'
import { existsSync, lstatSync, realpathSync, statSync } from 'node:fs'
import { isAbsolute, join, relative } from 'node:path'
import { findSecrets } from './acceptance/evidence-writer.mjs'

export const CLIENT_CELLS = [
	{ client: 'MCP Inspector', transport: 'stdio' },
	{ client: 'MCP Inspector', transport: 'streamable-http' },
	{ client: 'Claude Code', transport: 'stdio' },
	{ client: 'Claude Code', transport: 'streamable-http' },
	{ client: 'Claude Desktop', transport: 'stdio' },
	{ client: 'Claude Desktop', transport: 'streamable-http' },
	{ client: 'Cursor', transport: 'stdio' },
	{ client: 'Cursor', transport: 'streamable-http' },
	{ client: 'Docker smoke', transport: 'docker-http' },
]

export const AUTOMATED_CLIENT_CELLS = CLIENT_CELLS.filter(
	({ client }) => !['Claude Desktop', 'Cursor'].includes(client),
)

export const CONFIGURATION_TARGETS = new Map([
	[
		'Claude Desktop:stdio',
		{
			capabilitySource: 'https://support.claude.com/en/articles/9797557-desktop-extensions',
			prerequisite: 'Install Claude Desktop and add the documented stdio configuration in the user profile.',
		},
	],
	[
		'Claude Desktop:streamable-http',
		{
			capabilitySource:
				'https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp',
			prerequisite:
				'Use a public HTTPS endpoint and configure the remote connector in the account-scoped Claude Desktop UI.',
		},
	],
	[
		'Cursor:stdio',
		{
			capabilitySource: 'https://docs.cursor.com/context/model-context-protocol',
			prerequisite: 'Install Cursor and add the documented stdio configuration in its MCP settings.',
		},
	],
	[
		'Cursor:streamable-http',
		{
			capabilitySource: 'https://docs.cursor.com/context/model-context-protocol',
			prerequisite: 'Use a reachable HTTP endpoint and add it in Cursor MCP settings.',
		},
	],
])

const cellKey = ({ client, transport }) => `${client}:${transport}`
export const isConfigurationTarget = (cell) => CONFIGURATION_TARGETS.has(cellKey(cell))
export const configurationTargetFor = (cell) => CONFIGURATION_TARGETS.get(cellKey(cell)) ?? null

export const VERSION_COMMANDS = {
	'MCP Inspector': ['npm', 'view', '@modelcontextprotocol/inspector@2.0.0', 'version'],
	'Claude Code': ['claude', '--version'],
	'Claude Desktop': [
		'/usr/bin/defaults',
		'read',
		'/Applications/Claude.app/Contents/Info',
		'CFBundleShortVersionString',
	],
	Cursor: ['cursor', '--version'],
	'Docker smoke': [
		'docker',
		'image',
		'inspect',
		'--format={{ index .Config.Labels "org.opencontainers.image.version" }}',
	],
}

const ERAS = new Set(['2025-11-25', '2026-07-28'])
const OUTCOMES = new Set(['PASS', 'UNSUPPORTED', 'BLOCKED', 'CONFIGURATION_TARGET'])
const ENTRY_KEYS = [
	'capabilitySource',
	'client',
	'configurationRoot',
	'evidenceTime',
	'observedHandshake',
	'outcome',
	'prerequisite',
	'reason',
	'transport',
	'version',
	'versionCommand',
]
const CANDIDATE_KEYS = [
	'baseCommitSha',
	'candidateContentDigest',
	'image',
	'imageDigest',
	'imageId',
	'sourceSha',
	'sourceShaKind',
]

function inside(parent, child) {
	const rel = relative(realpathSync(parent), realpathSync(child))
	return rel !== '' && !rel.startsWith('..') && !isAbsolute(rel)
}

function exactKeys(value, expected, label) {
	assert.deepEqual(Object.keys(value).sort(), expected, `${label} fields drifted`)
}

function time(value, label, now) {
	const parsed = new Date(value)
	assert.equal(parsed.toISOString(), value, `${label} must be an ISO timestamp`)
	assert.ok(parsed.getTime() <= new Date(now).getTime() + 5_000, `${label} is in the future`)
	return parsed.getTime()
}

function officialSource(client, value) {
	assert.match(value ?? '', /^https:\/\/[^ ]+$/, 'UNSUPPORTED requires an official URL')
	const source = new URL(value)
	if (client === 'MCP Inspector') {
		return (
			source.hostname === 'modelcontextprotocol.io' ||
			(source.hostname === 'github.com' &&
				source.pathname.startsWith('/modelcontextprotocol/inspector'))
		)
	}
	if (client === 'Claude Code' || client === 'Claude Desktop') {
		return (
			source.hostname === 'anthropic.com' ||
			source.hostname.endsWith('.anthropic.com') ||
			source.hostname === 'docs.claude.com' ||
			source.hostname === 'support.claude.com'
		)
	}
	return client === 'Cursor' && source.hostname === 'docs.cursor.com'
}

export function versionCommandFor(client, candidate) {
	const command = VERSION_COMMANDS[client]
	assert.ok(command, `unknown client ${client}`)
	return client === 'Docker smoke' ? [...command, candidate.image] : [...command]
}

function validateEntry(entry, evidence, context) {
	exactKeys(entry, ENTRY_KEYS, `${entry.client ?? 'client'} evidence`)
	assert.ok(OUTCOMES.has(entry.outcome), `unknown outcome ${entry.outcome}`)
	assert.deepEqual(
		entry.versionCommand,
		versionCommandFor(entry.client, evidence.candidate),
		'version command is not canonical',
	)
	assert.ok(existsSync(entry.configurationRoot), 'configuration root does not exist')
	assert.ok(statSync(entry.configurationRoot).isDirectory(), 'configuration root is not a directory')
	assert.equal(lstatSync(entry.configurationRoot).isSymbolicLink(), false, 'configuration root is a symlink')
	assert.ok(inside(evidence.runRoot, entry.configurationRoot), 'configuration root escaped the run root')
	time(entry.evidenceTime, 'evidence time', context.now)
	const current = context.currentVersions[entry.client] ?? null
	if (isConfigurationTarget(entry)) {
		assert.equal(entry.outcome, 'CONFIGURATION_TARGET', 'configuration target has the wrong outcome')
		assert.equal(entry.reason, null, 'configuration target must not claim an adapter failure')
		assert.equal(entry.observedHandshake, null, 'configuration target cannot claim a handshake')
		assert.ok(entry.prerequisite?.length > 0, 'configuration target requires a prerequisite')
		assert.ok(
			officialSource(entry.client, entry.capabilitySource),
			'configuration target requires an official capability source',
		)
		assert.equal(entry.version, current, 'configuration target version differs from current command output')
		return
	}
	assert.equal(entry.prerequisite, null, 'automated client cannot carry a configuration prerequisite')
	if (entry.outcome !== 'BLOCKED') {
		assert.equal(entry.version, current, 'version differs from current command output')
		assert.ok(entry.version, 'available client requires exact version output')
	} else if (entry.version !== null) {
		assert.equal(entry.version, current, 'blocked client version differs from current command output')
	}
	if (entry.client === 'MCP Inspector' && entry.outcome !== 'BLOCKED') {
		assert.equal(entry.version, '2.0.0')
	}
	if (entry.outcome === 'PASS') {
		assert.equal(entry.reason, null)
		assert.equal(entry.capabilitySource, null)
		assert.ok(ERAS.has(entry.observedHandshake?.protocolVersion), 'PASS requires observed era')
		time(entry.observedHandshake.observedAt, 'handshake time', context.now)
		return
	}
	assert.equal(entry.observedHandshake, null, `${entry.outcome} cannot claim a handshake`)
	assert.ok(entry.reason?.length > 0, `${entry.outcome} requires a reason`)
	if (entry.outcome === 'UNSUPPORTED') {
		assert.ok(
			officialSource(entry.client, entry.capabilitySource),
			'UNSUPPORTED requires an official capability source',
		)
	} else {
		assert.equal(entry.capabilitySource, null)
	}
}

export function validateClientEvidence(evidence, context) {
	exactKeys(
		evidence,
		['candidate', 'clients', 'producedAt', 'producer', 'runRoot', 'schemaVersion'],
		'named-client evidence',
	)
	assert.equal(evidence.schemaVersion, 3)
	assert.equal(evidence.producer, 'scripts/certify-clients.mjs')
	time(evidence.producedAt, 'producedAt', context.now)
	assert.equal(evidence.runRoot, join(context.runDirectory, 'client-config'))
	assert.ok(existsSync(evidence.runRoot), 'run root does not exist')
	exactKeys(evidence.candidate, CANDIDATE_KEYS, 'candidate identity')
	assert.deepEqual(evidence.candidate, context.candidate, 'candidate identity is not current')
	assert.equal(evidence.clients.length, 9, 'named-client evidence must contain exactly 9 cells')
	const actualCells = evidence.clients
		.map(({ client, transport }) => `${client}:${transport}`)
		.sort()
	const expectedCells = CLIENT_CELLS.map(({ client, transport }) => `${client}:${transport}`).sort()
	assert.deepEqual(actualCells, expectedCells, 'named-client cells differ from the required matrix')
	assert.deepEqual(findSecrets(evidence), [], 'named-client evidence carried a secret')
	for (const entry of evidence.clients) validateEntry(entry, evidence, context)
	return evidence
}

export function certificationState(evidence) {
	const automated = evidence.clients.filter((cell) => !isConfigurationTarget(cell))
	assert.equal(automated.length, AUTOMATED_CLIENT_CELLS.length, 'automated client cells differ')
	return automated.every(({ outcome }) => outcome === 'PASS') ? 'PASS' : 'BLOCKED'
}
