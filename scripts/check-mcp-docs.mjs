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

import { readFileSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const REPO_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
const DOCS_ROOT = join(REPO_ROOT, 'web-docs', 'content', 'docs', 'fluentcart-mcp')

export const SCANNED_FILES = [
	join(REPO_ROOT, 'README.md'),
	join(REPO_ROOT, 'fluentcart-mcp', 'README.md'),
	join(DOCS_ROOT, 'index.mdx'),
	join(DOCS_ROOT, 'setup.mdx'),
	join(DOCS_ROOT, 'usage.mdx'),
	join(DOCS_ROOT, 'tools.mdx'),
	join(DOCS_ROOT, 'deployment.mdx'),
	join(DOCS_ROOT, 'troubleshooting.mdx'),
	join(DOCS_ROOT, 'changelog.mdx'),
]

/** How far either side of a non-loopback bind we look for the bearer-key requirement. */
const KEY_PROXIMITY_LINES = 12

const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '::1']

/**
 * Copy that presents something as usable today. The list connectives matter as much as the verbs:
 * "guarded … plus refunding and subscription cancellation" makes the claim without a verb at all.
 */
const AVAILABILITY_VERB = new RegExp(
	[
		/\b(allows?|enables?|gives?|exposes?|unlocks?|adds?|supports?|permits?|lets)\b/,
		// An imperative claims availability without ever using an availability verb:
		// "Use fluentcart_order_refund to send money back" is an instruction to do the impossible.
		/\b(use|call|invoke|run|trigger)\b/,
		// Past participles are the passive-voice version of the same claim.
		/\b(enabled|exposed|supported|permitted|unlocked)\b/,
		// List connectives make the claim with no verb at all.
		/\b(plus|including|as well as|along with)\b/,
		/\byou can\b|\bable to\b/,
	]
		.map((part) => part.source)
		.join('|'),
	'i',
)

/**
 * Wording that already tells the reader the thing is not usable. Without this the rule would fight
 * the sentences that fix it: "refunding is not available in 2.0.0" names the subject too, and a
 * scanner that cannot tell a correction from a claim gets switched off.
 */
