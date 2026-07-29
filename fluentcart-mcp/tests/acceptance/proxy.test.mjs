import assert from 'node:assert/strict'
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, it } from 'node:test'
import { writeJsonAtomic } from '../../scripts/acceptance/evidence-writer.mjs'
import { LANES } from '../../scripts/acceptance/lanes.mjs'
import { modernProxyRequest } from '../../scripts/proxy-candidate-smoke.mjs'
import {
	assessCandidateProxyResult,
	PROXY_IMAGE,
	runProxySmoke,
	verifyCandidateImageIdentity,
	verifyCandidateProxy,
} from '../../scripts/proxy-smoke.mjs'
import { expectedReleaseIdentity } from '../../scripts/release-identity.mjs'
import { CandidateStore } from '../fixtures/proxy/candidate-store.mjs'

const REQUIRED_FILES = [
	'tests/fixtures/proxy/docker-compose.yml',
	'tests/fixtures/proxy/nginx.conf',
	'scripts/proxy-smoke.mjs',
]
const SHA = '0123456789abcdef0123456789abcdef01234567'
const IMAGE_ID = `sha256:${'a'.repeat(64)}`
const IMAGE_DIGEST = `sha256:${'b'.repeat(64)}`
const CONTENT_DIGEST = `sha256:${'c'.repeat(64)}`
const PROXY_CLIENT_INFO = { name: 'proxy-candidate-smoke', version: '1.0.0' }
const PROXY_MODERN_META = {
	'io.modelcontextprotocol/protocolVersion': '2026-07-28',
	'io.modelcontextprotocol/clientInfo': PROXY_CLIENT_INFO,
	'io.modelcontextprotocol/clientCapabilities': {},
}
const PROXY_TOOL_PARAMS = {
	name: 'fluentcart_execute_read_tool',
	arguments: {
		tool_name: 'fluentcart_order_list',
		input: { page: 1, per_page: 1 },
	},
}

function candidateIdentity(overrides = {}) {
	return {
		imageId: IMAGE_ID,
		imageDigest: IMAGE_DIGEST,
		candidateContentDigest: CONTENT_DIGEST,
		sourceSha: SHA,
		...overrides,
	}
}

function inspectedCandidate(overrides = {}) {
	return {
		Id: IMAGE_ID,
		RepoDigests: [`registry.invalid/fluentcart-mcp@${IMAGE_DIGEST}`],
		Config: {
			Labels: {
				'org.opencontainers.image.revision': SHA,
				'sh.vcode.fluentcart-mcp.candidate-content-digest': CONTENT_DIGEST,
			},
		},
		...overrides,
	}
}

function completeCandidateResult() {
	const observations = Object.fromEntries(
		[
			'tls',
			'forwarding',
			'streaming',
			'cancellation',
			'reconnect',
			'oversizedBody',
			'rateLimit',
			'connectionLimit',
		].map((behaviour) => [behaviour, { candidateImageId: IMAGE_ID, passed: true }]),
	)
	return { candidateBacked: true, identity: candidateIdentity(), observations }
}

function prepareSyntheticBackend(directory) {
	const config = join(directory, 'backend.conf')
	const body = join(directory, 'stream.bin')
	const override = join(directory, 'compose.override.json')
	writeFileSync(
		config,
		`worker_processes 1;
events { worker_connections 64; }
http {
  log_format fixture '$uri $status $request_time';
  access_log /dev/stdout fixture;
  server {
    listen 3000;
    location = /health { default_type application/json; return 200 '{"status":"ok"}'; }
    location = /mcp/headers {
      default_type text/plain;
      return 200 "$http_authorization|$host|$http_origin|$http_x_forwarded_proto";
    }
    location = /mcp/ping { return 204; }
    location = /mcp/stream { alias /srv/stream.bin; limit_rate 32768; }
    location = /mcp/hold { alias /srv/stream.bin; limit_rate 1024; }
  }
}
`,
	)
	writeFileSync(body, Buffer.alloc(512 * 1024, 'x'))
	writeFileSync(
		override,
		JSON.stringify({
			services: {
				backend: {
					image: PROXY_IMAGE,
					command: ['nginx', '-g', 'daemon off;', '-c', '/etc/nginx/nginx.conf'],
					volumes: [
						{ type: 'bind', source: config, target: '/etc/nginx/nginx.conf', read_only: true },
						{ type: 'bind', source: body, target: '/srv/stream.bin', read_only: true },
					],
				},
			},
		}),
	)
	return override
}

