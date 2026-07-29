#!/usr/bin/env node
/**
 * Scan current-facing FluentCart MCP documentation for claims the code no longer supports.
 *
 * Only the pages a reader lands on today are scanned. Files under `_changelog/` are deliberately
 * excluded: a changelog entry records what was true for the release it describes, and rewriting
 * it to match today would be falsifying history rather than fixing a stale claim.
 *
 * Ground truth comes from `fluentcart-mcp/release-contract.json` where a number is involved, so
 * this scanner cannot drift from the measurement that produced it.
 */

import { existsSync, readFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { CURRENT_FACING_MARKETING_FILES, RULES, ruleMatchesLine } from './mcp-doc-rules.mjs'

const REPO_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))

function trackedFilesAt(repoRoot) {
	try {
		return execFileSync('git', ['ls-files'], { cwd: repoRoot, encoding: 'utf8' })
			.split('\n')
			.filter(Boolean)
	} catch {
		return []
	}
}

export function currentFacingFiles({
	repoRoot = REPO_ROOT,
	trackedFiles = trackedFilesAt(repoRoot),
	exists = existsSync,
} = {}) {
	const currentMcpDocs = trackedFiles
		.filter((path) => path.startsWith('web-docs/content/docs/fluentcart-mcp/'))
		.filter((path) => path.endsWith('.mdx'))
		.filter((path) => !path.includes('/_changelog/'))
		.map((path) => join(repoRoot, path))

	return [
		join(repoRoot, 'README.md'),
		join(repoRoot, 'fluentcart-mcp', 'README.md'),
		...trackedFiles.filter((path) => /(^|\/)AGENTS\.md$/.test(path)).map((path) => join(repoRoot, path)),
		join(repoRoot, 'CLAUDE.md'),
		join(repoRoot, 'fluentcart-mcp', 'CLAUDE.md'),
		...CURRENT_FACING_MARKETING_FILES.map((path) => join(repoRoot, path)),
		...currentMcpDocs,
	].filter((path, index, paths) => exists(path) && paths.indexOf(path) === index)
}

export const SCANNED_FILES = currentFacingFiles()

/** How far either side of a non-loopback bind we look for the bearer-key requirement. */
const KEY_PROXIMITY_LINES = 12

const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '::1']

/**
 * A non-loopback bind is only acceptable beside the bearer key that makes it safe, so this rule
 * needs the surrounding lines rather than one line in isolation.
 */
