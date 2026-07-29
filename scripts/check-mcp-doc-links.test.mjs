import assert from 'node:assert/strict'
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { afterEach, describe, it } from 'node:test'
import {
	checkMcpDocLinks,
	documentationLinks,
	headingAnchors,
	routeForDoc,
} from './check-mcp-doc-links.mjs'

const temporaryRoots = []

function fixture(files) {
	const root = mkdtempSync(join(tmpdir(), 'mcp-doc-links-'))
	temporaryRoots.push(root)
	for (const [path, source] of Object.entries(files)) {
		const absolute = join(root, path)
		mkdirSync(dirname(absolute), { recursive: true })
		writeFileSync(absolute, source)
	}
	return root
}

afterEach(() => {
	for (const root of temporaryRoots.splice(0)) rmSync(root, { recursive: true, force: true })
})

describe('FluentCart MCP documentation links', () => {
	it('maps flat MDX pages and ignores private include directories', () => {
		assert.equal(
			routeForDoc('web-docs/content/docs/fluentcart-mcp/index.mdx'),
			'/docs/fluentcart-mcp',
		)
		assert.equal(
			routeForDoc('web-docs/content/docs/fluentcart-mcp/chatgpt-web.mdx'),
			'/docs/fluentcart-mcp/chatgpt-web',
		)
		assert.equal(
			routeForDoc('web-docs/content/docs/fluentcart-mcp/_changelog/2026-07.mdx'),
			null,
		)
	})

	it('derives unique rendered heading anchors and ignores fenced examples', () => {
		const anchors = headingAnchors([
			'## 1. Credential setup failed',
			'## Repeated heading',
			'## Repeated heading',
			'## Repeated heading-1',
			'```bash',
			'# Windows',
			'```',
		].join('\n'))

		assert.deepEqual(
			[...anchors],
			[
				'1-credential-setup-failed',
				'repeated-heading',
				'repeated-heading-1',
				'repeated-heading-1-1',
			],
		)
	})

	it('extracts multiline Markdown, absolute and JSX links but ignores code examples', () => {
		const links = documentationLinks([
			'[Local](/docs/fluentcart-mcp/setup#choose)',
			'[Absolute](https://fchub.co/docs/fluentcart-mcp/tools)',
			'[Multiline label',
			'](/docs/fluentcart-mcp/troubleshooting#credential-setup-failed)',
			'[Multiline destination](',
			'  /docs/fluentcart-mcp/configuration#presentation-modes',
			')',
			'<Card href="/docs/fluentcart-mcp/usage">Use</Card>',
			'`[Inline example](/docs/fluentcart-mcp/not-a-route)`',
			'```md',
			'[Example](/docs/fluentcart-mcp/missing)',
			'```',
		].join('\n'))

		assert.deepEqual(
			links.map(({ target }) => target),
			[
				'/docs/fluentcart-mcp/setup#choose',
				'https://fchub.co/docs/fluentcart-mcp/tools',
				'/docs/fluentcart-mcp/troubleshooting#credential-setup-failed',
				'/docs/fluentcart-mcp/configuration#presentation-modes',
				'/docs/fluentcart-mcp/usage',
			],
		)
	})

	it('reports missing routes and fragments across MDX and README sources', () => {
		const docsRoot = 'web-docs/content/docs/fluentcart-mcp'
		const root = fixture({
			[`${docsRoot}/index.mdx`]: '## Choose your app\n',
			[`${docsRoot}/setup.mdx`]: '## Choose one\n',
			'README.md': [
				'[Good](https://fchub.co/docs/fluentcart-mcp/setup#choose-one)',
				'[Bad route](/docs/fluentcart-mcp/deployment)',
				'[Bad fragment](/docs/fluentcart-mcp/setup#old-heading)',
			].join('\n'),
		})
		const trackedFiles = [
			`${docsRoot}/index.mdx`,
			`${docsRoot}/setup.mdx`,
			'README.md',
		]
		const findings = checkMcpDocLinks({
			repoRoot: root,
			trackedFiles,
			files: [join(root, 'README.md')],
		})

		assert.deepEqual(
			findings.map(({ message }) => message),
			[
				'missing FluentCart MCP route /docs/fluentcart-mcp/deployment',
				'missing heading #old-heading on /docs/fluentcart-mcp/setup',
			],
		)
	})

	it('resolves the repository current-facing routes and fragments', () => {
		assert.deepEqual(checkMcpDocLinks(), [])
	})
})
