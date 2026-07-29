import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import { LANES } from '../../scripts/acceptance/lanes.mjs'
import { runManagedSoakLifecycle } from '../../scripts/managed-soak-runtime.mjs'
import {
	MAX_P99_LATENCY_MS,
	resolveSoakPolicy,
	runSoak,
	summariseSoak,
	verifySoakCandidateIdentity,
} from '../../scripts/soak-http.mjs'

const MiB = 1024 * 1024
const IMAGE_A = `sha256:${'a'.repeat(64)}`
const IMAGE_B = `sha256:${'b'.repeat(64)}`
const DIGEST_A = `sha256:${'c'.repeat(64)}`
const CONTENT_A = `sha256:${'d'.repeat(64)}`
const SOURCE_SHA = '0123456789abcdef0123456789abcdef01234567'

function soakIdentityFixture(overrides = {}) {
	const expected = {
		imageId: IMAGE_A,
		imageDigest: DIGEST_A,
		candidateContentDigest: CONTENT_A,
		sourceSha: SOURCE_SHA,
	}
	return {
		expected,
		imageInspect: {
			Id: IMAGE_A,
			RepoDigests: [`registry.invalid/fluentcart-mcp@${DIGEST_A}`],
			Config: {
				Labels: {
					'org.opencontainers.image.revision': SOURCE_SHA,
					'sh.vcode.fluentcart-mcp.candidate-content-digest': CONTENT_A,
				},
			},
		},
		containerInspect: { Image: IMAGE_A },
		...overrides,
	}
}

describe('soak acceptance lane', () => {
	it('keeps policy proof separate from the release-duration execution', () => {
		assert.deepEqual(
			LANES.soak.steps.map(({ id }) => id),
			['candidate-preflight', 'soak-policy', 'soak-http'],
		)
		assert.equal(LANES.soak.steps[1].reporter, 'node-test')
		assert.equal(LANES.soak.steps[2].acceptsSourceSha, true)
	})
})

