// Lane registry and step execution for the acceptance harness.
//
// Every lane in the release programme is named here from day one. Lanes whose underlying commands
// do not exist yet report BLOCKED and name the exact missing prerequisite — never PASS, and never a
// warning. An unbuilt gate is an ungated release, and saying so plainly is the point of this file.

import { worstStatus } from './test-report.mjs'

// Re-exported so callers have one import for the whole harness surface.
export { resolveStep, runStep } from './steps.mjs'

const npmStep = (id, s, extra = {}) => ({
	id, kind: 'npm', args: ['run', s], requiresScript: s, ...extra,
})
const nodeStep = (id, file, args = [], extra = {}) => ({ id, kind: 'node', file, args, ...extra })

// `reporter` names the machine-readable report a step can produce. Without one a step is judged on
// its exit code alone, which is fine for a build but never for a test runner: both runners exit 0
// when tests are skipped, so an exit code cannot tell a proof from an omission.
const testStep = (id, files, extra = {}) => ({
	id, kind: 'node-test', files, reporter: 'node-test', ...extra,
})
const liveStep = (id, testFile, extra = {}) =>
	npmStep(id, 'test:integration:local', {
		args: ['run', 'test:integration:local', '--', testFile],
		requiresFiles: [testFile],
		reporter: 'vitest',
		...extra,
	})

const GUARDED_WRITES = 'tests/integration/acceptance-guarded-writes.test.ts'
// The route index is public, so this lane needs a reachable store but no credential file, and an
// unreachable store is a failure naming its origin rather than a statically missing prerequisite.
const LIVE_TARGET = { acceptsFixture: true }
const DOCKER_IMAGE_ARGS = [
	'--context',
	'dist-packages/fluentcart-mcp-docker-context.tar.gz',
	'--checksums',
	'dist-packages/SHA256SUMS.json',
]

// Ordered exactly as plan 08 Task 1 Step 1 specifies. `npm ci` is opt-in: running it by default
// would delete dependencies that concurrent work is relying on, so it is skipped and recorded as
// unverified rather than silently assumed.
const DETERMINISTIC = [
	{
		id: 'install',
		kind: 'npm',
		args: ['ci'],
		optIn: 'install',
		skipNote: 'npm ci skipped by default because it removes node_modules; re-run with --install',
	},
	npmStep('typecheck', 'typecheck'),
	npmStep('lint', 'lint'),
	npmStep('unit-tests', 'test:unit', { reporter: 'vitest' }),
	// `npm run test:tooling` wraps `node --test <files>`, and node ignores reporter flags that
	// arrive after positional arguments, so this one is configured through NODE_OPTIONS instead.
	npmStep('tooling-tests', 'test:tooling', { reporter: 'node-test', reporterVia: 'env' }),
	npmStep('build', 'build'),
	npmStep('compatibility', 'check:compatibility'),
	nodeStep('api-coverage', 'scripts/build-api-coverage.mjs', ['--check']),
	npmStep('context-measurement', 'measure:context'),
	nodeStep('release-contract', 'scripts/build-release-contract.mjs', ['--check']),
	nodeStep('package-manifest', 'scripts/build-manifest.mjs', ['--check']),
]

