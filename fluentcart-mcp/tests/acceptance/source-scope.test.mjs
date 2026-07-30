// Scoped source cleanliness for the release programme.
//
// The scope is deliberately narrow, and cleanliness here never means "the tree is clean". The work
// is deliberately uncommitted, this repository is a monorepo other work touches constantly, and the
// owner's own tooling commits to `main` while acceptance runs, so asserting on an empty `git status`
// would be measuring the weather. What acceptance asserts instead is WHICH paths changed: only
// programme paths are touched, every deletion is one a plan explicitly mandates, the files the
// programme cannot run without are still present, and no part of this harness could run a command
// that tidies the evidence away.

import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const REPO_ROOT = resolve(PACKAGE_ROOT, '..')

/** Exactly the paths plan 08 names. No wildcard, no repository root, no "everything else". */
const PROGRAMME_PATHS = [
	'fluentcart-mcp',
	'web-docs/content/docs/fluentcart-mcp',
	'web-docs/lib/versions.json',
	'scripts/count-mcp-tools.mjs',
	'.github/workflows/mcp-ci.yml',
	'.github/workflows/mcp-release.yml',
	'.github/workflows/mcp-docker.yml',
	'.github/workflows/mcp-promote.yml',
]

/**
 * Deletions the programme explicitly mandates, each naming the plan and task that ordered it and
 * the file that took over the job.
 *
 * This allowlist is what separates a reviewed removal from a tidied tree. It is deliberately keyed
 * by exact path: a deletion nobody wrote down still fails, however plausible it looks.
 */
const SIMPLIFIED_PATHS = [
	'fluentcart-mcp/src/cli/guard-state.ts',
	'fluentcart-mcp/src/security/confirmation-token.ts',
	'fluentcart-mcp/src/security/guard-config.ts',
	'fluentcart-mcp/src/security/guarded-action.ts',
	'fluentcart-mcp/src/security/guarded-contract.ts',
	'fluentcart-mcp/src/security/idempotency-ledger.ts',
	'fluentcart-mcp/src/security/ledger-maintenance.ts',
	'fluentcart-mcp/src/security/ledger-records.ts',
	'fluentcart-mcp/src/security/ledger-store.ts',
	'fluentcart-mcp/src/tools/orders-refunds.ts',
	'fluentcart-mcp/src/tools/subscriptions-cancellation.ts',
	'fluentcart-mcp/tests/acceptance/guard-state.test.mjs',
	'fluentcart-mcp/tests/cli/guard-state.test.ts',
	'fluentcart-mcp/tests/fixtures/security/standalone-guard.json',
	'fluentcart-mcp/tests/integration/acceptance-guarded-writes.test.ts',
	'fluentcart-mcp/tests/integration/guarded-previews.test.ts',
	'fluentcart-mcp/tests/integration/support/guarded-payment-fixture.ts',
	'fluentcart-mcp/tests/security/confirmation-token.test.ts',
	'fluentcart-mcp/tests/security/guarded-action.test.ts',
	'fluentcart-mcp/tests/security/idempotency-ledger.test.ts',
	'fluentcart-mcp/tests/security/standalone-guard.test.ts',
	'fluentcart-mcp/tests/tools/orders-refunds.test.ts',
	'fluentcart-mcp/tests/tools/subscriptions-cancellation.test.ts',
]

const MANDATED_DELETIONS = [
	{
		path: 'fluentcart-mcp/test-tool.sh',
		mandate: 'plan 03 task 6 — replace the shell helper with the SDK v2 client helper',
		replacedBy: 'scripts/call-tool.mjs',
	},
	{
		path: 'fluentcart-mcp/scripts/generate-manifest-tools.ts',
		mandate: 'plan 07 task 2 — "Delete: fluentcart-mcp/scripts/generate-manifest-tools.ts"',
		replacedBy: 'scripts/build-manifest.mjs',
	},
	{
		path: 'fluentcart-mcp/.env.example',
		mandate: 'plan 08 task 8 — replace the unsafe generic live-test credential template',
		replacedBy: '.env.test.local.example',
	},
	{
		path: 'fluentcart-mcp/tests/fixtures/rest/fluentcart-1.5.5-core-pro-1.5.4-read-contracts.json',
		mandate:
			'plan 08 task 8 — stop claiming an all-active capture is exclusive Core plus Pro evidence',
		replacedBy: 'tests/fixtures/rest/fluentcart-1.5.5-all-active-read-contracts.json',
	},
	{
		path: 'fluentcart-mcp/src/logging.ts',
		mandate: 'plan 08 task 2 — replace process-global logging with factory-owned handlers',
		replacedBy: 'src/server.ts',
	},
	{
		path: 'fluentcart-mcp/tests/logging.test.ts',
		mandate: 'plan 08 task 2 — replace the legacy process-global logging test',
		replacedBy: 'tests/logging-capability.test.ts',
	},
	...SIMPLIFIED_PATHS.map((path) => ({
		path,
		mandate:
			'plan 02 task 1 — remove the retired guarded-write implementation and its direct tests',
		replacedBy: 'tests/acceptance/capability-matrix.test.mjs',
	})),
]

