import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import { currentFacingFiles } from './check-mcp-docs.mjs'
import { CURRENT_FACING_MARKETING_FILES } from './mcp-doc-rules.mjs'

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

const chatgptWebPage = 'web-docs/content/docs/fluentcart-mcp/chatgpt-web.mdx'

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
		expectExisting(chatgptWebPage)
	})

	it('keeps the ChatGPT web tunnel separate from local ChatGPT and Codex STDIO setup', () => {
		const chatgptWeb = readRequired(chatgptWebPage)
		const chatgptDesktop = readRequired(beginnerPages['ChatGPT Desktop'])

		assert.match(chatgptWeb, /Secure MCP Tunnel/i)
		assert.match(chatgptWeb, /does not read[^.]*\.codex\/config\.toml/i)
		assert.doesNotMatch(chatgptWeb, /Settings → MCP servers → Add server|codex mcp add/i)
		assert.match(chatgptDesktop, /Settings → MCP servers → Add server/i)
		assert.doesNotMatch(chatgptDesktop, /Secure MCP Tunnel/i)
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

	it('keeps Claude no-Node and each local client Node and npx guidance distinct', () => {
		const claude = readRequired(beginnerPages['Claude Desktop'])

		assert.match(claude, /no Terminal or separate Node\.js installation is required/i)
		for (const relativePath of [
			beginnerPages['ChatGPT Desktop'],
			beginnerPages.Cursor,
			beginnerPages['Other clients'],
		]) {
			const page = readRequired(relativePath)
			assert.match(page, /Node\.js 24 or newer/i, `${relativePath} must require Node.js 24 or newer`)
			assert.match(
				page,
				/npx -y[\s\S]{0,200}does not install[^.]*globally/i,
				`${relativePath} must tie no-global-install guidance to npx`,
			)
		}
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
		for (const path of currentFacingFiles()) {
			if (path.endsWith('/changelog.mdx')) continue
			assert.doesNotMatch(readFileSync(path, 'utf8'), /\b2\.0\.0\b/, `${path} presents a stale release version`)
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
	it('keeps the default read-only and does not offer unavailable writes as prompts', () => {
		const usage = read('web-docs/content/docs/fluentcart-mcp/usage.mdx')
		assert.match(usage, /default[^.]{0,100}(?:read-only|reads? and nothing else|writes? disabled)/i)
		assert.doesNotMatch(usage, /["'](?:upload|attach) [^"']*(?:file|document)[^"']*["']/i)
		assert.doesNotMatch(usage, /["'](?:save|change|update) [^"']*(?:global )?tax settings[^"']*["']/i)
		assert.doesNotMatch(usage, /["'](?:delete|remove) [^"']*(?:order|product|customer|coupon|subscription)[^"']*["']/i)
		assert.doesNotMatch(usage, /["'](?:bulk[- ]?(?:edit|update|delete)|update [^"']* in bulk)[^"']*["']/i)
		assert.doesNotMatch(usage, /["'](?:change|set|update) [^"']*order status[^"']*["']/i)
		assert.doesNotMatch(usage, /["']mark [^"']*order paid[^"']*["']/i)
		assert.doesNotMatch(usage, /["'](?:refund|cancel|charge|capture) [^"']*(?:order|subscription|card|payment)[^"']*["']/i)
	})
})

describe('marketing and blog truth', () => {
	it('does not turn source definitions into unqualified marketing counts', () => {
		const layoutPath = 'web-docs/app/(home)/fluentcart-mcp/layout.tsx'
		const layout = read(layoutPath)
		assert.match(
			layout,
			/description: "Open-source MCP server for reading and safely administering a FluentCart store from supported AI clients\."/,
		)
		assert.doesNotMatch(layout, /mcpToolCount|mcpSourceDefinitionCount/)

		for (const relativePath of CURRENT_FACING_MARKETING_FILES) {
			const marketing = read(relativePath)
			assert.doesNotMatch(marketing, /mcpToolCount|mcpSourceDefinitionCount/i, `${relativePath} exposes a source count`)
			assert.doesNotMatch(
				marketing,
				/\b(?:\d+\s+(?:mcp\s+)?tools?|\d+\s+(?:source\s+)?(?:tool\s+)?definitions?|(?:mcp\s+)?tools?(?:\s*[- ]?\s*count)?\s*(?::|—|-)\s*\d+)\b/i,
				`${relativePath} presents an unqualified tool count`,
			)
		}

		const comparison = read('web-docs/content/blog/fluentcart-mcp-vs-official-mcp.mdx')
		assert.match(comparison, /source (?:registry|definitions?)/i)
		assert.match(comparison, /measured profiles?/i)
		assert.match(comparison, /client-visible/i)
	})

	it('links beginner and advanced readers to their truthful destinations', () => {
		const comparison = read('web-docs/content/blog/fluentcart-mcp-vs-official-mcp.mdx')
		assert.match(comparison, /\]\(\/docs\/fluentcart-mcp\/(?:setup|chatgpt-desktop|claude-desktop|cursor|other-clients)\)/)
		assert.match(comparison, /\]\(\/docs\/fluentcart-mcp\/configuration\)/)
		assert.match(comparison, /independent server is optional and not required to use FluentCart or the official MCP/i)
		assert.doesNotMatch(comparison, /default dynamic surface has \*\*three\*\*/i)
	})
})
