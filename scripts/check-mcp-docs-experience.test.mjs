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

function hasSentenceWithConcepts(page, patterns) {
	return page
		.split(/(?<=[.!?])\s+/)
		.some((sentence) => sentence.length <= 360 && patterns.every((pattern) => pattern.test(sentence)))
}

function assertBeginnerAccountDataBoundary(page, relativePath) {
	assert.ok(
		hasSentenceWithConcepts(page, [
			/\bdedicated\b/i,
			/\blow[- ]privilege\b/i,
			/\b(?:WordPress\s+)?account\b/i,
		]),
		`${relativePath} must require a dedicated low-privilege account`,
	)
	assert.ok(
		hasSentenceWithConcepts(page, [
			/\b(?:WordPress|account(?:'s)?)\s+permissions?\b/i,
			/\b(?:decide|determine|control|bound|limit|govern)\w*\b/i,
			/\b(?:FluentCart(?:\s+MCP)?|store)\s+data\b/i,
			/\b(?:read|access|see)\w*\b/i,
		]),
		`${relativePath} must explain permission-limited readable data`,
	)
	assert.ok(
		hasSentenceWithConcepts(page, [
			/\bread[- ]only\b/i,
			/\b(?:block|prevent|stop|disable|prohibit|restrict)\w*\b/i,
			/\bMCP\b/i,
			/\b(?:write|change|modify|update)\w*\b/i,
		]),
		`${relativePath} must explain the read-only MCP write block`,
	)
	assert.ok(
		hasSentenceWithConcepts(page, [
			/\b(?:chosen|selected|configured)\s+(?:AI|assistant)\s+client\b/i,
			/\b(?:returned|response)\b/i,
			/\b(?:customer|order)\b[\s\S]{0,20}\bdata\b/i,
			/\b(?:see|read|receive|access|process)\w*\b|(?:does not|do not|cannot)\b[\s\S]{0,100}\b(?:private|hidden|secret)\b[\s\S]{0,100}\b(?:AI|assistant)\s+client\b/i,
		]),
		`${relativePath} must explain AI client data visibility`,
	)
}

function assertBeginnerPrerequisites(page, relativePath) {
	assert.match(
		page,
		/WordPress[\s\S]{0,80}FluentCart[\s\S]{0,60}(?:installed and )?active/i,
		`${relativePath} must require an active FluentCart installation`,
	)
	assert.match(
		page,
		/\bHTTPS\b[\s\S]{0,30}\b(?:store )?URL\b/i,
		`${relativePath} must require an HTTPS store URL`,
	)
	assert.match(
		page,
		/Application Password[\s\S]{0,100}(?:different from|not)[\s\S]{0,60}(?:normal\s+)?WordPress\s+login\s+password/i,
		`${relativePath} must distinguish an Application Password from the normal login password`,
	)
}

function assertLocalGuiPathRecovery(page, relativePath) {
	assert.match(
		page,
		/(?:macOS or Linux[\s\S]{0,100}which npx|which npx[\s\S]{0,100}macOS or Linux)/is,
		`${relativePath} must show which npx`,
	)
	assert.match(
		page,
		/(?:Windows[\s\S]{0,100}where npx|where npx[\s\S]{0,100}Windows)/is,
		`${relativePath} must show where npx`,
	)
	assert.match(
		page,
		/(?:replace|use)[\s\S]{0,100}(?:command|npx)[\s\S]{0,100}absolute path/i,
		`${relativePath} must tell GUI users to use the absolute command path`,
	)
	assert.match(page, /\bnpx\.cmd\b/i, `${relativePath} must name the Windows npx.cmd executable`)
}

function hasPrescriptiveWordPressAccessInstruction(page, target) {
	const prescriptiveVerb = /\b(?:use|assign|choose|set|give|grant|recommend|require|create|select|sign\s+in\s+as)\b/gi
	const targetAfterVerb = new RegExp(
		`^[\\s\\S]{0,120}\\b(?:an?\\s+|the\\s+)?${target}`,
		'i',
	)
	const immediateNegation = /(?:\b(?:do|does|did|should|must|can|will)\s+not\s+|\bnever\s+|\bavoid\s+)$/i

	return page
		.split(/(?:[.;!?\n]+|\b(?:but|however)\b[,:]?)/i)
		.some((clause) => {
			for (const verb of clause.matchAll(prescriptiveVerb)) {
				const beforeVerb = clause.slice(0, verb.index)
				if (immediateNegation.test(beforeVerb)) {
					continue
				}
				if (targetAfterVerb.test(clause.slice(verb.index + verb[0].length))) {
					return true
				}
			}
			return false
		})
}

function assertNoUnsupportedWordPressAccessPrescription(page, relativePath) {
	assert.ok(
		!hasPrescriptiveWordPressAccessInstruction(
			page,
			'(?:administrator(?:\\s+account|\\s+role)?|shop manager(?:\\s+role)?|admin(?:istrator)? account)\\b',
		),
		`${relativePath} must not prescribe an unsupported exact WordPress role`,
	)
	assert.ok(
		!hasPrescriptiveWordPressAccessInstruction(
			page,
			'(?:manage|read|edit|delete|publish|create|install|activate|update)_[a-z0-9_]+\\b',
		),
		`${relativePath} must not prescribe an unsupported named WordPress capability`,
	)
}

const chatgptWebPage = 'web-docs/content/docs/fluentcart-mcp/chatgpt-web.mdx'
const requiredDocsCiPaths = [
	'scripts/mcp-doc-rules.mjs',
	'scripts/check-mcp-doc-links.mjs',
	'scripts/check-mcp-doc-links.test.mjs',
	'scripts/check-mcp-docs.test.mjs',
	'scripts/check-mcp-docs-experience.test.mjs',
	'fluentcart-mcp/compatibility-support.json',
	'AGENTS.md',
	'CLAUDE.md',
	'web-docs/content/docs/fluentcart-mcp/**',
	'web-docs/app/(home)/fluentcart-mcp/**',
	'web-docs/content/blog/fluentcart-mcp-vs-official-mcp.mdx',
]

function assertDocsCiWorkflowContract(workflow) {
	assert.match(workflow, /node scripts\/check-mcp-docs\.mjs/)
	assert.match(workflow, /node scripts\/check-mcp-doc-links\.mjs/)
	assert.match(
		workflow,
		/node --test scripts\/check-mcp-docs\.test\.mjs scripts\/check-mcp-docs-experience\.test\.mjs/,
	)
	assert.match(workflow, /node --test scripts\/check-mcp-doc-links\.test\.mjs/)

	for (const event of ['push', 'pull_request']) {
		const paths = eventPaths(workflow, event)

		for (const path of requiredDocsCiPaths) {
			assert.ok(paths.has(path), `Docs CI ${event} paths does not watch ${path}`)
		}
	}
}

function eventPaths(workflow, event) {
	const { block: eventBlock } = workflowEventBlock(workflow, event)
	const pathsBlock = eventBlock.match(/^    paths:\n((?:      - .+\n)*)/m)?.[1]
	assert.ok(pathsBlock, `Docs CI ${event} trigger has no paths list`)

	return new Set(
		[...pathsBlock.matchAll(/^      - ['\"]?([^'"\n]+)['\"]?$/gm)].map(([, path]) => path),
	)
}

function workflowEventBlock(workflow, event) {
	const eventStart = workflow.indexOf(`  ${event}:\n`)
	assert.notEqual(eventStart, -1, `Docs CI does not define the ${event} trigger`)
	const followingWorkflow = workflow.slice(eventStart + 1)
	const nextEventOffset = followingWorkflow.search(/\n  [^\s]/)
	const nextSectionOffset = followingWorkflow.search(/\n\n[^\s]/)
	const eventEnd = eventStart + 1 + Math.min(
		...[nextEventOffset, nextSectionOffset].filter((offset) => offset !== -1),
		followingWorkflow.length,
	)

	return { start: eventStart, end: eventEnd, block: workflow.slice(eventStart, eventEnd) }
}

function removePathFromEvent(workflow, event, path) {
	const { start, end, block } = workflowEventBlock(workflow, event)

	return `${workflow.slice(0, start)}${block.replace(`      - '${path}'\n`, '')}${workflow.slice(end)}`
}

describe('Docs CI workflow contract', () => {
	it('Docs CI runs every MCP documentation gate', () => {
		const workflow = read('.github/workflows/docs-ci.yml')

		assertDocsCiWorkflowContract(workflow)
	})

	it('fails when either trigger loses a required documentation truth input', () => {
		const workflow = read('.github/workflows/docs-ci.yml')
		const missingPushPath = removePathFromEvent(workflow, 'push', 'scripts/mcp-doc-rules.mjs')
		const missingPullRequestPath = removePathFromEvent(
			workflow,
			'pull_request',
			'scripts/mcp-doc-rules.mjs',
		)

		assert.throws(
			() => assertDocsCiWorkflowContract(missingPushPath),
			/push paths does not watch scripts\/mcp-doc-rules\.mjs/,
		)
		assert.throws(
			() => assertDocsCiWorkflowContract(missingPullRequestPath),
			/pull_request paths does not watch scripts\/mcp-doc-rules\.mjs/,
		)
	})
})

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

	it('keeps dedicated-account data boundaries and direct next steps on every beginner route', () => {
		for (const relativePath of Object.values(beginnerPages)) {
			const page = readRequired(relativePath)

			assertBeginnerPrerequisites(page, relativePath)
			assertBeginnerAccountDataBoundary(page, relativePath)

			for (const [label, route] of [
				['Usage', '/docs/fluentcart-mcp/usage'],
				['Advanced configuration', '/docs/fluentcart-mcp/configuration'],
			]) {
				assert.match(
					page,
					new RegExp(`\\[${label}\\]\\(${route.replaceAll('/', '\\/')}\\)`),
					`${relativePath} must link directly to ${label}`,
				)
			}

			for (const anchor of [
				'1-credential-setup-failed',
				'2-the-client-cannot-start-the-server',
				'3-the-store-connection-or-startup-discovery-failed',
			]) {
				assert.match(
					page,
					new RegExp(`/docs/fluentcart-mcp/troubleshooting#${anchor}`),
					`${relativePath} must link directly to troubleshooting stage ${anchor}`,
				)
			}
		}
	})

	it('accepts independent editorial variants and rejects each missing data-boundary concept', () => {
		const validVariants = [
			'Create a dedicated low-privilege WordPress account for FluentCart. Its WordPress permissions determine which store data FluentCart MCP can access. Read-only mode blocks MCP changes. The selected AI client can process returned customer and order data.',
			'Use one low privilege dedicated account for WordPress. The account\'s permissions govern what FluentCart store data it can see. A read only connection disables MCP updates. Your configured AI client can receive returned order and customer data.',
		]
		const incompleteVariants = [
			['dedicated low-privilege account', 'Create a low-privilege WordPress account. Its WordPress permissions determine which store data FluentCart MCP can access. Read-only mode blocks MCP changes. The selected AI client can process returned customer and order data.'],
			['permission-limited readable data', 'Create a dedicated low-privilege WordPress account. The account has settings for FluentCart store data. Read-only mode blocks MCP changes. The selected AI client can process returned customer and order data.'],
			['read-only MCP write block', 'Create a dedicated low-privilege WordPress account. Its WordPress permissions determine which store data FluentCart MCP can access. The selected AI client can process returned customer and order data.'],
			['AI client data visibility', 'Create a dedicated low-privilege WordPress account. Its WordPress permissions determine which store data FluentCart MCP can access. Read-only mode blocks MCP changes. Returned customer and order data stays in the server.'],
		]

		for (const variant of validVariants) {
			assert.doesNotThrow(() => assertBeginnerAccountDataBoundary(variant, 'editorial variant'))
		}
		for (const [concept, variant] of incompleteVariants) {
			assert.throws(
				() => assertBeginnerAccountDataBoundary(variant, 'incomplete editorial variant'),
				new RegExp(concept),
			)
		}
	})

	it('rejects only prescriptive WordPress role and capability instructions', () => {
		for (const allowedVariant of [
			'Do not use an Administrator account for this connection.',
			'For safety, do not use an Administrator account for this connection.',
			'You should not use a Shop Manager role for this connection.',
			'Never assign the Shop Manager role to this account.',
			'Avoid use of an Administrator account for this connection.',
			'We do not recommend an Administrator account for this connection.',
			'This guide does not require the manage_woocommerce capability.',
			'An admin account is not recommended for this connection.',
			'Administrator is not required for this connection.',
			'manage_woocommerce is not prescribed here.',
			'An Administrator account has broad access, which is why this guide warns against it.',
			'The manage_woocommerce capability is mentioned only as an example of excessive access.',
		]) {
			assert.doesNotThrow(() => assertNoUnsupportedWordPressAccessPrescription(allowedVariant, 'allowed variant'))
		}

		const longClauseAdjacentPrescription = 'Use an Administrator account for this connection while this deliberately long clause continues with background about setup choices, local clients, connection details, safe storage, review steps, operational context, onboarding expectations, troubleshooting notes, and several other explanatory details that must never exempt the adjacent role prescription from the guard'
		assert.ok(longClauseAdjacentPrescription.length > 240, 'long-clause fixture must exceed 240 characters')

		for (const [label, rejectedVariant] of [
			['role', 'Use an Administrator account for this connection.'],
			['role', 'Assign the Shop Manager role.'],
			['role', 'Choose an admin account.'],
			['role', 'Set the account to Administrator.'],
			['role', 'Give the user the Shop Manager role.'],
			['role', 'Grant the user an Administrator role.'],
			['role', 'Recommend an Administrator account.'],
			['role', 'Require an Administrator account.'],
			['role', 'Create an Administrator account.'],
			['role', 'Select the Shop Manager role.'],
			['role', 'Sign in as an Administrator.'],
			['role', longClauseAdjacentPrescription],
			['role', 'Do not use a low-privilege account; choose an Administrator account.'],
			['role', 'Do not use a low-privilege account;choose an Administrator account.'],
			['role', 'Do not use an Administrator account.Use the Shop Manager role.'],
			['role', 'Do not use an Administrator account\nChoose the Shop Manager role.'],
			['role', 'Do not use an Administrator account and assign the Shop Manager role.'],
			['role', 'Do not use an Administrator account, but select the Shop Manager role.'],
			['role', 'Do not use an Administrator account; however, choose a Shop Manager role.'],
			['capability', 'Use manage_woocommerce for the account.'],
			['capability', 'Assign manage_woocommerce to the user.'],
			['capability', 'Choose manage_woocommerce for the user.'],
			['capability', 'Set the account to manage_woocommerce.'],
			['capability', 'Give the user manage_woocommerce.'],
			['capability', 'Grant manage_woocommerce to the user.'],
			['capability', 'Recommend manage_woocommerce for the account.'],
			['capability', 'Require manage_woocommerce for the account.'],
			['capability', 'Create a role with manage_woocommerce.'],
			['capability', 'Select manage_woocommerce for the user.'],
			['capability', 'Sign in as a user with manage_woocommerce.'],
		]) {
			assert.throws(
				() => assertNoUnsupportedWordPressAccessPrescription(rejectedVariant, 'rejected variant'),
				new RegExp(`unsupported exact WordPress ${label}|unsupported named WordPress ${label}`),
			)
		}
	})

	it('keeps Claude no-Node and each local client Node and npx guidance distinct', () => {
		const claude = readRequired(beginnerPages['Claude Desktop'])

		assert.match(claude, /no Terminal or separate Node\.js\s+installation is required/i)
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
			assertLocalGuiPathRecovery(page, relativePath)
		}
	})

	it('keeps the Cursor Windows PATH recovery valid JSON', () => {
		const cursor = readRequired(beginnerPages.Cursor)
		const windowsJson = cursor.match(/complete valid example:\n\n```json\n([\s\S]*?)\n```/)?.[1]

		assert.match(cursor, /where npx.*Windows/is)
		assert.match(cursor, /"command": "C:\\\\Program Files\\\\nodejs\\\\npx\.cmd"/)
		assert.match(cursor, /double backslashes|forward slashes/i)
		assert.ok(windowsJson, 'Cursor must show a complete Windows JSON example')
		assert.equal(JSON.parse(windowsJson).mcpServers.fluentcart.command, 'C:\\Program Files\\nodejs\\npx.cmd')
	})

	it('gives Other clients GUI PATH recovery and valid Windows JSON', () => {
		const otherClients = readRequired(beginnerPages['Other clients'])
		const windowsJson = otherClients.match(/complete valid example:\n\n```json\n([\s\S]*?)\n```/)?.[1]

		assert.match(otherClients, /macOS or Linux[\s\S]{0,80}which npx/is)
		assert.match(otherClients, /Windows[\s\S]{0,80}where npx/is)
		assert.match(otherClients, /replace[^.]*client command[^.]*absolute path/i)
		assert.match(otherClients, /double[^.]*backslashes|forward slashes/i)
		assert.ok(windowsJson, 'Other clients must show a complete Windows JSON example')
		assert.equal(JSON.parse(windowsJson).mcpServers.fluentcart.command, 'C:\\Program Files\\nodejs\\npx.cmd')
	})

})

describe('advanced client boundaries', () => {
	it('keeps the ChatGPT web tunnel separate from local ChatGPT and Codex STDIO setup', () => {
		expectExisting(chatgptWebPage)
		const chatgptWeb = readRequired(chatgptWebPage)
		const chatgptDesktop = readRequired(beginnerPages['ChatGPT Desktop'])

		assert.match(chatgptWeb, /Secure MCP Tunnel/i)
		assert.match(chatgptWeb, /does not read[^.]*\.codex\/config\.toml/i)
		assert.doesNotMatch(chatgptWeb, /Settings → MCP servers → Add server|codex mcp add/i)
		assert.match(chatgptDesktop, /Settings → MCP servers → Add server/i)
		assert.doesNotMatch(chatgptDesktop, /Secure MCP Tunnel/i)
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

	it('keeps generated private-HTTP keys available to Docker and Compose', () => {
		const deployment = read('web-docs/content/docs/fluentcart-mcp/deployment.mdx')
		assert.match(deployment, /FLUENTCART_HTTP_KEY="\$\(openssl rand -hex 32\)"/)
		assert.match(deployment, /FLUENTCART_MCP_API_KEY=\$FLUENTCART_HTTP_KEY/)
		assert.match(deployment, /127\.0\.0\.1:3000:3000/)
	})

	it('demotes TLS verification bypass to a removable local diagnostic', () => {
		const troubleshooting = read('web-docs/content/docs/fluentcart-mcp/troubleshooting.mdx')
		assert.match(troubleshooting, /valid TLS certificate or a trusted local CA/i)
		assert.match(
			troubleshooting,
			/NODE_TLS_REJECT_UNAUTHORIZED[\s\S]{0,400}(?:final temporary local diagnostic|remove it immediately)/i,
		)
		assert.match(
			troubleshooting,
			/remove it immediately[\s\S]{0,240}restart the affected MCP server or client/i,
		)
	})
})

describe('current release parity', () => {
	it('keeps current-facing package versions out of non-historical content', () => {
		const stalePackageVersion =
			/(?:^|\n)#{1,6}\s+v?2\.0\.0\b|\b(?:new in|fluentcart-mcp@|release(?:d|s)?(?:\s+version)?|package\s+version|current\s+version)\s+[`'"]?v?2\.0\.0\b/i
		for (const path of currentFacingFiles()) {
			if (path.endsWith('/changelog.mdx')) continue
			assert.doesNotMatch(
				readFileSync(path, 'utf8'),
				stalePackageVersion,
				`${path} presents a stale release version`,
			)
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
	it('wraps comparison-blog tags on narrow viewports', () => {
		const blogPostPage = read('web-docs/app/(home)/blog/[slug]/page.tsx')

		assert.match(
			blogPostPage,
			/<div className="flex flex-wrap items-center gap-1\.5 mt-4">/,
		)
	})

	it('labels local setup as advanced and uses plain WordPress account language', () => {
		const marketing = read('web-docs/app/(home)/fluentcart-mcp/page.tsx')

		assert.match(marketing, /Advanced local setup/i)
		assert.match(marketing, /Node\.js 24 or newer/i)
		assert.match(
			marketing,
			/npx -y[\s\S]{0,200}does not install[^.]*globally/i,
		)
		assert.match(marketing, /WordPress account whose permissions the server uses/i)
		assert.doesNotMatch(marketing, /\bprincipal\b/i)
	})

	it('labels the coupon prompt as reversible-mode-only', () => {
		const marketing = read('web-docs/app/(home)/fluentcart-mcp/page.tsx')

		assert.match(marketing, /Create a coupon for the weekend sale \(reversible mode only\)/i)
	})

	it('does not turn source definitions into unqualified marketing counts', () => {
		const layoutPath = 'web-docs/app/(home)/fluentcart-mcp/layout.tsx'
		const layout = read(layoutPath)
		const descriptionPattern = /description:\s*"Open-source MCP server for reading and safely administering a FluentCart store from supported AI clients\."/
		assert.match(layout, descriptionPattern)
		assert.doesNotMatch(
			layout.replaceAll('reading and safely', 'reading  and safely'),
			descriptionPattern,
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
