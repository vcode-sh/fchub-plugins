import assert from 'node:assert/strict'
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { after, describe, it } from 'node:test'
import * as checker from './check-mcp-docs.mjs'

const { SCANNED_FILES, activeRules, checkMcpDocs, groundTruth } = checker

const fixtureRoot = mkdtempSync(join(tmpdir(), 'fluentcart-mcp-doc-rules-'))
after(() => rmSync(fixtureRoot, { force: true, recursive: true }))

function fixture(name, text) {
	const path = join(fixtureRoot, name)
	mkdirSync(dirname(path), { recursive: true })
	writeFileSync(path, text)
	return path
}

describe('FluentCart MCP documentation truth gate', () => {
	it('covers every current-facing surface', () => {
		const relativePaths = SCANNED_FILES.map((path) => path.replace(process.cwd(), ''))
		for (const path of [
			'/README.md',
			'/AGENTS.md',
			'/fluentcart-mcp/README.md',
			'/web-docs/app/(home)/fluentcart-mcp/page.tsx',
			'/web-docs/content/blog/fluentcart-mcp-vs-official-mcp.mdx',
			'/web-docs/content/docs/fluentcart-mcp/changelog.mdx',
		]) {
			assert.ok(relativePaths.includes(path), `${path} is not audited`)
		}
	})

	it('discovers nested tracked AGENTS, optional local CLAUDE files and no historical changelog', () => {
		assert.equal(typeof checker.currentFacingFiles, 'function')
		if (typeof checker.currentFacingFiles !== 'function') return

		const coverageRoot = mkdtempSync(join(tmpdir(), 'fluentcart-mcp-doc-coverage-'))
		after(() => rmSync(coverageRoot, { force: true, recursive: true }))
		for (const name of [
			'AGENTS.md',
			'operations/AGENTS.md',
			'CLAUDE.md',
			'fluentcart-mcp/CLAUDE.md',
			'web-docs/content/docs/fluentcart-mcp/_changelog/2026-03.mdx',
		]) {
			const path = join(coverageRoot, name)
			mkdirSync(dirname(path), { recursive: true })
			writeFileSync(path, 'fixture')
		}

		const files = checker.currentFacingFiles({
			exists: existsSync,
			repoRoot: coverageRoot,
			trackedFiles: ['AGENTS.md', 'operations/AGENTS.md'],
		})
		const relativePaths = files.map((path) => path.replace(`${coverageRoot}/`, ''))
		assert.deepEqual(relativePaths, ['AGENTS.md', 'operations/AGENTS.md', 'CLAUDE.md', 'fluentcart-mcp/CLAUDE.md'])
	})

	it('rejects claims that contradict generated release truth', () => {
		const claims = {
			'dynamic-five.md': 'Dynamic mode exposes five meta-tools by default.',
			'unavailable-actions.md': 'FluentCart MCP exposes refunds and subscription cancellation.',
			'certification-disclaimer.md': 'This is not evidence that every client has been certified.',
			'mixed-official-claim.md': 'The official FluentCart MCP and FluentCart MCP expose refunds.',
			'obsolete-protocol.md': 'The server speaks MCP protocol 2024-11-05.',
			'stale-red-gates.md': 'Two red gates remain before certification.',
			'direct-publication.md': 'Tag fluentcart-mcp/v<package-version> to publish npm and create a GitHub Release.',
			'broad-certification.md': 'All MCP clients are certified for FluentCart MCP 2.0.0.',
		}
		const files = Object.entries(claims).map(([name, text]) => fixture(name, text))
		const truth = groundTruth()
		assert.equal(truth.realMoneyExposable, 0)
		const findings = checkMcpDocs(files, activeRules(truth), truth)
		const byFile = Object.fromEntries(
			findings.map(({ path, findings: fileFindings }) => [path.split('/').at(-1), fileFindings.map(({ rule }) => rule)]),
		)

		assert.deepEqual(byFile['dynamic-five.md'], ['stale-dynamic-tool-count'])
		assert.deepEqual(byFile['unavailable-actions.md'], ['unavailable-write-presented-as-available'])
		assert.deepEqual(byFile['certification-disclaimer.md'], [])
		assert.deepEqual(byFile['mixed-official-claim.md'], ['unavailable-write-presented-as-available'])
		assert.deepEqual(byFile['obsolete-protocol.md'], ['obsolete-protocol'])
		assert.deepEqual(byFile['stale-red-gates.md'], ['stale-red-gate-count'])
		assert.deepEqual(byFile['direct-publication.md'], ['direct-publication-claim'])
		assert.deepEqual(byFile['broad-certification.md'], ['broad-client-certification'])
	})
})