const UNAVAILABLE_MARKER = new RegExp(
	[
		/\b(unavailable|absent|hidden|withheld|cannot|can'?t|never|no longer|not shipped|future release)\b/,
		/\bnot\b[^.]{0,20}\b(available|exposed|shipped|active|usable|supported|possible)\b/,
		/\bno\b[^.]{0,30}\b(exposes?|exposed|exposable)\b/,
		/\b(none|neither|nor)\b[^.]{0,20}\b(exposes?|exposable|exposed|available)\b/,
		/execution:\s*'?none/,
		// A refusal only counts when it is bound to the thing being refused. Exempting any line
		// containing "will not" anywhere would wave through "You can refund an order and it does
		// not require a state directory", which is an overclaim wearing a negation as a disguise.
		/\b(will|would|does|do|is|are|can|could)\s*n[o']?t\b[^.]{0,25}\b(do it|work|happen|expose|appear|apply|refund|cancel)\b/,
	]
		.map((part) => part.source)
		.join('|'),
	'i',
)

/** The two real-money actions, by tool name and by the words the prose uses for them. */
const REAL_MONEY_SUBJECT =
	/fluentcart_order_refund|fluentcart_subscription_cancel|\brefunds?\b|\brefunding\b|subscription cancellation|\bcancellations?\b|\breal-money\b/i

/** Guard inputs that change nothing while no real-money tool is exposed. */
const GUARD_MECHANICS =
	/\bconfirm_token\b|\bdry_run\b|\bidempotency_key\b|FLUENTCART_GUARD_SECRET|FLUENTCART_GUARD_STATE_DIR/

const RESERVED_MARKER =
	/\breserved\b|(changes?|does|do) nothing|nothing at all|has no effect|no effect|no behaviour change|placeholder|inert|\bnothing\b[^.]{0,20}\b(appears?|happens?|changes?|occurs?)\b|\bnot\b[^.]{0,20}\b(used|active|wired|read)\b/i

/**
 * Split a line into clauses. Sentence ends need trailing whitespace to qualify, so `2.0.0` and
 * `fluent-cart.1.3.9.zip` stay intact; a semicolon always separates.
 */
function clauses(line) {
	const parts = line.split(/;|(?<=[\w)`"'])\.\s+/).filter((part) => part.trim() !== '')
	return parts.length > 0 ? parts : [line]
}

/** Blank out JSX/MDX attribute values so a heading cannot be mistaken for an example prompt. */
function withoutAttributeValues(line) {
	return line.replace(/([A-Za-z_][\w-]*)=\s*"[^"]*"/g, '$1=""')
}

/**
 * Each rule is deliberately narrow. A scanner that flags every occurrence of "refund" trains
 * everyone to ignore it, so the patterns target the specific shapes the stale copy actually uses.
 *
 * A rule carrying `appliesWhen` is gated on generated evidence rather than on someone remembering
 * to edit a regex. The two real-money rules below switch themselves off the day the release
 * contract reports an exposable real-money tool, which is the only day they should.
 */
export const RULES = [
	{
		id: 'stale-tool-count',
		message: 'stale tool count; the exposed count depends on write mode and store capabilities',
		test: (line) => /\b279\b/.test(line) || /\b200\+/.test(line) || /\b274\s+tools?\b/i.test(line),
	},
	{
		id: 'stale-token-claim',
		message: 'stale context-size claim; use the measured budgets from release-contract.json',
		test: (line) => /~?\b30K\b/i.test(line) || /~?\b30,000\s+tokens\b/i.test(line),
	},
	{
		id: 'pinned-download-link',
		message: 'hard-coded release version in a download link; link to the latest release instead',
		test: (line) => /releases\/(download|tag)\/[^)\s]*v\d+\.\d+\.\d+/.test(line),
	},
	{
		id: 'pinned-release-command',
		message: 'hard-coded FluentCart MCP tag version; use the package version or a neutral placeholder',
		test: (line) => /fluentcart-mcp\/v\d+\.\d+\.\d+/.test(line),
	},
	{
		id: 'unqualified-full-access',
		message: '"full access" without a capability or write-policy qualification',
		test: (line) =>
			/full access/i.test(line) &&
			!/(read-only|write mode|FLUENTCART_WRITE_MODE|capabilit|policy)/i.test(line),
	},
	{
		id: 'resources-cached',
		message: 'claims reference data or MCP Resources are cached; unsupported by the shared cache tests',
		test: (line) =>
			/(in-memory caching|cached in-memory)/i.test(line) ||
			/\b(resources?|static (reference )?data)\b[^.]{0,60}\b(is|are)\s+cached\b/i.test(line),
	},
	{
		id: 'administrator-required',
		message: 'claims Administrator is required; least-privilege roles are supported',
		test: (line) =>
			/\bAdministrator\b[^.]{0,60}\b(account|role|required|requires|needs)\b/i.test(line) ||
			/\b(requires?|needs?|isn'?t|must be)\b[^.]{0,40}\bAdministrator\b/i.test(line),
	},
	{
		id: 'least-privilege-denied',
		message: 'claims admin capabilities are mandatory; the server works with narrower roles',
		test: (line) =>
			/permission requirements can'?t be lowered/i.test(line) ||
			/\bneeds? admin capabilities\b/i.test(line),
	},
	{
		id: 'endpoint-wide-open',
		message: 'claims the endpoint is reachable without a key; a non-loopback bind now refuses to start',
		test: (line) => /wide open/i.test(line) || /endpoint is (unprotected|open)\b/i.test(line),
	},
	{
		id: 'static-mode',
		message: 'the `static` mode no longer exists; the full registry is `full`',
		test: (line) => /\bstatic\b[^\n]{0,40}\bmode\b/i.test(line) || /\bmode\b[^\n]{0,20}\bstatic\b/i.test(line),
	},
	{
		id: 'wrong-default-mode',
		message: 'dynamic is the default mode; static and curated are not',
		test: (line) => /\b(static|curated)\b[^\n]{0,30}\(default\)/i.test(line) ||
			/\bdefault\b[^\n]{0,30}\b(static|curated)\s+mode\b/i.test(line),
	},
	{
		id: 'refund-available',
		message: 'refund and cancellation are currently unavailable and must not be offered as examples',
		test: (line) =>
			// The quoted branch looks for example prompts, so it reads the line with MDX attribute
			// values removed: `title="No refunds in 2.0.0"` is a heading, not something we are
			// inviting the reader to type, and flagging accurate headings teaches people to ignore
			// the scanner. The unquoted branches below still see the whole line.
			/"[^"]{0,80}\b(refund|cancel)\w*\b[^"]{0,80}"/i.test(withoutAttributeValues(line)) ||
			/\b(issue|process|handle|pull)\s+refunds?\b/i.test(line) ||
			/\brefund an order\b/i.test(line),
	},
	{
		id: 'unavailable-write-presented-as-available',
		selfNegating: true,
		message:
			'refund and subscription cancellation are execution: none in this release, so no write mode exposes them',
		appliesWhen: (truth) => truth.realMoneyExposable === 0,
		test: (line) =>
			REAL_MONEY_SUBJECT.test(line) && AVAILABILITY_VERB.test(line) && !UNAVAILABLE_MARKER.test(line),
	},
	{
		id: 'unavailable-subscription-lifecycle',
		selfNegating: true,
		message: 'subscription pause, resume and reactivate are not shipped tool actions',
		test: (line) =>
			(/fluentcart_subscription_(pause|resume|reactivate)\b/i.test(line) ||
				/"(?:pause|resume|reactivate)\b[^"]*\bsubscription\b/i.test(line) ||
				/\|\s*list,\s*(?:pause|resume|reactivate)\b/i.test(line) ||
				/\b(allows?|enables?|supports?|use|call)\b[^.]{0,50}\b(pause|resume|reactivate)\w*\b[^.]{0,30}\bsubscriptions?\b/i.test(
					line,
				)) &&
			!UNAVAILABLE_MARKER.test(line),
	},
	{
		id: 'guard-mechanics-as-usable',
		selfNegating: true,
		message:
			'guard inputs are reserved while no real-money tool is exposable; document them as reserved, not as steps',
		appliesWhen: (truth) => truth.realMoneyExposable === 0,
		test: (line) =>
			GUARD_MECHANICS.test(line) && !RESERVED_MARKER.test(line) && !UNAVAILABLE_MARKER.test(line),
	},
	{
		id: 'writes-on-by-default',
		message: 'writes are absent unless FLUENTCART_WRITE_MODE is set',
		test: (line) =>
			/\b(create orders|issue refunds|manage your store)\b/i.test(line) &&
			!/(read-only|write mode|FLUENTCART_WRITE_MODE)/i.test(line),
	},
]

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

/**
 * Copy that explicitly denies a claim is the fix, not the defect. Without this, correcting
 * "Administrator required" to "an Administrator account is not required" would keep failing and
 * the only way to pass would be to say nothing at all.
 */
const NEGATED = /\b(not|no longer|never|isn'?t|aren'?t|doesn'?t|don'?t)\s+(a\s+|an\s+|the\s+)?(required|necessary|mandatory|cached|the default|enabled|available)\b/i

/** Only the rules whose evidence gate is currently closed. */
export function activeRules(truth = groundTruth()) {
	return RULES.filter((rule) => !rule.appliesWhen || rule.appliesWhen(truth))
}

function scanFile(path, rules) {
	const lines = readFileSync(path, 'utf8').split('\n')
	const findings = []

	lines.forEach((line, index) => {
		const parts = clauses(line)
		for (const rule of rules) {
			// Rules that bind a negation to their own subject judge the whole line themselves; the
			// marker may legitimately sit in a later sentence than the thing it qualifies.
			//
			// Everything else consults `NEGATED`, clause by clause. Applied to a whole line it is an
			// evasion: an incidental "a state directory is not required" at the end would excuse a
			// claim at the start. Per clause, a negation can only excuse the clause it appears in.
			const matched = rule.selfNegating
				? rule.test(line)
				: parts.some((part) => !NEGATED.test(part) && rule.test(part))
			if (!matched) continue
			findings.push({ rule: rule.id, line: index + 1, text: line.trim(), message: rule.message })
		}
	})

	return [...findings, ...findNonLoopbackExposure(lines)].sort((a, b) => a.line - b.line)
}

export function checkMcpDocs(files = SCANNED_FILES, rules = activeRules()) {
	return files.map((path) => ({ path, findings: scanFile(path, rules) }))
}

/** Numbers the docs may quote, read from the generated contract rather than retyped here. */
export function groundTruth() {
	const contract = JSON.parse(
		readFileSync(join(REPO_ROOT, 'fluentcart-mcp', 'release-contract.json'), 'utf8'),
	)
	const measured = contract.profiles.find((profile) => profile.writeMode === 'disabled' && profile.modes)

	return {
		defaultMode: 'dynamic',
		modes: ['dynamic', 'curated', 'code', 'full'],
		defaultWriteMode: 'disabled',
		sourceDefinitionCount: contract.sourceDefinitionCount,
		defaultExposedCount: contract.writePolicyExposure?.disabled,
		realMoneyExposable: contract.writePolicyExposure?.realMoneyExposable ?? null,
		serializer: contract.serializer,
		tokenizer: contract.tokenizer,
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