function findNonLoopbackExposure(lines) {
	const findings = []

	lines.forEach((line, index) => {
		const bind = /0\.0\.0\.0/.test(line) || /--host[= ]+([^\s'"\\]+)/.test(line)
		if (!bind) return

		const host = line.match(/--host[= ]+([^\s'"\\]+)/)?.[1]
		if (host && LOOPBACK_HOSTS.includes(host)) return

		const from = Math.max(0, index - KEY_PROXIMITY_LINES)
		const window = lines.slice(from, index + KEY_PROXIMITY_LINES + 1).join('\n')
		if (/FLUENTCART_MCP_API_KEY/.test(window)) return

		findings.push({
			rule: 'non-loopback-without-key',
			line: index + 1,
			text: line.trim(),
			message: 'non-loopback exposure without a bearer key requirement nearby',
		})
	})

	return findings
}

/** Only the rules whose evidence gate is currently closed. */
export function activeRules(truth = groundTruth()) {
	return RULES.filter((rule) => !rule.appliesWhen || rule.appliesWhen(truth))
}

function scanFile(path, rules, truth) {
	const lines = readFileSync(path, 'utf8').split('\n')
	const findings = []
	const wrappedBlocks = []
	let block = null

	const flushBlock = () => {
		if (!block) return
		wrappedBlocks.push({ ...block, text: block.text.join(' ') })
		block = null
	}

	lines.forEach((line, index) => {
		const text = line.trim()
		if (text === '' || text.startsWith('|')) {
			flushBlock()
			if (text.startsWith('|')) wrappedBlocks.push({ line: index + 1, text })
			return
		}
		if (!block) block = { line: index + 1, text: [] }
		block.text.push(text)
	})
	flushBlock()

	lines.forEach((line, index) => {
		for (const rule of rules.filter(({ multiline }) => !multiline)) {
			if (!ruleMatchesLine(rule, line, truth)) continue
			findings.push({ rule: rule.id, line: index + 1, text: line.trim(), message: rule.message })
		}
	})

	for (const { line, text } of wrappedBlocks) {
		for (const rule of rules.filter(({ multiline }) => multiline)) {
			if (!ruleMatchesLine(rule, text, truth)) continue
			findings.push({ rule: rule.id, line, text, message: rule.message })
		}
	}

	return [...findings, ...findNonLoopbackExposure(lines)].sort((a, b) => a.line - b.line)
}

export function checkMcpDocs(files = SCANNED_FILES, rules = activeRules(), truth = groundTruth()) {
	return files.map((path) => ({ path, findings: scanFile(path, rules, truth) }))
}

/** Numbers the docs may quote, read from the generated contract rather than retyped here. */
export function groundTruth() {
	const contract = JSON.parse(
		readFileSync(join(REPO_ROOT, 'fluentcart-mcp', 'release-contract.json'), 'utf8'),
	)
	const manifest = JSON.parse(readFileSync(join(REPO_ROOT, 'fluentcart-mcp', 'manifest.json'), 'utf8'))
	const compatibility = JSON.parse(
		readFileSync(join(REPO_ROOT, 'fluentcart-mcp', 'compatibility-support.json'), 'utf8'),
	)
	const packageJson = JSON.parse(readFileSync(join(REPO_ROOT, 'fluentcart-mcp', 'package.json'), 'utf8'))
	if (!Array.isArray(manifest.tools)) throw new Error('manifest has no generated tool catalogue')
	const measured = contract.profiles.find((profile) => profile.writeMode === 'disabled' && profile.modes)
	const release = contract.release ?? manifest._meta?.['sh.vcode.fluentcart-mcp']?.release ?? {}
	const namedClients = compatibility.releaseEvidence?.namedClients ?? ''
	const clientCount = namedClients.match(/(?:AUTOMATED_)?(ZERO|ONE|TWO|THREE|FOUR|FIVE|SIX|SEVEN|EIGHT|NINE|TEN|\d+)_REQUIRED/)
	const words = { ZERO: 0, ONE: 1, TWO: 2, THREE: 3, FOUR: 4, FIVE: 5, SIX: 6, SEVEN: 7, EIGHT: 8, NINE: 9, TEN: 10 }

	return {
		packageName: packageJson.name,
		packageVersion: packageJson.version,
		defaultMode: Object.keys(measured?.modes ?? {})[0] ?? null,
		modes: Object.keys(measured?.modes ?? {}),
		defaultWriteMode: measured?.writeMode ?? null,
		defaultDynamicToolCount: measured?.modes?.dynamic?.toolCount ?? null,
		profileToolCounts: Object.fromEntries(
			Object.entries(measured?.modes ?? {}).map(([mode, profile]) => [mode, profile.toolCount]),
		),
		sourceDefinitionCount: contract.sourceDefinitionCount,
		defaultExposedCount: contract.writePolicyExposure?.disabled,
		realMoneyExposable: manifest.tools.filter(({ name }) =>
			['fluentcart_order_refund', 'fluentcart_subscription_cancel'].includes(name),
		).length,
		serializer: contract.serializer,
		tokenizer: contract.tokenizer,
		protocols: release.protocols ?? [],
		httpProfiles: release.httpProfiles ?? [],
		finalCandidate: release.evidence?.finalCandidate ?? compatibility.releaseEvidence?.finalCandidate ?? null,
		requiredAutomatedClientCount: clientCount ? (words[clientCount[1]] ?? Number(clientCount[1])) : null,
		promotion: release.promotion ?? null,
		budgets: measured?.modes ?? null,
	}
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
	const results = checkMcpDocs()
	const total = results.reduce((sum, result) => sum + result.findings.length, 0)

	for (const { path, findings } of results) {
		if (findings.length === 0) continue
		process.stdout.write(`\n${relative(REPO_ROOT, path)}\n`)
		for (const finding of findings) {
			const text = finding.text.length > 110 ? `${finding.text.slice(0, 110)}…` : finding.text
			process.stdout.write(`  ${finding.line}: [${finding.rule}] ${finding.message}\n      ${text}\n`)
		}
	}

	if (total === 0) {
		process.stdout.write('All current-facing FluentCart MCP documentation matches the release contract.\n')
		process.exit(0)
	}
	process.stdout.write(`\n${total} stale claim(s) across ${results.filter((r) => r.findings.length).length} file(s).\n`)
	process.exit(1)
}