export const LANES = {
	deterministic: {
		description: 'Source, build, coverage, context and package contracts from a clean tree',
		steps: DETERMINISTIC,
	},
	'route-drift': {
		description: 'Live REST route set against the checked fixture, with added/removed pairs',
		// Drift needs a running store, and `.env.test.local` is the one file this project reads to
		// find one. Without it the lane is blocked, never quietly passed.
		steps: [testStep('route-drift', ['tests/acceptance/route-drift.test.mjs'], LIVE_TARGET)],
	},
	capabilities: {
		description: 'Per-profile public tool names and scoped source cleanliness',
		steps: [
			testStep('capability-matrix', ['tests/acceptance/capability-matrix.test.mjs']),
			testStep('source-scope', ['tests/acceptance/source-scope.test.mjs']),
		],
	},
	transport: {
		description: 'Startup, bearer, session and shutdown boundaries against the built server',
		steps: [testStep('transport', ['tests/acceptance/transport.test.mjs'])],
	},
	tokens: {
		description: 'Built wire definition sizes and progressive-disclosure budgets',
		steps: [testStep('token-budget', ['tests/acceptance/token-budget.test.mjs'])],
	},
	'dynamic-live': {
		description: 'Live dynamic search, describe and bounded read payload sizes',
		steps: [liveStep('dynamic-live', 'tests/integration/acceptance-dynamic-live.test.ts')],
	},
	'code-live': {
		description: 'QuickJS isolation attack matrix plus one bounded live read composition',
		steps: [
			testStep('code-sandbox', ['tests/acceptance/code-sandbox.test.mjs']),
			liveStep('code-composition', 'tests/integration/acceptance-code-live.test.ts'),
		],
	},
	'readonly-live': {
		description: 'Read-only live behaviour against run-owned data only',
		steps: [liveStep('readonly-live', 'tests/integration/acceptance-readonly.test.ts')],
	},
	'reversible-live': {
		description: 'Reversible lifecycles with independently verified cleanup',
		steps: [liveStep('reversible-live', 'tests/integration/acceptance-reversible.test.ts')],
	},
	'guarded-preview': {
		description: 'Signed, state-pinned, mutation-free refund and cancellation previews',
		steps: [
			liveStep('guarded-preview', GUARDED_WRITES, {
				env: { FLUENTCART_ACCEPTANCE_GUARD_PHASE: 'preview' },
				// Plan 08 Task 7: a documented guarded capability requires this lane to PASS. Skip the
				// preview and the capability is unproven, however many neighbouring tests are green.
				proves: ['previews a guarded refund'],
			}),
		],
	},
	'guarded-execute-test': {
		description: 'Durable claim behaviour and test-mode-only guarded execution',
		steps: [
			testStep('guard-state', ['tests/acceptance/guard-state.test.mjs']),
			liveStep('guarded-execute', GUARDED_WRITES, {
				env: { FLUENTCART_ACCEPTANCE_GUARD_PHASE: 'execute' },
				proves: [
					'executes one approved test-mode refund',
					'executes one approved test-mode cancellation',
				],
			}),
		],
	},
	archives: {
		description: 'One checksum-bound release build, inspected and smoked without rebuilding',
		steps: [
			npmStep('pack-release', 'pack:release'),
			nodeStep('inspect-npm-pack', 'scripts/inspect-npm-pack.mjs', [], {
				dynamicArgument: { dir: 'dist-packages', prefix: 'fluentcart-mcp-', suffix: '.tgz' },
			}),
			nodeStep('inspect-mcpb', 'scripts/inspect-mcpb.mjs', ['dist-packages/fluentcart-mcp.mcpb'], {
				requiresFiles: ['dist-packages/fluentcart-mcp.mcpb'],
			}),
			nodeStep('docker-image', 'scripts/build-validated-docker-image.mjs', DOCKER_IMAGE_ARGS, {
				requiresFiles: ['dist-packages/SHA256SUMS.json'],
				acceptsSourceSha: true,
			}),
			testStep('docker-smoke', ['tests/acceptance/docker.test.mjs']),
		],
	},
	docs: {
		description: 'Current-facing documentation claims against the same release contract',
		steps: [
			nodeStep('docs-claims', '../scripts/check-mcp-docs.mjs'),
			npmStep('web-docs-lint', 'lint', { cwd: '../web-docs', requiresFiles: ['../web-docs/package.json'] }),
			npmStep('web-docs-build', 'build', { cwd: '../web-docs', requiresFiles: ['../web-docs/package.json'] }),
		],
	},
}

export const LANE_NAMES = Object.keys(LANES)
export const ALL_LANE_NAMES = [...LANE_NAMES, 'all']

export function expandLane(name) {
	if (name === 'all') return LANE_NAMES
	if (Object.hasOwn(LANES, name)) return [name]
	throw new Error(`unknown lane "${name}"; expected one of: ${ALL_LANE_NAMES.join(', ')}`)
}

/** A lane is only as strong as its weakest step; a failure never becomes a warning. */
export function aggregate(steps) {
	if (steps.length === 0) return 'BLOCKED'
	const statuses = steps.map((step) => step.status)
	if (statuses.includes('FAIL')) return 'FAIL'
	if (statuses.includes('BLOCKED')) return 'BLOCKED'
	// A lane that only ever skipped proved nothing, so it cannot claim a pass.
	if (!statuses.some((status) => status === 'PASS' || status === 'PASS_WITH_EXCLUSIONS')) {
		return 'BLOCKED'
	}
	return worstStatus(statuses.filter((status) => status !== 'SKIPPED'))
}

/** Every skipped test a lane's steps recorded, with the reason its author wrote down. */
export function unprovenOf(steps) {
	return steps.flatMap((step) =>
		(step.unproven ?? []).map((entry) => ({ step: step.id, ...entry })),
	)
}