describe('release soak policy', () => {
	it('binds the run-managed sampled container to the inspected candidate', () => {
		assert.deepEqual(verifySoakCandidateIdentity(soakIdentityFixture()), {
			imageId: IMAGE_A,
			imageDigest: DIGEST_A,
			candidateContentDigest: CONTENT_A,
			sourceSha: SOURCE_SHA,
		})
		assert.throws(
			() =>
				verifySoakCandidateIdentity(soakIdentityFixture({ containerInspect: { Image: IMAGE_B } })),
			/running container image/,
		)
	})

	it('does not require evidence from a prior proxy lane', () => {
		const fixture = soakIdentityFixture()
		assert.equal(Object.hasOwn(fixture, 'proxyEvidence'), false)
		assert.equal(verifySoakCandidateIdentity(fixture).imageId, IMAGE_A)
	})

	it('refuses a short release duration or warm-up', () => {
		assert.throws(
			() =>
				resolveSoakPolicy({
					nodeEnv: 'production',
					durationSeconds: 299,
					warmupSeconds: 60,
					intervalMs: 1000,
					mode: 'dynamic',
				}),
			/requires at least 300 stable seconds/,
		)
		assert.throws(
			() =>
				resolveSoakPolicy({
					nodeEnv: 'production',
					durationSeconds: 300,
					warmupSeconds: 59,
					intervalMs: 1000,
					mode: 'dynamic',
				}),
			/requires at least 60 seconds/,
		)
	})

	it('keeps the release stability check to five measured minutes after a one-minute warm-up', () => {
		const policy = resolveSoakPolicy({
			nodeEnv: 'production',
			durationSeconds: 300,
			warmupSeconds: 60,
			intervalMs: 1000,
			mode: 'dynamic',
		})
		assert.equal(policy.stableDurationMs, 300_000)
		assert.equal(policy.totalDurationMs, 360_000)
	})

	it('permits a short duration only under NODE_ENV=test', () => {
		const policy = resolveSoakPolicy({
			nodeEnv: 'test',
			durationSeconds: 0.2,
			warmupSeconds: 0.05,
			intervalMs: 10,
			mode: 'dynamic',
		})
		assert.equal(policy.stableDurationMs, 200)
		assert.equal(policy.warmupMs, 50)
		assert.equal(policy.totalDurationMs, 250)
		assert.equal(Object.hasOwn(policy, 'resourceIntervalMs'), false)
		assert.equal(Object.hasOwn(policy, 'finalWindowMs'), false)
	})

	it('uses one explicit p99 ceiling for every presentation mode', () => {
		assert.deepEqual(MAX_P99_LATENCY_MS, {
			dynamic: 250,
			curated: 250,
			code: 250,
			full: 250,
		})
	})

	it('enforces exact error, descriptor, RSS, and measured tail thresholds', () => {
		const summary = summariseSoak({
			mode: 'dynamic',
			latenciesMs: [10, 10, 10, 10, 20],
			warmResources: [
				{ rssBytes: 90 * MiB, openFds: 9 },
				{ rssBytes: 100 * MiB, openFds: 10 },
				{ rssBytes: 110 * MiB, openFds: 11 },
			],
			finalResources: [
				{ rssBytes: 150 * MiB, openFds: 14 },
				{ rssBytes: 160 * MiB, openFds: 15 },
				{ rssBytes: 160 * MiB, openFds: 15 },
			],
			unexpectedErrors: 0,
			unhandledRejections: 0,
		})
		assert.deepEqual(summary.thresholds, {
			unexpectedErrors: 0,
			unhandledRejections: 0,
			maxFdGrowth: 5,
			maxRssGrowthBytes: 64 * MiB,
			maxP99LatencyMs: 250,
			maxAbsoluteLatencyMs: 1000,
		})
		assert.equal(summary.resources.fdGrowth, 5)
		assert.equal(summary.resources.rssGrowthBytes, 60 * MiB)
		assert.equal(summary.outcome, 'PASS')
	})

	it('fails any threshold breach and persists aggregate shapes only', () => {
		const summary = summariseSoak({
			mode: 'code',
			latenciesMs: [...Array(19).fill(10), 200],
			warmResources: [{ rssBytes: 512 * MiB, openFds: 10 }],
			finalResources: [{ rssBytes: 700 * MiB, openFds: 16 }],
			unexpectedErrors: 1,
			unhandledRejections: 1,
		})
		assert.equal(summary.thresholds.maxRssGrowthBytes, 128 * MiB)
		assert.equal(summary.outcome, 'FAIL')
		assert.deepEqual(
			summary.failures.sort(),
			[
				'file descriptor growth 6 exceeded 5',
				'final snapshot rss growth 197132288 bytes exceeded 134217728 bytes',
				'unexpected errors 1 exceeded 0',
				'unhandled rejections 1 exceeded 0',
			].sort(),
		)
		const serialised = JSON.stringify(summary)
		assert.doesNotMatch(serialised, /payload|content|customer|authorization/i)
	})

	it('uses the final descriptor snapshot for persistent growth', () => {
		const summary = summariseSoak({
			mode: 'dynamic',
			latenciesMs: [10, 10, 10],
			warmResources: [
				{ rssBytes: 100 * MiB, openFds: 10 },
				{ rssBytes: 100 * MiB, openFds: 10 },
			],
			finalResources: [
				{ rssBytes: 100 * MiB, openFds: 10 },
				{ rssBytes: 100 * MiB, openFds: 10 },
				{ rssBytes: 100 * MiB, openFds: 16 },
			],
			unexpectedErrors: 0,
			unhandledRejections: 0,
		})
		assert.equal(summary.resources.finalSnapshotOpenFds, 16)
		assert.equal(summary.outcome, 'FAIL')
		assert.ok(summary.failures.includes('file descriptor growth 6 exceeded 5'))
	})

	it('fails when only 240 of the required 300 stable seconds completed', () => {
		const summary = summariseSoak({
			mode: 'dynamic',
			latenciesMs: [10, 10, 10],
			warmResources: [{ rssBytes: 100 * MiB, openFds: 10 }],
			finalResources: [{ rssBytes: 100 * MiB, openFds: 10 }],
			unexpectedErrors: 0,
			unhandledRejections: 0,
			requiredStableDurationMs: 300_000,
			stableElapsedMs: 240_000,
		})
		assert.equal(summary.outcome, 'FAIL')
		assert.ok(
			summary.failures.includes('stable measurement 240000 ms did not reach required 300000 ms'),
		)
	})

	it('fails persistent RSS growth in the final snapshot', () => {
		const summary = summariseSoak({
			mode: 'dynamic',
			latenciesMs: [10, 10, 10],
			warmResources: [{ rssBytes: 100 * MiB, openFds: 10 }],
			finalResources: [{ rssBytes: 300 * MiB, openFds: 10 }],
			unexpectedErrors: 0,
			unhandledRejections: 0,
		})
		assert.equal(summary.outcome, 'FAIL')
		assert.ok(summary.failures.some((failure) => failure.startsWith('final snapshot rss')))
	})
})

