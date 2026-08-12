import assert from 'node:assert/strict'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
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
			'staged-publication.md': 'The tag creates an npm stage for interactive 2FA approval.',
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
		assert.deepEqual(byFile['staged-publication.md'], ['staged-publication-claim'])
		assert.deepEqual(byFile['legacy-next-flow.md'], ['obsolete-npm-release-flow'])
		assert.deepEqual(byFile['legacy-token-flow.md'], ['obsolete-npm-release-flow'])
		assert.deepEqual(byFile['broad-certification.md'], ['broad-client-certification'])
	})

	it('rejects unavailable high-impact writes when documentation presents them as usable', () => {
		const claims = {
			'engineering-guidance.md': 'Delete the tests whose subject is gone.',
			'delete-order.md': 'FluentCart MCP lets you delete an order.',
			'remove-customer.md': 'FluentCart MCP lets you remove a customer.',
			'bulk-products.md': 'Use bulk actions to update products.',
			'order-status.md': 'You can change an order status through FluentCart MCP.',
			'order-transition.md': 'Move an order to completed from the chat.',
			'moving-order.md': 'FluentCart MCP supports moving an order to completed.',
			'mark-paid.md': 'Mark an order paid from the chat.',
			'charge-card.md': 'FluentCart MCP can charge a credit card.',
			'collect-payment.md': 'Collect a payment from the customer.',
			'take-payment.md': 'Take payment for the order.',
			'debit-card.md': 'Debit a card for the invoice.',
			'capture-payment.md': 'Capture a payment for order #42.',
			'collecting-payments.md': 'FluentCart MCP supports collecting payments.',
			'payment-capture.md': 'Payment capture is supported.',
			'payment-captures.md': 'Payment captures are supported.',
			'policy.md': [
				'Deletion, bulk operations, order status changes, marking an order paid and money-moving actions are not exposed.',
				'Do not move an order to completed.',
				'No mode can collect payments.',
				'Payment capture is not supported.',
			].join('\n'),
		}
		const files = Object.entries(claims).map(([name, text]) => fixture(name, text))
		const truth = groundTruth()
		const findings = checkMcpDocs(files, activeRules(truth), truth)
		const byFile = Object.fromEntries(
			findings.map(({ path, findings: fileFindings }) => [path.split('/').at(-1), fileFindings.map(({ rule }) => rule)]),
		)

		for (const name of [
			'delete-order.md',
			'remove-customer.md',
			'bulk-products.md',
			'order-status.md',
			'order-transition.md',
			'moving-order.md',
			'mark-paid.md',
			'charge-card.md',
			'collect-payment.md',
			'take-payment.md',
			'debit-card.md',
			'capture-payment.md',
			'collecting-payments.md',
			'payment-capture.md',
			'payment-captures.md',
		]) {
			assert.deepEqual(byFile[name], ['unavailable-high-impact-write-presented-as-available'])
		}
		assert.deepEqual(byFile['engineering-guidance.md'], [])
		assert.deepEqual(byFile['policy.md'], [])
	})

	it('requires numeric tool counts to name the right current truth concept', () => {
		const truth = groundTruth()
		const claims = {
			'bare-tools.md': 'tools: 289',
			'tool-count.md': 'Tool count: 289',
			'hyphenated-tool-count.md': 'MCP tool-count — 289',
			'mcp-tools.md': '289 MCP tools',
			'singular-mcp-tool.md': '1 MCP tool',
			'scattered-terms.md': '289 tools. Source definitions, measured profiles and client-visible tools are distinct.',
			'stale-source.md': `Source definitions: ${truth.sourceDefinitionCount + 1}`,
			'current-source.md': `Source definitions: ${truth.sourceDefinitionCount}`,
			'stale-profile.md': `The dynamic profile exposes ${truth.defaultDynamicToolCount + 1} tools.`,
			'current-profile.md': `The dynamic profile exposes ${truth.defaultDynamicToolCount} tools.`,
			'client-visible.md': 'This connected store reports 12 client-visible tools in tools/list.',
			'evergreen.md': 'Open-source MCP server for reading and safely administering a FluentCart store from supported AI clients.',
		}
		const files = Object.entries(claims).map(([name, text]) => fixture(name, text))
		const findings = checkMcpDocs(files, activeRules(truth), truth)
		const byFile = Object.fromEntries(
			findings.map(({ path, findings: fileFindings }) => [path.split('/').at(-1), fileFindings.map(({ rule }) => rule)]),
		)

		for (const name of [
			'bare-tools.md',
			'tool-count.md',
			'hyphenated-tool-count.md',
			'mcp-tools.md',
			'singular-mcp-tool.md',
			'scattered-terms.md',
			'stale-source.md',
			'stale-profile.md',
		]) {
			assert.deepEqual(byFile[name], ['unqualified-tool-count'])
		}
		for (const name of ['current-source.md', 'current-profile.md', 'client-visible.md', 'evergreen.md']) {
			assert.deepEqual(byFile[name], [])
		}
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

describe('FluentCart MCP repository guidance', () => {
	const read = (path) => readFileSync(join(process.cwd(), path), 'utf8')

	it('keeps package examples least-privilege, shell-valid, and valid JSON', () => {
		const readme = read('fluentcart-mcp/README.md')
		assert.doesNotMatch(readme, /FLUENTCART_(?:USERNAME|ABILITIES_USERNAME)=admin\b/)
		assert.doesNotMatch(readme, /"username":\s*"admin"/)
		assert.doesNotMatch(readme, /\bAn admin account works\b/i)
		assert.match(readme, /FLUENTCART_USERNAME=fluentcart-reader/)
		assert.match(readme, /FLUENTCART_APP_PASSWORD="[^"\n]+"/)
		assert.match(readme, /FLUENTCART_ABILITIES_APP_PASSWORD="[^"\n]+"/)

		const configBlock = readme.match(
			/### 2\. Config File[\s\S]*?```json\n([\s\S]*?)\n```/,
		)?.[1]
		assert.ok(configBlock, 'package README must contain the config-file JSON example')
		assert.equal(JSON.parse(configBlock).username, 'fluentcart-reader')
	})

	it('uses tracked AGENTS.md as the canonical release-recovery source', () => {
		const agents = read('AGENTS.md')
		const design = read(
			'docs/superpowers/specs/2026-07-29-fluentcart-mcp-simple-documentation-design.md',
		)
		const plan = read(
			'docs/superpowers/plans/2026-07-29-fluentcart-mcp-simple-documentation.md',
		)

		assert.ok(!existsSync(join(process.cwd(), 'docs/developer-manual.md')))
		assert.match(agents, /If a published release needs correcting/i)
		assert.match(agents, /deprecate the faulty\s+publication/i)
		assert.match(agents, /never reuse a released version or\s+tag/i)
		assert.match(agents, /new patch version with fresh evidence/i)
		assert.match(design, /tracked `AGENTS\.md`/)
		assert.match(plan, /tracked `AGENTS\.md`/)
		assert.doesNotMatch(design, /release recovery belongs in the internal developer manual/i)
		assert.doesNotMatch(plan, /Move release recovery into `docs\/developer-manual\.md`/)
	})
})
