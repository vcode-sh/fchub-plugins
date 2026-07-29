// Task 10 release truth and promotion boundaries.
//
// These assertions deliberately cross generated metadata, container entry points and workflows:
// a release promise written in only one of those places is merely an attractive typo.

import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import { buildReleaseTruth, validateReleaseState } from '../../scripts/release-truth.mjs'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const REPO_ROOT = resolve(PACKAGE_ROOT, '..')
const read = (path) => readFileSync(join(REPO_ROOT, path), 'utf8')
const readJson = (path) => JSON.parse(read(path))

const expectedTruth = {
	version: '2.0.1',
	protocols: ['2025-11-25', '2026-07-28'],
	httpProfiles: ['local', 'private'],
	sdk: { server: '2.0.0', node: '2.0.0', express: '2.0.0' },
	conformance: { package: '0.2.0-alpha.10', expectedFailures: [] },
}

const expectedRecipes = [
	[
		'ChatGPT Desktop',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'STDIO',
			distribution: 'local',
			capabilitySource: 'https://learn.chatgpt.com/docs/extend/mcp',
			reason: 'manual configuration is documented, not certified',
		},
	],
	[
		'Codex CLI',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'STDIO',
			distribution: 'local',
			capabilitySource: 'https://learn.chatgpt.com/docs/extend/mcp',
			reason: 'manual configuration is documented, not certified',
		},
	],
	[
		'Codex IDE extension',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'STDIO',
			distribution: 'local',
			capabilitySource: 'https://learn.chatgpt.com/docs/extend/mcp',
			reason: 'manual configuration is documented, not certified',
		},
	],
	[
		'Claude Desktop',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'MCPB/STDIO',
			distribution: 'extension',
			capabilitySource:
				'https://support.claude.com/en/articles/10949351-getting-started-with-local-mcp-servers-on-claude-desktop',
			reason: 'manual configuration is documented, not certified',
		},
	],
	[
		'Cursor',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'STDIO',
			distribution: 'local',
			capabilitySource: 'https://docs.cursor.com/context/model-context-protocol',
			reason: 'manual configuration is documented, not certified',
		},
	],
	[
		'VS Code with GitHub Copilot',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'STDIO',
			distribution: 'local',
			capabilitySource: 'https://code.visualstudio.com/docs/copilot/chat/mcp-servers',
			reason: 'manual configuration is documented, not certified',
		},
	],
	[
		'Windsurf',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'STDIO',
			distribution: 'local',
			capabilitySource: 'https://docs.windsurf.com/windsurf/cascade/mcp',
			reason: 'manual configuration is documented, not certified',
		},
	],
	[
		'ChatGPT web',
		{
			status: 'CONFIGURATION_TARGET',
			transport: 'Secure MCP Tunnel',
			distribution: 'private web',
			capabilitySource: 'https://developers.openai.com/api/docs/guides/secure-mcp-tunnels',
			reason: 'manual configuration is documented, not certified',
		},
	],
]

