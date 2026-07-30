import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { resolve } from 'node:path'
import { Client, StreamableHTTPClientTransport } from '@modelcontextprotocol/client'
import { writeJsonAtomic } from './acceptance/evidence-writer.mjs'
import { verifyCandidateImageIdentity } from './proxy-candidate-contract.mjs'
import { sampleContainerResources } from './soak-resource-sampler.mjs'
import { runSoak } from './soak-runner.mjs'
import { MODERN_PROTOCOL } from './protocol-wire.mjs'

function verifyIdentity({ expected, imageInspect, containerInspect }) {
	const identity = verifyCandidateImageIdentity(imageInspect, expected)
	assert.equal(
		containerInspect?.Image,
		identity.imageId,
		'running container image differs from the inspected candidate',
	)
	return identity
}

export async function createMcpConnection(
	url,
	apiKey,
	host,
	{ ClientClass = Client, TransportClass = StreamableHTTPClientTransport } = {},
) {
	const client = new ClientClass(
		{ name: 'fluentcart-release-soak', version: '1.0.0' },
		{
			capabilities: {},
			supportedProtocolVersions: [MODERN_PROTOCOL],
			versionNegotiation: { mode: { pin: MODERN_PROTOCOL } },
		},
	)
	const transport = new TransportClass(new URL(url), {
		authProvider: { token: async () => apiKey },
		requestInit: { headers: { Host: host, Origin: `https://${host}` } },
	})
	try {
		await client.connect(transport)
		assert.equal(
			client.getNegotiatedProtocolVersion(),
			MODERN_PROTOCOL,
			`candidate soak negotiated ${client.getNegotiatedProtocolVersion()} instead of ${MODERN_PROTOCOL}`,
		)
	} catch (error) {
		await client.close()
		throw error
	}
	return {
		close: () => client.close(),
		read: async () => {
			const result = await client.callTool({
				name: 'fluentcart_execute_read_tool',
				arguments: {
					tool_name: 'fluentcart_order_list',
					input: { page: 1, per_page: 1 },
				},
			})
			if (result.isError) throw new Error('read tool returned an error')
		},
	}
}

function dockerInspect(target) {
	const result = spawnSync('docker', ['inspect', target], {
		encoding: 'utf8',
		timeout: 10_000,
	})
	if (result.status !== 0) throw new Error(`candidate inspection failed for ${target}`)
	return JSON.parse(result.stdout)[0]
}

export async function runSoakWorker(policy) {
	const resultPath = process.env.FLUENTCART_SOAK_RESULT_PATH
	const image = process.env.FLUENTCART_SOAK_IMAGE
	const container = process.env.FLUENTCART_SOAK_CONTAINER
	const url = process.env.FLUENTCART_SOAK_URL
	const apiKey = process.env.FLUENTCART_SOAK_API_KEY
	const caPath = process.env.FLUENTCART_SOAK_CA_PATH
	const host = process.env.FLUENTCART_SOAK_HOST
	const expected = JSON.parse(process.env.FLUENTCART_SOAK_EXPECTED_IDENTITY ?? 'null')
	for (const [name, value] of Object.entries({
		resultPath,
		image,
		container,
		url,
		apiKey,
		caPath,
		host,
		expected,
	})) {
		if (!value) throw new Error(`managed soak worker requires ${name}`)
	}
	assert.equal(
		resolve(process.env.NODE_EXTRA_CA_CERTS ?? ''),
		resolve(caPath),
		'managed soak worker must start with its run-owned CA',
	)
	const candidate = verifyIdentity({
		expected,
		imageInspect: dockerInspect(image),
		containerInspect: dockerInspect(container),
	})
	const connection = await createMcpConnection(url, apiKey, host)
	let summary
	try {
		summary = await runSoak(policy, {
			read: connection.read,
			sampleResources: () => sampleContainerResources(container),
		})
	} finally {
		await connection.close()
	}
	writeJsonAtomic(resultPath, {
		candidate,
		stableDurationSeconds: policy.stableDurationMs / 1000,
		warmupSeconds: policy.warmupMs / 1000,
		totalRuntimeSeconds: policy.totalDurationMs / 1000,
		mode: policy.mode,
		...summary,
	})
	if (summary.outcome !== 'PASS') process.exitCode = 1
}
