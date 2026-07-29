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

	it('discovers every current FluentCart MCP page, including new MDX files', () => {
		assert.equal(typeof checker.currentFacingFiles, 'function')
		if (typeof checker.currentFacingFiles !== 'function') return

		const coverageRoot = mkdtempSync(join(tmpdir(), 'fluentcart-mcp-doc-coverage-'))
		after(() => rmSync(coverageRoot, { force: true, recursive: true }))
		for (const name of [
			'README.md',
			'AGENTS.md',
			'operations/AGENTS.md',
			'fluentcart-mcp/README.md',
			'CLAUDE.md',
			'fluentcart-mcp/CLAUDE.md',
			'web-docs/app/(home)/fluentcart-mcp/layout.tsx',
			'web-docs/app/(home)/fluentcart-mcp/page.tsx',
			'web-docs/app/(home)/home-resource-links.tsx',
			'web-docs/content/blog/fluentcart-mcp-vs-official-mcp.mdx',
			'web-docs/content/docs/fluentcart-mcp/index.mdx',
			'web-docs/content/docs/fluentcart-mcp/proof.mdx',
			'web-docs/content/docs/fluentcart-mcp/new-client.mdx',
			'web-docs/content/docs/fluentcart-mcp/_changelog/2026-07.mdx',
		]) {
			const path = join(coverageRoot, name)
			mkdirSync(dirname(path), { recursive: true })
			writeFileSync(path, 'fixture')
		}

		const files = checker.currentFacingFiles({
			exists: existsSync,
			repoRoot: coverageRoot,
			trackedFiles: [
				'AGENTS.md',
				'operations/AGENTS.md',
				'web-docs/content/docs/fluentcart-mcp/index.mdx',
				'web-docs/content/docs/fluentcart-mcp/proof.mdx',
				'web-docs/content/docs/fluentcart-mcp/new-client.mdx',
				'web-docs/content/docs/fluentcart-mcp/_changelog/2026-07.mdx',
			],
		})
		const relativePaths = files.map((path) => path.replace(`${coverageRoot}/`, ''))

		assert.ok(relativePaths.includes('web-docs/content/docs/fluentcart-mcp/proof.mdx'))
		assert.ok(relativePaths.includes('web-docs/content/docs/fluentcart-mcp/new-client.mdx'))
		assert.ok(!relativePaths.includes('web-docs/content/docs/fluentcart-mcp/_changelog/2026-07.mdx'))

		for (const path of [
			'README.md',
			'AGENTS.md',
			'operations/AGENTS.md',
			'fluentcart-mcp/README.md',
			'CLAUDE.md',
			'fluentcart-mcp/CLAUDE.md',
			'web-docs/app/(home)/fluentcart-mcp/layout.tsx',
			'web-docs/app/(home)/fluentcart-mcp/page.tsx',
			'web-docs/app/(home)/home-resource-links.tsx',
			'web-docs/content/blog/fluentcart-mcp-vs-official-mcp.mdx',
			'web-docs/content/docs/fluentcart-mcp/index.mdx',
			'web-docs/content/docs/fluentcart-mcp/proof.mdx',
			'web-docs/content/docs/fluentcart-mcp/new-client.mdx',
		]) {
			assert.ok(relativePaths.includes(path), `${path} is not audited`)
		}
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
			'legacy-next-flow.md': 'The release workflow publishes npm under `next` before promotion.',
			'legacy-token-flow.md': 'Configure NPM_PROMOTION_TOKEN for the promotion workflow.',
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
		assert.deepEqual(byFile['legacy-next-flow.md'], ['obsolete-npm-release-flow'])
		assert.deepEqual(byFile['legacy-token-flow.md'], ['obsolete-npm-release-flow'])
		assert.deepEqual(byFile['broad-certification.md'], ['broad-client-certification'])
	})

	it('rejects unavailable high-impact writes when documentation presents them as usable', () => {
		const claims = {
			'delete-order.md': 'FluentCart MCP lets you delete an order.',
			'bulk-products.md': 'Use bulk actions to update products.',
			'order-status.md': 'You can change an order status through FluentCart MCP.',
			'mark-paid.md': 'Mark an order paid from the chat.',
			'charge-card.md': 'FluentCart MCP can charge a credit card.',
			'policy.md': 'Deletion, bulk operations, order status changes, marking an order paid and money-moving actions are not exposed.',
		}
		const files = Object.entries(claims).map(([name, text]) => fixture(name, text))
		const truth = groundTruth()
		const findings = checkMcpDocs(files, activeRules(truth), truth)
		const byFile = Object.fromEntries(
			findings.map(({ path, findings: fileFindings }) => [path.split('/').at(-1), fileFindings.map(({ rule }) => rule)]),
		)

		for (const name of ['delete-order.md', 'bulk-products.md', 'order-status.md', 'mark-paid.md', 'charge-card.md']) {
			assert.deepEqual(byFile[name], ['unavailable-high-impact-write-presented-as-available'])
		}
		assert.deepEqual(byFile['policy.md'], [])
	})

	it('rejects wrapped runtime and ChatGPT authentication claims without flagging supported negations', () => {
		const claims = {
			'wrapped-claude-runtime.md': [
				'Claude Desktop MCPB extension users must',
				'install Node.js 24 before setup.',
			].join('\n'),
			'wrapped-mcpb-runtime.md': [
				'The MCPB archive contains a',
				'Node runtime.',
			].join('\n'),
			'wrapped-chatgpt-auth.md': [
				'For ChatGPT web, add',
				'FLUENTCART_MCP_API_KEY as bearer authentication.',
			].join('\n'),
			'contradictory-claude-runtime.md': [
				'Claude Desktop MCPB extension users do not install Node!',
				'Claude Desktop MCPB extension users must install Node.js 24 before setup.',
			].join('\n'),
			'contradictory-mcpb-runtime.md': [
				'The MCPB does not contain Node.',
				'The MCPB archive contains a Node runtime.',
			].join('\n'),
			'contradictory-chatgpt-auth.md': [
				'FLUENTCART_MCP_API_KEY is not ChatGPT plugin authentication!',
				'ChatGPT web uses FLUENTCART_MCP_API_KEY as bearer authentication.',
			].join('\n'),
			'supported-negations.md': [
				'Claude Desktop supplies its own Node runtime. Extension users do not',
				'install Node separately.',
				'',
				'The MCPB contains JavaScript. It does not contain',
				'a Node executable.',
				'',
				'FLUENTCART_MCP_API_KEY is not',
				'ChatGPT plugin authentication.',
			].join('\n'),
		}
		const files = Object.entries(claims).map(([name, text]) => fixture(name, text))
		const truth = groundTruth()
		const findings = checkMcpDocs(files, activeRules(truth), truth)
		const byFile = Object.fromEntries(
			findings.map(({ path, findings: fileFindings }) => [path.split('/').at(-1), fileFindings.map(({ rule }) => rule)]),
		)

		assert.deepEqual(byFile['wrapped-claude-runtime.md'], ['claude-extension-node-prerequisite'])
		assert.deepEqual(byFile['wrapped-mcpb-runtime.md'], ['mcpb-bundles-node-runtime'])
		assert.deepEqual(byFile['wrapped-chatgpt-auth.md'], ['chatgpt-static-bearer-auth'])
		assert.deepEqual(byFile['contradictory-claude-runtime.md'], ['claude-extension-node-prerequisite'])
		assert.deepEqual(byFile['contradictory-mcpb-runtime.md'], ['mcpb-bundles-node-runtime'])
		assert.deepEqual(byFile['contradictory-chatgpt-auth.md'], ['chatgpt-static-bearer-auth'])
		assert.deepEqual(byFile['supported-negations.md'], [])
	})
})