describe('generated release truth', () => {
	it('is concrete, current and identical in the contract and MCPB manifest', () => {
		const contract = readJson('fluentcart-mcp/release-contract.json')
		const manifest = readJson('fluentcart-mcp/manifest.json')
		const manifestTruth = manifest._meta['sh.vcode.fluentcart-mcp'].release
		assert.deepEqual(
			{
				version: contract.release.version,
				protocols: contract.release.protocols,
				httpProfiles: contract.release.httpProfiles,
				sdk: contract.release.sdk,
				conformance: contract.release.conformance,
			},
			expectedTruth,
		)
		assert.deepEqual(manifestTruth, contract.release)
	})

	it('binds promotion to one redacted capture with immutable recovery values', () => {
		const fixturePath = join(PACKAGE_ROOT, 'tests/fixtures/releases/previous-release-state.json')
		assert.ok(existsSync(fixturePath), 'the explicit previous-release capture is missing')
		const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'))
		const promotion = readJson('fluentcart-mcp/release-contract.json').release.promotion

		assert.equal(fixture.redacted, true)
		assert.match(fixture.npm.previousLatest, /^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/)
		assert.notEqual(fixture.npm.previousLatest, expectedTruth.version)
		assert.equal(fixture.candidate.version, expectedTruth.version)
		assert.equal(fixture.candidate.npmPublished, false)
		assert.equal(fixture.candidate.remoteTagPresent, false)
		for (const [registry, digest] of Object.entries(fixture.docker.previousLatestDigests)) {
			assert.ok(['ghcr.io', 'docker.io'].includes(registry), `unknown registry ${registry}`)
			assert.match(digest, /^sha256:[a-f0-9]{64}$/)
		}
		assert.deepEqual(promotion, {
			npmPublishing: 'trusted-staged',
			npmStageTag: 'latest',
			npmApproval: 'interactive-2fa',
			npmPromotionWrite: false,
			previousLatest: fixture.npm.previousLatest,
			previousDockerDigests: fixture.docker.previousLatestDigests,
		})
	})

	it('separates five automated handshakes from documented configuration recipes', () => {
		const support = readJson('fluentcart-mcp/compatibility-support.json')
		assert.deepEqual(support.releaseEvidence, {
			version: '2.0.1',
			protocols: ['2025-11-25', '2026-07-28'],
			httpProfiles: ['local', 'private'],
			finalCandidate: 'AUTOMATED_CLIENT_CERTIFICATION_REQUIRED',
			namedClients: 'AUTOMATED_FIVE_REQUIRED',
			configurationRecipes: 'DOCUMENTED_NOT_CERTIFIED',
		})
		const truth = buildReleaseTruth(readJson('fluentcart-mcp/package.json'))
		assert.deepEqual(Object.keys(truth.clients.automated), [
			'MCP Inspector',
			'Claude Code',
			'Docker smoke',
		])
		assert.deepEqual(Object.entries(truth.clients.configurationRecipes), expectedRecipes)
		for (const [, recipe] of expectedRecipes) {
			assert.deepEqual(Object.keys(recipe).sort(), [
				'capabilitySource',
				'distribution',
				'reason',
				'status',
				'transport',
			])
		}
		assert.equal(Object.hasOwn(truth.clients, 'configurationTargets'), false)
		assert.equal(truth.evidence.finalCandidate, 'AUTOMATED_CLIENT_CERTIFICATION_REQUIRED')
	})

	it('keeps active protocol consumers within the generated protocol truth', () => {
		const protocols = buildReleaseTruth(readJson('fluentcart-mcp/package.json')).protocols
		const consumers = [
			'fluentcart-mcp/test-tool.sh',
			'fluentcart-mcp/scripts/smoke-mcp-http.mjs',
			'fluentcart-mcp/scripts/benchmark-http-code-mode.mjs',
			'fluentcart-mcp/tests/integration/e2e-http.test.ts',
		]

		for (const path of consumers) {
			const declared = [
				...read(path).matchAll(/["']?protocolVersion["']?\s*:\s*['"]([^'"]+)['"]/g),
			].map(([, protocol]) => protocol)
			assert.ok(declared.length > 0, `${path} declares no MCP protocol`)
			for (const protocol of declared) {
				assert.ok(protocols.includes(protocol), `${path} uses retired MCP protocol ${protocol}`)
			}
		}
	})

	it('rejects missing, mutable, unknown and already-current capture inputs', () => {
		const good = readJson('fluentcart-mcp/tests/fixtures/releases/previous-release-state.json')
		assert.throws(() => validateReleaseState({}, '2.0.1'), /schema version 1/)
		assert.throws(
			() =>
				validateReleaseState({ ...good, npm: { ...good.npm, previousLatest: '2.0.1' } }, '2.0.1'),
			/already equals npm latest/,
		)
		const mutable = structuredClone(good)
		mutable.docker.previousLatestDigests['ghcr.io'] = 'ghcr.io/vcode-sh/fluentcart-mcp:latest'
		assert.throws(() => validateReleaseState(mutable, '2.0.1'), /not an immutable digest/)
		const unknown = structuredClone(good)
		unknown.docker.previousLatestDigests = {
			'ghcr.io': unknown.docker.previousLatestDigests['ghcr.io'],
			'example.invalid': unknown.docker.previousLatestDigests['docker.io'],
		}
		assert.throws(() => validateReleaseState(unknown, '2.0.1'), /unknown Docker registry/)
	})
})

describe('private release container', () => {
	const dockerfile = read('fluentcart-mcp/Dockerfile.release')

	it('uses the private profile and explicit allowlists on the remotely reachable path', () => {
		assert.match(dockerfile, /"--http-profile",\s*"private"/)
		assert.match(dockerfile, /Host and Origin/)
		assert.doesNotMatch(dockerfile, /ENV FLUENTCART_MCP_ALLOWED_/)
	})

	it('keeps runtime artefacts free of the conformance package and legacy SDK', () => {
		for (const inspector of ['inspect-npm-pack.mjs', 'inspect-mcpb.mjs']) {
			const body = read(`fluentcart-mcp/scripts/${inspector}`)
			assert.match(body, /@modelcontextprotocol\/sdk/)
			assert.match(body, /@modelcontextprotocol\/conformance/)
			assert.match(body, /node.*24|>=24/)
		}
	})

	it('labels the image with content identity and reserves OCI revision for committed source', () => {
		const builder = read('fluentcart-mcp/scripts/build-validated-docker-image.mjs')
		assert.match(builder, /sh\.vcode\.fluentcart-mcp\.candidate-content-digest/)
		assert.match(builder, /org\.opencontainers\.image\.revision=\$\{sourceSha\}/)
		assert.match(builder, /sourceSha === null/)
	})
})

describe('staging and promotion workflows', () => {
	const stage = read('.github/workflows/mcp-release.yml')
	const docker = read('.github/workflows/mcp-docker.yml')
	const promote = read('.github/workflows/mcp-promote.yml')

	it('stages npm for interactive latest approval and publishes Docker by immutable version only', () => {
		assert.match(stage, /npm stage publish "\$TARBALL".*--tag latest/)
		assert.doesNotMatch(stage, /npm publish|npm dist-tag/)
		assert.doesNotMatch(stage, /gh release create/)
		assert.doesNotMatch(docker, /:\s*latest|:latest/)
		assert.match(docker, /docker-content-digest|imagetools inspect/)
	})

	it('makes promotion owner-triggered, exact, evidence-bound and build-free', () => {
		assert.match(promote, /workflow_dispatch:/)
		for (const input of ['version', 'source_sha', 'staging_run_id']) {
			assert.match(promote, new RegExp(`${input}:\\n[\\s\\S]*?required: true`))
		}
		assert.match(promote, /run-id:\s*\$\{\{\s*inputs\.staging_run_id\s*\}\}/)
		assert.match(promote, /npm view fluentcart-mcp dist-tags\.latest/)
		assert.match(promote, /imagetools create/)
		assert.match(promote, /gh release create/)
		assert.doesNotMatch(promote, /npm dist-tag|NPM_TOKEN|NODE_AUTH_TOKEN/)
		assert.doesNotMatch(promote, /\bnpm ci\b|\bnpm run build\b|\bdocker build\b/)
	})
})

describe('public recovery contract', () => {
	const docs = [
		read('fluentcart-mcp/README.md'),
		...['setup.mdx', 'deployment.mdx', 'proof.mdx'].map((name) =>
			read(`web-docs/content/docs/fluentcart-mcp/${name}`),
		),
	].join('\n')

	it('documents profiles, principal boundaries, evidence limits and the breaking fallback', () => {
		for (const phrase of [
			'dynamic mode',
			'2025-11-25',
			'2026-07-28',
			'local profile',
			'private profile',
			'one configured WordPress principal',
			'no OAuth',
			'fail-closed',
			'1.5.5',
			'fluentcart-mcp@1',
		]) {
			assert.match(docs.toLowerCase(), new RegExp(phrase.toLowerCase()))
		}
	})

	it('documents staged promotion and safe recovery without pretending versions are reusable', () => {
		for (const pattern of [
			/trusted publishing/,
			/staged publish/,
			/interactive 2fa/,
			/deprecate/,
			/never reuse/,
			/new patch version/,
		]) {
			assert.match(docs.toLowerCase(), pattern)
		}
	})
})

describe('documentation checker structure', () => {
	it('rejects lowercase completion claims even when they omit a full stop', async () => {
		const catalogue = await import(new URL('../../../scripts/mcp-doc-rules.mjs', import.meta.url))
		const rule = catalogue.RULES.find(({ id }) => id === 'unqualified-client-claim')
		for (const claim of ['Download, double-click, done', 'See store data? done', 'done']) {
			assert.equal(catalogue.ruleMatchesLine(rule, claim), true, claim)
		}
		assert.equal(
			catalogue.ruleMatchesLine(rule, 'The setup is done when the client reports connected.'),
			false,
		)
	})

	it('keeps the executable scanner below the production limit while preserving its rule catalogue', async () => {
		const checkerPath = join(REPO_ROOT, 'scripts/check-mcp-docs.mjs')
		assert.ok(readFileSync(checkerPath, 'utf8').split('\n').length <= 280)
		const checker = await import(new URL('../../../scripts/check-mcp-docs.mjs', import.meta.url))
		const catalogue = await import(new URL('../../../scripts/mcp-doc-rules.mjs', import.meta.url))
		assert.ok(catalogue.RULES.length >= 10)
		assert.equal(
			checker.activeRules({
				finalCandidate: 'AUTOMATED_CLIENT_CERTIFICATION_REQUIRED',
				realMoneyExposable: 0,
			}).length,
			catalogue.RULES.length,
		)
		assert.deepEqual(
			checker.checkMcpDocs().flatMap((result) => result.findings),
			[],
		)
	})
})

describe('client-first documentation contracts', () => {
	const packageReadme = read('fluentcart-mcp/README.md')
	const index = read('web-docs/content/docs/fluentcart-mcp/index.mdx')
	const setup = read('web-docs/content/docs/fluentcart-mcp/setup.mdx')
	const deployment = read('web-docs/content/docs/fluentcart-mcp/deployment.mdx')
	const onboarding = [packageReadme, index, setup].join('\n')
	const webSetup = [setup, deployment].join('\n')
	const compactOnboarding = onboarding.replace(/\s+/g, ' ')
	const compactSetup = setup.replace(/\s+/g, ' ')
	const compactDeployment = deployment.replace(/\s+/g, ' ')

	it('keeps the Claude extension runtime separate from the MCPB archive contents', () => {
		assert.match(
			compactOnboarding,
			/Claude Desktop supplies (?:its own |a built-in )?Node(?:\.js)? runtime/i,
		)
		assert.match(compactOnboarding, /MCPB[^.]{0,160}(?:contains|includes)[^.]{0,80}JavaScript/i)
		assert.match(compactOnboarding, /(?:not|does not)[^.]{0,80}(?:Node executable|Node runtime)/i)
		assert.doesNotMatch(
			compactOnboarding,
			/Claude Desktop (?:MCPB )?extension[^.]{0,180}(?:requires? Node|Node(?:\.js)? 24\+ is required|users (?:must|need to|should) install Node)/i,
		)
	})

	it('explains local npx installation behaviour and the shared OpenAI desktop configuration', () => {
		assert.match(compactOnboarding, /npx -y[^.]{0,100}downloads?[^.]{0,60}on demand/i)
		assert.match(
			compactOnboarding,
			/npx -y[^.]{0,140}(?:does not|doesn't)[^.]{0,40}install[^.]{0,30}globally/i,
		)
		assert.match(
			compactSetup,
			/ChatGPT Desktop[^.]{0,120}Codex CLI[^.]{0,120}Codex IDE extension[^.]{0,160}share/i,
		)
		assert.match(compactSetup, /~\/\.codex\/config\.toml/)
		assert.match(compactSetup, /Settings\s*→\s*MCP servers\s*→\s*Add server/)
	})

	it('provides an actionable repository marketplace route for the optional OpenAI plugin', () => {
		assert.match(compactSetup, /codex plugin marketplace add vcode-sh\/fchub-plugins --ref main/)
		assert.match(compactSetup, /codex plugin marketplace list/)
		assert.match(compactSetup, /restart ChatGPT Desktop/i)
		assert.match(
			compactSetup,
			/Plugins Directory[^.]{0,180}fchub-plugins[^.]{0,180}fluentcart-mcp/i,
		)
		assert.match(
			compactSetup,
			/(?:separate from|not listed in)[^.]{0,100}(?:universal )?public directory/i,
		)
	})

	it('represents every generated configuration target with its current client shape', () => {
		for (const client of [
			'ChatGPT Desktop',
			'Codex CLI',
			'Codex IDE extension',
			'Claude Desktop',
			'Cursor',
			'VS Code with GitHub Copilot',
			'Windsurf',
			'ChatGPT web',
		]) {
			assert.match(webSetup, new RegExp(client.replaceAll(' ', '\\s+'), 'i'), client)
		}
		assert.match(setup, /"servers"\s*:\s*\{[\s\S]{0,180}"type"\s*:\s*"stdio"/)
		assert.match(setup, /Cursor[\s\S]{0,500}"mcpServers"/)
		assert.match(setup, /Windsurf[\s\S]{0,500}"mcpServers"/)
		assert.match(compactSetup, /MCPs icon/)
		assert.match(compactSetup, /Devin Settings\s*→\s*Cascade\s*→\s*MCP Servers/)
		assert.match(compactSetup, /~\/\.codeium\/windsurf\/mcp_config\.json/)
		assert.doesNotMatch(compactSetup, /Open MCP Config/)
		assert.doesNotMatch(
			setup,
			/must be in \*\*Agent\*\* mode|Regular Chat (?:mode )?(?:doesn't|does not) use MCP/i,
		)
	})

	it('documents ChatGPT web as a separately authorised Secure MCP Tunnel connection', () => {
		assert.match(compactDeployment, /OpenAI Secure MCP Tunnel/)
		assert.match(compactDeployment, /Developer mode/)
		assert.match(compactDeployment, /Platform tunnel settings/)
		assert.match(compactDeployment, /CONTROL_PLANE_API_KEY/)
		assert.match(compactDeployment, /tunnel-client init/)
		assert.match(compactDeployment, /--mcp-command "npx -y fluentcart-mcp"/)
		assert.match(compactDeployment, /tunnel-client doctor/)
		assert.match(compactDeployment, /tunnel-client run/)
		assert.match(compactDeployment, /ChatGPT Plugins/)
		assert.match(compactDeployment, /Tunnel under Connection/)
		assert.match(
			compactDeployment,
			/(?:separate|different)[^.]{0,100}(?:permission|authori[sz]ation)/i,
		)
		assert.match(
			compactDeployment,
			/FLUENTCART_MCP_API_KEY[^.]{0,160}(?:is not|isn't)[^.]{0,80}ChatGPT[^.]{0,80}auth/i,
		)
		assert.match(
			compactDeployment,
			/public[^.]{0,100}(?:directory|submission)[^.]{0,160}FluentCart[^.]{0,80}authori[sz]ation/i,
		)
	})
})