const runDirectory = process.env.FLUENTCART_ACCEPTANCE_RUN_DIR
const candidateImage = process.env.FLUENTCART_ACCEPTANCE_IMAGE
const currentIdentity = expectedReleaseIdentity()
const releaseIdentity = {
	imageId: process.env.FLUENTCART_ACCEPTANCE_IMAGE_ID,
	imageDigest: process.env.FLUENTCART_ACCEPTANCE_IMAGE_DIGEST,
	candidateContentDigest: currentIdentity.candidateContentDigest,
	sourceSha: currentIdentity.sourceSha,
}
const candidatePrerequisites = Object.entries({
	runDirectory,
	candidateImage,
	imageId: releaseIdentity.imageId,
	imageDigest: releaseIdentity.imageDigest,
	candidateContentDigest: releaseIdentity.candidateContentDigest,
})
	.filter(([, value]) => !value)
	.map(([name]) => name)
const candidateReady = candidatePrerequisites.length === 0
const shouldExercise = process.env.NODE_ENV === 'test' || candidateReady
const proxyResult = shouldExercise
	? await runProxySmoke({ prepareFixture: prepareSyntheticBackend })
	: null
let candidateResult = null
if (candidateReady) {
	const store = new CandidateStore()
	await store.start()
	try {
		candidateResult = await verifyCandidateProxy({
			image: candidateImage,
			expectedIdentity: releaseIdentity,
			fixture: store,
		})
		writeJsonAtomic(join(runDirectory, 'proxy.json'), {
			schemaVersion: 2,
			...candidateResult,
		})
	} finally {
		await store.close()
	}
}
const candidateAssessment = candidateResult
	? assessCandidateProxyResult(candidateResult, releaseIdentity)
	: { status: 'BLOCKED', missing: candidatePrerequisites }

describe('private proxy acceptance lane', () => {
	it('declares one mandatory safe proxy proof without weakening the protocol lane', () => {
		assert.deepEqual(
			LANES.proxy.steps.map(({ id }) => id),
			['candidate-preflight', 'proxy-smoke'],
		)
		assert.equal(LANES.proxy.steps[1].reporter, 'node-test')
		assert.deepEqual(LANES.proxy.steps[1].requiresFiles, REQUIRED_FILES)
		assert.ok(LANES.proxy.steps[1].proves.includes('certifies actual candidate proxy behaviours'))
		assert.equal(LANES.protocol.steps.length, 3)
	})
})

