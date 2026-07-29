/**
 * Current-facing documentation claim catalogue.
 *
 * Rules stay narrow: false positives train maintainers to ignore the checker, which is a splendid
 * way to preserve stale documentation indefinitely.
 */

const AVAILABILITY_VERB = new RegExp(
	[
		/\b(allows?|enables?|gives?|exposes?|unlocks?|adds?|supports?|permits?|lets)\b/,
		/\b(use|call|invoke|run|trigger)\b/,
		/\b(enabled|exposed|supported|permitted|unlocked)\b/,
		/\b(plus|including|as well as|along with)\b/,
		/\byou can\b|\bable to\b/,
	]
		.map((part) => part.source)
		.join('|'),
	'i',
)

const UNAVAILABLE_MARKER = new RegExp(
	[
		/\b(unavailable|absent|hidden|withheld|cannot|can'?t|never|no longer|not shipped|future release)\b/,
		/\b(?:is|are|was|were)n'?t\b[^.]{0,20}\b(available|exposed|shipped|active|usable|supported|possible)\b/,
		/\bnot\b[^.]{0,20}\b(available|exposed|shipped|active|usable|supported|possible)\b/,
		/\bnot\b[^.]{0,20}\btools?\b/,
		/\bno\b[^.]{0,30}\b(exposes?|exposed|exposable)\b/,
		/\bno\b[^.]{0,50}\b(enables?|turns? on)\b/,
		/\b(none|neither|nor)\b[^.]{0,20}\b(exposes?|exposable|exposed|available)\b/,
		/execution:\s*'?none/,
		/\b(will|would|does|do|is|are|can|could)\s*n[o']?t\b[^.]{0,25}\b(do it|work|happen|expose|appear|apply|refund|cancel)\b/,
	]
		.map((part) => part.source)
		.join('|'),
	'i',
)

const REAL_MONEY_SUBJECT =
	/fluentcart_order_refund|fluentcart_subscription_cancel|\brefunds?\b|\brefunding\b|subscription cancellation|\bcancellations?\b|\breal-money\b/i
const OFFICIAL_MCP_CONTEXT = /\bofficial\s+(?:FluentCart\s+)?(?:MCP|server)\b/i
const CERTIFICATION_NEGATION = /\b(not|no)\b[^.]{0,80}\b(certified|certification)\b/i
const GUARD_MECHANICS =
	/\bconfirm_token\b|\bdry_run\b|\bidempotency_key\b|FLUENTCART_GUARD_SECRET|FLUENTCART_GUARD_STATE_DIR/
const RESERVED_MARKER =
	/\breserved\b|(changes?|does|do) nothing|nothing at all|has no effect|no effect|no behaviour change|placeholder|inert|\bnothing\b[^.]{0,20}\b(appears?|happens?|changes?|occurs?)\b|\bnot\b[^.]{0,20}\b(used|active|wired|read)\b/i
const NEGATED =
	/\b(not|no longer|never|isn'?t|aren'?t|doesn'?t|don'?t)\s+(a\s+|an\s+|the\s+)?(required|necessary|mandatory|cached|the default|enabled|available)\b/i

const NUMBER_WORDS = new Map([
	['zero', 0],
	['one', 1],
	['two', 2],
	['three', 3],
	['four', 4],
	['five', 5],
	['six', 6],
	['seven', 7],
	['eight', 8],
	['nine', 9],
	['ten', 10],
])

function claimedCount(line, subject) {
	if (new RegExp(`\\b\\d+\\s*[–-]\\s*\\d+\\s+${subject}`, 'i').test(line)) return null
	const match = line.match(new RegExp(`\\b(${[...NUMBER_WORDS.keys()].join('|')}|\\d+)\\s+${subject}`, 'i'))
	if (!match) return null
	return NUMBER_WORDS.get(match[1].toLowerCase()) ?? Number(match[1])
}

function clauses(line) {
	const parts = line.split(/;|(?<=[\w)`"'*_~\]])[.!?]\s+/).filter((part) => part.trim() !== '')
	return parts.length > 0 ? parts : [line]
}

function withoutAttributeValues(line) {
	return line.replace(/([A-Za-z_][\w-]*)=\s*"[^"]*"/g, '$1=""')
}

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
		id: 'stale-dynamic-tool-count',
		message: 'dynamic mode count conflicts with the generated default read-only profile',
		test: (line, truth) => {
			const count = claimedCount(line, '(?:compact\\s+)?meta-tools?')
			return /\bdynamic\b/i.test(line) && count !== null && count !== truth.defaultDynamicToolCount
		},
	},
	{
		id: 'obsolete-protocol',
		message: 'protocol is not in the generated release contract',
		test: (line, truth) => {
			const protocols = [...line.matchAll(/\b(?:MCP\s+)?protocol(?:\s+version)?\s*[`'"]?([\d-]{6,})/gi)].map(
				([, protocol]) => protocol,
			)
			return protocols.some((protocol) => !truth.protocols.includes(protocol))
		},
	},
	{
		id: 'stale-red-gate-count',
		message: 'red-gate count is not the generated automated-client requirement',
		test: (line, truth) => {
			const count = claimedCount(line, '(?:red\\s+)?gates?')
			return (
				count !== null &&
				truth.requiredAutomatedClientCount !== null &&
				/\b(red|certif)/i.test(line) &&
				count !== truth.requiredAutomatedClientCount
			)
		},
	},
	{
		id: 'direct-publication-claim',
		message: 'release truth uses native npm staging, interactive 2FA approval and separate promotion',
		test: (line, truth) =>
			truth.promotion?.npmPublishing === 'trusted-staged' &&
			/\b(tag|push)\b[^.]{0,120}\b(npm\s+publish|publish\s+(?:to\s+)?npm|GitHub Release|Docker)\b/i.test(line),
	},
	{
		id: 'obsolete-npm-release-flow',
		message: 'npm release flow has no next tag or stored npm token',
		test: (line, truth) =>
			truth.promotion?.npmPublishing === 'trusted-staged' &&
			(/\bnpm\b[^.\n]{0,80}\bnext\b/i.test(line) ||
				/\b(?:NPM_PROMOTION_TOKEN|NPM_TOKEN|NODE_AUTH_TOKEN)\b/.test(line)),
	},
	{
		id: 'broad-client-certification',
		message: 'only candidate-bound automated handshakes can certify this release',
		appliesWhen: (truth) => truth.finalCandidate === 'AUTOMATED_CLIENT_CERTIFICATION_REQUIRED',
		test: (line) =>
			!CERTIFICATION_NEGATION.test(line) &&
			/\b(all|every|any)\b[^.]{0,60}\b(MCP\s+)?clients?\b[^.]{0,80}\b(certified|certification|passed)\b/i.test(line),
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
		id: 'claude-extension-node-prerequisite',
		multiline: true,
		message: 'Claude Desktop supplies Node for MCPB extensions; extension users do not install Node',
		negated: (clause) => /\b(?:do|does|need) not\b[^.]{0,60}\binstall Node/i.test(clause),
		test: (line) =>
			/\bClaude Desktop\b[^.]{0,100}\b(extension|MCPB)\b[^.]{0,100}\b(Node(?:\.js)?(?:\s+24\+)?\s+(?:is\s+)?(?:required|prerequisite)|install Node)\b/i.test(
				line,
			) ||
			/\bNode(?:\.js)?(?:\s+24\+)?.{0,180}\b(?:required|prerequisite)\b.{0,140}\bClaude Desktop\b.{0,60}\b(extension|MCPB)\b/i.test(
				line,
			),
	},
	{
		id: 'mcpb-bundles-node-runtime',
		multiline: true,
		message: 'the MCPB contains JavaScript and dependencies, not a Node executable or runtime',
		negated: (clause) =>
			/\b(?:does|do) not\b[^.]{0,80}\b(?:contain|include|bundle|ship)\b[^.]{0,60}\bNode/i.test(clause),
		test: (line) =>
			/\b(MCPB|archive|extension)\b[^.]{0,100}\b(contains?|includes?|bundles?|ships?)\b[^.]{0,60}\bNode(?:\.js)?\s+(runtime|executable|binary)\b/i.test(
				line,
			),
	},
	{
		id: 'chatgpt-static-bearer-auth',
		multiline: true,
		message: 'a FluentCart bearer key is not ChatGPT plugin authentication',
		negated: (clause) =>
			/\b(is not|isn'?t|never present|separate from)\b[^.]{0,100}\bChatGPT\b/i.test(clause),
		test: (line) =>
			/\bChatGPT\b[^.]{0,140}\b(bearer|FLUENTCART_MCP_API_KEY)\b[^.]{0,80}\b(authentication|auth|token|key|setting)\b/i.test(
				line,
			) ||
			/\b(bearer|FLUENTCART_MCP_API_KEY)\b[^.]{0,100}\bChatGPT\b[^.]{0,80}\b(authentication|auth|token|key|setting)\b/i.test(
				line,
			),
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
		test: (line) =>
			/\bstatic\b[^\n]{0,40}\bmode\b/i.test(line) || /\bmode\b[^\n]{0,20}\bstatic\b/i.test(line),
	},
	{
		id: 'wrong-default-mode',
		message: 'dynamic is the default mode; static and curated are not',
		test: (line) =>
			/\b(static|curated)\b[^\n]{0,30}\(default\)/i.test(line) ||
			/\bdefault\b[^\n]{0,30}\b(static|curated)\s+mode\b/i.test(line),
	},
	{
		id: 'unrun-client-certification',
		message: 'only the five automated client handshakes can certify the candidate',
		appliesWhen: (truth) => truth.finalCandidate === 'AUTOMATED_CLIENT_CERTIFICATION_REQUIRED',
		test: (line) =>
			/^\s*works with\b[^.]{0,120}\b(Claude Desktop|Cursor|VS Code|ChatGPT)\b/i.test(line) ||
			/\b(all|every)\b[^.]{0,40}\bnamed clients?\b[^.]{0,30}\b(pass|work|certified)\b/i.test(line),
	},
	{
		id: 'unqualified-client-claim',
		message: 'a setup recipe or transport description is not client certification',
		test: (line) =>
			/\banything that speaks MCP will work\b|\bworks brilliantly\b|\bwill negotiate\b|\bdone\b(?:[.!?]|$)/i.test(
				line,
			),
	},
	{
		id: 'refund-available',
		message: 'refund and cancellation are currently unavailable and must not be offered as examples',
		test: (line) =>
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
			line
				.split('|')
				.some(
					(part) =>
						REAL_MONEY_SUBJECT.test(part) &&
						AVAILABILITY_VERB.test(part) &&
						!UNAVAILABLE_MARKER.test(part) &&
						(!OFFICIAL_MCP_CONTEXT.test(part) ||
							/\bFluentCart MCP\b/i.test(part.replace(OFFICIAL_MCP_CONTEXT, ''))),
				),
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

export function ruleMatchesLine(rule, line, truth = {}) {
	if (rule.selfNegating) return rule.test(line, truth)
	return clauses(line).some(
		(part) => !NEGATED.test(part) && !rule.negated?.(part, truth) && rule.test(part, truth),
	)
}