describe('short test soak', () => {
	const candidateEnvironment = [
		'FLUENTCART_ACCEPTANCE_IMAGE',
		'FLUENTCART_ACCEPTANCE_IMAGE_ID',
		'FLUENTCART_ACCEPTANCE_IMAGE_DIGEST',
	]
	const missingCandidate = candidateEnvironment.filter((name) => !process.env[name])

	it('starts the actual candidate, reads and samples it through run-owned TLS, then removes it', {
		skip: missingCandidate.length > 0 && `BLOCKED: ${missingCandidate.join(', ')}`,
	}, () => {
		const runDirectory = mkdtempSync(join(tmpdir(), 'fcmcp-managed-soak-test-'))
		try {
			const child = spawnSync(
				process.execPath,
				[
					fileURLToPath(new URL('../../scripts/soak-http.mjs', import.meta.url)),
					'--source-sha',
					SOURCE_SHA,
					'--duration',
					'0.2',
					'--warmup',
					'0.05',
					'--interval-ms',
					'100',
				],
				{
					encoding: 'utf8',
					timeout: 60_000,
					env: {
						...process.env,
						NODE_ENV: 'test',
						FLUENTCART_ACCEPTANCE_RUN_DIR: runDirectory,
					},
				},
			)
			assert.equal(child.status, 0, child.stderr)
			const evidence = JSON.parse(readFileSync(join(runDirectory, 'soak.json'), 'utf8'))
			assert.equal(evidence.outcome, 'PASS')
			assert.equal(evidence.runtime.ownership, 'run-managed')
			assert.equal(evidence.runtime.tls, true)
			assert.ok(evidence.requests.succeeded > 0)
			assert.equal(evidence.candidate.imageId, process.env.FLUENTCART_ACCEPTANCE_IMAGE_ID)
			assert.notEqual(
				spawnSync('docker', ['inspect', evidence.candidateContainer]).status,
				0,
				'the sampled candidate container must be removed',
			)
		} finally {
			rmSync(runDirectory, { recursive: true, force: true })
		}
	})

	it('owns startup, same-identity reads and sampling, and cleanup for the whole lifecycle', async () => {
		const policy = resolveSoakPolicy({
			nodeEnv: 'test',
			durationSeconds: 0.2,
			warmupSeconds: 0.05,
			intervalMs: 5,
			mode: 'dynamic',
		})
		const events = []
		let active = false
		const result = await runManagedSoakLifecycle({
			startRuntime: async () => {
				active = true
				events.push('startup')
				return {
					descriptor: {
						...soakIdentityFixture(),
						containerId: 'run-owned-candidate-container',
						read: async () => {
							assert.equal(active, true)
							events.push('read')
						},
						sampleResources: async (containerId) => {
							assert.equal(active, true)
							assert.equal(containerId, 'run-owned-candidate-container')
							events.push('sample')
							return { rssBytes: 100 * MiB, openFds: 10 }
						},
					},
					close: async () => {
						events.push('cleanup')
						active = false
					},
				}
			},
			execute: async (descriptor) => {
				assert.equal(
					verifySoakCandidateIdentity(descriptor).imageId,
					descriptor.containerInspect.Image,
				)
				return runSoak(policy, {
					read: descriptor.read,
					sampleResources: () => descriptor.sampleResources(descriptor.containerId),
				})
			},
		})
		assert.equal(result.outcome, 'PASS')
		assert.ok(events.includes('read'))
		assert.ok(events.includes('sample'))
		assert.deepEqual(events.slice(0, 1), ['startup'])
		assert.deepEqual(events.slice(-1), ['cleanup'])
		assert.equal(active, false)
	})

	it('cleans the managed candidate runtime when soak execution fails', async () => {
		let cleaned = false
		await assert.rejects(
			runManagedSoakLifecycle({
				startRuntime: async () => ({
					descriptor: soakIdentityFixture(),
					close: async () => {
						cleaned = true
					},
				}),
				execute: async () => {
					throw new Error('synthetic soak failure')
				},
			}),
			/synthetic soak failure/,
		)
		assert.equal(cleaned, true)
	})

	it('runs read-only sampling under NODE_ENV=test without retaining results', async () => {
		const policy = resolveSoakPolicy({
			nodeEnv: 'test',
			durationSeconds: 0.2,
			warmupSeconds: 0.05,
			intervalMs: 10,
			mode: 'dynamic',
		})
		let reads = 0
		const summary = await runSoak(policy, {
			read: async () => {
				reads += 1
				return { discarded: `synthetic-payload-${reads}` }
			},
			sampleResources: async () => ({ rssBytes: 100 * MiB, openFds: 10 }),
		})
		assert.ok(reads >= 5)
		assert.equal(summary.outcome, 'PASS')
		assert.equal(summary.requests.total, reads)
		assert.doesNotMatch(JSON.stringify(summary), /synthetic-payload/)
	})

	it('counts a real unhandled rejection in a child process', () => {
		const moduleUrl = new URL('../../scripts/soak-http.mjs', import.meta.url).href
		const program = `
			import { resolveSoakPolicy, runSoak } from ${JSON.stringify(moduleUrl)}
			const policy = resolveSoakPolicy({
				nodeEnv: 'test', durationSeconds: 0.1, warmupSeconds: 0.02,
				intervalMs: 10, mode: 'dynamic'
			})
			let emitted = false
			const summary = await runSoak(policy, {
				read: async () => {
					if (!emitted) {
						emitted = true
						Promise.reject(new Error('actual unhandled rejection'))
					}
				},
				sampleResources: async () => ({ rssBytes: ${100 * MiB}, openFds: 10 })
			})
			process.stdout.write(JSON.stringify(summary))
		`
		const child = spawnSync(process.execPath, ['--input-type=module', '--eval', program], {
			encoding: 'utf8',
		})
		assert.equal(child.status, 0, child.stderr)
		const summary = JSON.parse(child.stdout)
		assert.equal(summary.unhandledRejections, 1)
		assert.equal(summary.outcome, 'FAIL')
	})

	it('removes its unhandled-rejection listener after success and failure', async () => {
		const before = process.listenerCount('unhandledRejection')
		const policy = resolveSoakPolicy({
			nodeEnv: 'test',
			durationSeconds: 0.05,
			warmupSeconds: 0.01,
			intervalMs: 5,
			mode: 'dynamic',
		})
		await runSoak(policy, {
			read: async () => undefined,
			sampleResources: async () => ({ rssBytes: 100 * MiB, openFds: 10 }),
		})
		assert.equal(process.listenerCount('unhandledRejection'), before)

		await assert.rejects(
			runSoak(policy, {
				read: async () => undefined,
				sampleResources: async () => {
					throw new Error('resource sampler failed')
				},
			}),
			/resource sampler failed/,
		)
		assert.equal(process.listenerCount('unhandledRejection'), before)
	})
})