/** Files acceptance cannot run without. Their absence is the signature of a tidied tree. */
const REQUIRED_FILES = [
	'package.json',
	'tsconfig.json',
	'src/index.ts',
	'src/server.ts',
	'src/transport/auth.ts',
	'src/code-mode/sandbox.ts',
	'src/security/write-policy.ts',
	'scripts/measure-tool-context.mjs',
	'scripts/acceptance/run-acceptance.mjs',
	'scripts/acceptance/lanes.mjs',
	'scripts/acceptance/evidence-writer.mjs',
	'tests/fixtures/routes/fluentcart-1.6.0-core-pro-1.6.0.json',
]

/**
 * Git subcommands that discard, hide or rewrite working-tree state.
 *
 * Acceptance reads the tree and never repairs it. A harness that could run any of these could
 * turn a failed gate into a clean one, which is the single most effective way to make an
 * acceptance suite worthless.
 */
const DESTRUCTIVE_GIT_SUBCOMMANDS = [
	'clean',
	'stash',
	'restore',
	'checkout',
	'reset',
	'revert',
	'rm',
	'mv',
	'add',
	'commit',
	'push',
	'apply',
	'switch',
	'gc',
	'prune',
]

/** @throws when the argv is anything but a read-only inspection command. */
function assertReadOnlyGitCommand(args) {
	const subcommand = args.find((arg) => !arg.startsWith('-') && arg !== '-C' && !arg.includes('/'))
	if (DESTRUCTIVE_GIT_SUBCOMMANDS.includes(subcommand)) {
		throw new Error(
			`refusing to run "git ${subcommand}": acceptance never mutates the working tree`,
		)
	}
	return args
}

function git(...args) {
	assertReadOnlyGitCommand(args)
	return execFileSync('git', ['-C', REPO_ROOT, ...args], { encoding: 'utf8' })
}

function statusInScope() {
	const raw = git('status', '--porcelain', '--', ...PROGRAMME_PATHS)
	return raw
		.split('\n')
		.filter((line) => line.trim() !== '')
		.map((line) => ({ code: line.slice(0, 2), path: line.slice(3).replace(/^"|"$/g, '') }))
}

/** Porcelain marks a delete in either column: ` D` unstaged, `D ` staged, `AD` added then removed. */
const deletionsInScope = () => statusInScope().filter((entry) => entry.code.includes('D'))

/** Deletions with no mandate behind them. Exact-path matching: no prefixes, no near-misses. */
function unexplainedDeletions(deleted, mandated = MANDATED_DELETIONS) {
	const allowed = new Set(mandated.map((entry) => entry.path))
	return deleted.filter((path) => !allowed.has(path))
}

