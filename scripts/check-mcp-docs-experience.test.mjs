import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const repoRoot = dirname(dirname(fileURLToPath(import.meta.url)))
const dashboardPrompt = 'Show me the FluentCart dashboard stats.'

function pathFromRoot(relativePath) {
	return join(repoRoot, relativePath)
}

function read(relativePath) {
	return readFileSync(pathFromRoot(relativePath), 'utf8')
}

function expectExisting(relativePath) {
	assert.ok(existsSync(pathFromRoot(relativePath)), `${relativePath} does not exist`)
}

function readRequired(relativePath) {
	if (!existsSync(pathFromRoot(relativePath))) {
		assert.fail(`${relativePath} does not exist`)
	}
	return read(relativePath)
}

const beginnerPages = {
	'Claude Desktop': 'web-docs/content/docs/fluentcart-mcp/claude-desktop.mdx',
	'ChatGPT Desktop': 'web-docs/content/docs/fluentcart-mcp/chatgpt-desktop.mdx',
	Cursor: 'web-docs/content/docs/fluentcart-mcp/cursor.mdx',
	'Other clients': 'web-docs/content/docs/fluentcart-mcp/other-clients.mdx',
}

describe('beginner client journeys', () => {
	it('gives every primary client a distinct existing page and direct chooser links', () => {
		const index = read('web-docs/content/docs/fluentcart-mcp/index.mdx')
		const setup = read('web-docs/content/docs/fluentcart-mcp/setup.mdx')

		for (const [label, relativePath] of Object.entries(beginnerPages)) {
			expectExisting(relativePath)
			const route = `/docs/fluentcart-mcp/${relativePath.split('/').at(-1).replace('.mdx', '')}`
			assert.match(index, new RegExp(route))
			assert.match(setup, new RegExp(route))
		}
		assert.match(index, /\[ChatGPT web\]\(\/docs\/fluentcart-mcp\/chatgpt-web\)/)
	})

	it('uses common beginner recipe headings and one dashboard verification prompt', () => {
		for (const relativePath of Object.values(beginnerPages)) {
			const page = readRequired(relativePath)
			for (const heading of [
				'## What you need',
				'## Create safe store access',
				'## Confirm it works',
				'## If it does not work',
				'## Optional next steps',
			]) {
				assert.match(page, new RegExp(heading.replace(/[.*+?^${}()|[\\]\\]/g, '\\$&')))
			}
			assert.match(page, new RegExp(dashboardPrompt.replace(/[.*+?^${}()|[\\]\\]/g, '\\$&')))
		}
	})

	it('keeps Claude no-Node and local-client Node and no-global-install guidance distinct', () => {
		const claude = readRequired(beginnerPages['Claude Desktop'])
		const localClients = [
			readRequired(beginnerPages['ChatGPT Desktop']),
			readRequired(beginnerPages.Cursor),
			readRequired(beginnerPages['Other clients']),
		].join('\n')

		assert.match(claude, /no Terminal or separate Node\.js installation is required/i)
		assert.match(localClients, /Node\.js 24/i)
		assert.match(localClients, /does not install[^.]*globally/i)
	})
})

describe('safe diagnostics', () => {
	it('does not publish credentials in a curl command', () => {
		assert.doesNotMatch(
			read('web-docs/content/docs/fluentcart-mcp/troubleshooting.mdx'),
			/curl\s+-u\s+["'][^"']*:[^"']*["']/,
		)
	})

	it('requires redaction before recommending an online JSON validator', () => {
		const troubleshooting = read('web-docs/content/docs/fluentcart-mcp/troubleshooting.mdx')
		const jsonLint = troubleshooting.match(/.{0,160}jsonlint\.com.{0,160}/is)?.[0] ?? ''
		assert.ok(!jsonLint || /redact/i.test(jsonLint))
	})
})

describe('current release parity', () => {
	it('keeps current-facing package versions out of non-historical content', () => {
		for (const relativePath of [
			'web-docs/content/docs/fluentcart-mcp/usage.mdx',
			'web-docs/content/docs/fluentcart-mcp/tools.mdx',
			'web-docs/app/(home)/fluentcart-mcp/page.tsx',
			'web-docs/app/(home)/fluentcart-mcp/layout.tsx',
			'web-docs/content/blog/fluentcart-mcp-vs-official-mcp.mdx',
		]) {
			assert.doesNotMatch(read(relativePath), /\b2\.0\.0\b/, `${relativePath} presents a stale release version`)
		}
	})

	it('keeps file uploads unavailable and makes reviewed tax-settings saves reversible only', () => {
		const usage = read('web-docs/content/docs/fluentcart-mcp/usage.mdx')
		const tools = read('web-docs/content/docs/fluentcart-mcp/tools.mdx')

		assert.match(tools, /does not expose:[\s\S]{0,200}file uploads/i)
		assert.match(usage, /global tax settings[\s\S]{0,120}FLUENTCART_WRITE_MODE=reversible|FLUENTCART_WRITE_MODE=reversible[\s\S]{0,120}global tax settings/i)
		assert.doesNotMatch(usage, /saving global tax settings[^.]*not exposed/i)
	})

	it('does not make a blanket page and per_page claim for every list tool', () => {
		assert.doesNotMatch(
			read('web-docs/content/docs/fluentcart-mcp/troubleshooting.mdx'),
			/every list tool supports [`']?per_page[`']? and [`']?page/i,
		)
	})

	it('keeps the FluentCart 1.3.9 capture as route evidence rather than tool support', () => {
		const compatibility = JSON.parse(read('fluentcart-mcp/compatibility-support.json'))
		assert.match(compatibility.legacyClaimPolicy, /route surface/i)
		assert.doesNotMatch(compatibility.legacyClaimPolicy, /runtime support may now be claimed/i)
		assert.match(compatibility.legacyClaimPolicy, /does not prove tool compatibility or supported operation/i)
	})
})

describe('usage policy examples', () => {
	it('does not offer unavailable file uploads or global tax-settings writes as prompts', () => {
		const usage = read('web-docs/content/docs/fluentcart-mcp/usage.mdx')
		assert.doesNotMatch(usage, /["'](?:upload|attach) [^"']*(?:file|document)[^"']*["']/i)
		assert.doesNotMatch(usage, /["'](?:save|change|update) [^"']*(?:global )?tax settings[^"']*["']/i)
	})
})

describe('marketing and blog truth', () => {
	it('does not turn source definitions into unqualified marketing metadata', () => {
		const layout = read('web-docs/app/(home)/fluentcart-mcp/layout.tsx')
		assert.match(
			layout,
			/description: "Open-source MCP server for reading and safely administering a FluentCart store from supported AI clients\."/,
		)
		assert.doesNotMatch(layout, /mcpToolCount|mcpSourceDefinitionCount/)
	})

	it('links beginner and advanced readers to their truthful destinations', () => {
		const comparison = read('web-docs/content/blog/fluentcart-mcp-vs-official-mcp.mdx')
		assert.match(comparison, /\]\(\/docs\/fluentcart-mcp\/(?:setup|chatgpt-desktop|claude-desktop|cursor|other-clients)\)/)
		assert.match(comparison, /\]\(\/docs\/fluentcart-mcp\/configuration\)/)
		assert.match(comparison, /independent server is optional and not required to use FluentCart or the official MCP/i)
		assert.doesNotMatch(comparison, /default dynamic surface has \*\*three\*\*/i)
	})
})