describe('candidate proxy release proof', () => {
	it('uses server/discover with the exact modern metadata envelope', () => {
		const discovery = modernProxyRequest(1, 'server/discover')
		assert.deepEqual(JSON.parse(discovery.body), {
			jsonrpc: '2.0',
			id: 1,
			method: 'server/discover',
			params: { _meta: PROXY_MODERN_META },
		})
	})

	it('sets the modern protocol and method headers for discovery', () => {
		const discovery = modernProxyRequest(1, 'server/discover')
		assert.deepEqual(discovery.headers, {
			Accept: 'application/json, text/event-stream',
			'Content-Type': 'application/json',
			'Mcp-Protocol-Version': '2026-07-28',
			'Mcp-Method': 'server/discover',
		})
	})

	it('uses tools/call with the same required modern metadata', () => {
		const tool = modernProxyRequest(2, 'tools/call', PROXY_TOOL_PARAMS)
		assert.deepEqual(JSON.parse(tool.body), {
			jsonrpc: '2.0',
			id: 2,
			method: 'tools/call',
			params: { _meta: PROXY_MODERN_META, ...PROXY_TOOL_PARAMS },
		})
	})

	it('sets both method and tool-name headers for a modern tool call', () => {
		const tool = modernProxyRequest(2, 'tools/call', PROXY_TOOL_PARAMS)
		assert.equal(tool.headers['Mcp-Method'], 'tools/call')
		assert.equal(tool.headers['Mcp-Name'], 'fluentcart_execute_read_tool')
	})

	it('never disguises a legacy initialize request with a modern header', () => {
		const body = JSON.parse(modernProxyRequest(1, 'server/discover').body)
		assert.equal(body.method, 'server/discover')
		assert.equal(Object.hasOwn(body.params, 'protocolVersion'), false)
		assert.equal(Object.hasOwn(body.params, 'clientInfo'), false)
		assert.equal(Object.hasOwn(body.params, 'capabilities'), false)
	})

	it('returns fresh headers so negative forwarding probes cannot corrupt the valid request', () => {
		const first = modernProxyRequest(1, 'server/discover')
		first.headers.Authorization = 'Bearer deliberately-wrong'
		const second = modernProxyRequest(1, 'server/discover')
		assert.notStrictEqual(first.headers, second.headers)
		assert.equal(second.headers.Authorization, undefined)
		assert.deepEqual(JSON.parse(first.body), JSON.parse(second.body))
	})

	it('rejects a candidate with the expected source labels but the wrong image digest', () => {
		assert.throws(
			() =>
				verifyCandidateImageIdentity(
					inspectedCandidate(),
					candidateIdentity({ imageDigest: `sha256:${'d'.repeat(64)}` }),
				),
			/image digest/,
		)
	})

	it('keeps synthetic or incomplete observations BLOCKED', () => {
		const synthetic = { ...completeCandidateResult(), candidateBacked: false }
		assert.equal(assessCandidateProxyResult(synthetic, candidateIdentity()).status, 'BLOCKED')

		const incomplete = completeCandidateResult()
		incomplete.observations.connectionLimit = undefined
		assert.deepEqual(assessCandidateProxyResult(incomplete, candidateIdentity()), {
			status: 'BLOCKED',
			missing: ['connectionLimit'],
		})
	})

	it('requires every mandatory observation to name the actual candidate image', () => {
		const result = completeCandidateResult()
		result.observations.streaming.candidateImageId = `sha256:${'e'.repeat(64)}`
		assert.deepEqual(assessCandidateProxyResult(result, candidateIdentity()), {
			status: 'BLOCKED',
			missing: ['streaming'],
		})
	})
})

if (proxyResult)
	describe('digest-pinned nginx proxy', () => {
		const result = proxyResult

		it('terminates verified TLS with its certificate outside tracked source', () => {
			assert.equal(result.proxyImage, PROXY_IMAGE)
			assert.equal(result.tlsVerified, true)
			assert.equal(result.certificateInTrackedSource, false)
		})

		it('waits for the exact proxied candidate health endpoint before testing behaviour', () => {
			const nginx = readFileSync('tests/fixtures/proxy/nginx.conf', 'utf8')
			assert.match(nginx, /location = \/health \{[\s\S]*proxy_pass http:\/\/fluentcart_mcp;/)
			assert.deepEqual(result.readiness, { path: '/health', status: 200 })
		})

		it('keeps the explicitly private backend off every host port', () => {
			assert.equal(result.backendPrivateProfileConfigured, true)
			assert.equal(result.backendHostPublished, false)
		})

		it('forwards the allowed Host, Origin, and bearer exactly', () => {
			assert.deepEqual(result.forwarded, {
				authorization: true,
				host: true,
				origin: true,
				proto: true,
			})
		})

		it('streams before completion, propagates cancellation, and reconnects', () => {
			assert.equal(result.streaming.firstChunkBeforeCompletion, true)
			assert.equal(result.streaming.cancelledUpstream, true)
			assert.equal(result.streaming.reconnected, true)
		})

		it('exercises proxy limits instead of merely declaring them', () => {
			assert.equal(result.limits.oversizedStatus, 413)
			assert.equal(result.limits.rateRejected, true)
			assert.equal(result.limits.connectionRejected, true)
		})
	})

if (candidateAssessment.status !== 'PASS') {
	const reason = candidateAssessment.missing.join(', ') || 'candidate observations unavailable'
	it(`certifies actual candidate proxy behaviours (BLOCKED: ${reason})`, { skip: true }, () => {
		// Synthetic observations are deliberately incapable of satisfying this proof.
	})
} else {
	it('certifies actual candidate proxy behaviours', () => {
		assert.equal(candidateResult.candidateBacked, true)
		assert.deepEqual(candidateResult.identity, releaseIdentity)
		assert.deepEqual(candidateAssessment, { status: 'PASS', missing: [] })
	})
}