describe('programme scope', () => {
	it('names exactly the paths the plan puts in scope', () => {
		assert.equal(PROGRAMME_PATHS.length, 8)
		for (const path of PROGRAMME_PATHS) {
			assert.ok(!path.startsWith('/'), `${path} must be repository-relative`)
			assert.ok(!path.includes('*'), `${path} must not be a glob`)
			assert.notEqual(path, '.', 'the whole repository is never in scope')
			assert.notEqual(path, '', 'an empty path would silently widen the scope')
		}
	})

	it('reports only paths inside that scope', () => {
		for (const entry of statusInScope()) {
			const inScope = PROGRAMME_PATHS.some(
				(path) => entry.path === path || entry.path.startsWith(`${path}/`),
			)
			assert.ok(inScope, `git reported ${entry.path}, which is outside the declared scope`)
		}
	})

	it('deletes nothing in scope that a plan did not mandate', (t) => {
		const deleted = deletionsInScope().map((entry) => entry.path)
		t.diagnostic(`${statusInScope().length} changed paths in scope, ${deleted.length} deleted`)
		assert.deepEqual(
			unexplainedDeletions(deleted),
			[],
			'a tracked programme file was deleted without a plan mandating it; add it to MANDATED_DELETIONS with its plan and task, or restore it',
		)
	})

	it('would still catch a deletion nobody wrote down', () => {
		// Without this, the check above passes just as happily when the allowlist swallows
		// everything or when git reports nothing at all.
		const swept = ['fluentcart-mcp/src/server.ts', 'fluentcart-mcp/package.json']
		assert.deepEqual(unexplainedDeletions(swept), swept)
		assert.deepEqual(unexplainedDeletions([MANDATED_DELETIONS[0].path]), [])
		assert.deepEqual(unexplainedDeletions([`${MANDATED_DELETIONS[0].path}.bak`]), [
			`${MANDATED_DELETIONS[0].path}.bak`,
		])
	})

	it('records the plan and the replacement behind every allowlisted deletion', () => {
		// An allowlist entry that names no mandate is just a suppressed failure with extra steps.
		for (const entry of MANDATED_DELETIONS) {
			assert.match(entry.mandate, /plan \d+ task \d+/, `${entry.path} names no plan and task`)
			assert.ok(
				existsSync(join(PACKAGE_ROOT, entry.replacedBy)),
				`${entry.path} was deleted but its replacement ${entry.replacedBy} does not exist`,
			)
			const inScope = PROGRAMME_PATHS.some((path) => entry.path.startsWith(`${path}/`))
			assert.ok(inScope, `${entry.path} is not a programme path, so allowlisting it means nothing`)
		}
	})

	it('still contains every file the programme cannot run without', () => {
		// The other half of the deletion check: a clean or restore that removed something nobody
		// had staged would leave no `D` in porcelain at all, only a hole where the file used to be.
		for (const required of REQUIRED_FILES) {
			assert.ok(existsSync(join(PACKAGE_ROOT, required)), `${required} is missing from the tree`)
		}
	})

	it('carries no conflict markers or whitespace corruption in scope', () => {
		assert.doesNotThrow(() => git('diff', '--check', '--', ...PROGRAMME_PATHS))
	})

	it('keeps the checked route fixture present and readable', () => {
		const fixture = join(PACKAGE_ROOT, 'tests/fixtures/routes/fluentcart-1.6.0-core-pro-1.6.0.json')
		const parsed = JSON.parse(readFileSync(fixture, 'utf8'))
		assert.equal(parsed.schemaVersion, 1)
		assert.ok(Array.isArray(parsed.operations) && parsed.operations.length > 0)
	})
})

describe('destructive command refusal', () => {
	it('rejects every clean, stash and restore form', () => {
		for (const subcommand of DESTRUCTIVE_GIT_SUBCOMMANDS) {
			assert.throws(
				() => assertReadOnlyGitCommand([subcommand, '--', 'fluentcart-mcp']),
				new RegExp(`refusing to run "git ${subcommand}"`),
				`git ${subcommand} must be refused`,
			)
		}
		assert.throws(() => assertReadOnlyGitCommand(['clean', '-fdx']), /refusing to run/)
		assert.throws(() => assertReadOnlyGitCommand(['stash', 'push', '-u']), /refusing to run/)
		assert.throws(() => assertReadOnlyGitCommand(['restore', '--staged', '.']), /refusing to run/)
	})

	it('permits the read-only inspections acceptance actually needs', () => {
		for (const args of [
			['status', '--porcelain'],
			['diff', '--check'],
			['rev-parse', 'HEAD'],
			['ls-files'],
			['check-ignore', '--no-index', '-q', 'src/index.ts'],
		]) {
			assert.doesNotThrow(() => assertReadOnlyGitCommand(args), args.join(' '))
		}
	})
})

function acceptanceSources() {
	const directory = join(PACKAGE_ROOT, 'scripts', 'acceptance')
	return readdirSync(directory)
		.filter((name) => name.endsWith('.mjs'))
		.map((name) => ({ name, text: readFileSync(join(directory, name), 'utf8') }))
}

describe('harness cannot tidy the evidence away', () => {
	it('contains no destructive git invocation anywhere in the orchestrator', () => {
		for (const { name, text } of acceptanceSources()) {
			for (const subcommand of DESTRUCTIVE_GIT_SUBCOMMANDS) {
				assert.doesNotMatch(
					text,
					new RegExp(`['"\`]git['"\`][^\\n]*${subcommand}|git\\s+${subcommand}\\b`),
					`${name} must not be able to run git ${subcommand}`,
				)
			}
		}
	})

	it('never spawns a shell, so no command can be assembled from a string', () => {
		for (const { name, text } of acceptanceSources()) {
			assert.doesNotMatch(text, /shell:\s*true/, `${name} must not enable a shell`)
			assert.doesNotMatch(text, /\bexecSync\s*\(/, `${name} must not use execSync`)
		}
	})

	it('writes evidence outside tracked source', () => {
		const orchestrator = acceptanceSources().find((entry) => entry.name === 'evidence-writer.mjs')
		assert.ok(orchestrator, 'the evidence writer must exist before any lane runs')
		assert.match(orchestrator.text, /tracked source/)
	})
})
