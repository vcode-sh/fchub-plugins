# FluentCart MCP Simple-First Documentation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give complete beginners one obvious, safe, client-specific path to a verified FluentCart
connection while preserving a complete advanced reference and correcting every current-facing
documentation inconsistency.

**Architecture:** Replace the shared hidden-tab journey with stable flat client routes. Keep
`index.mdx` and `setup.mdx` as concise choosers, move operational material into explicit advanced
pages, and enforce the boundary with dynamically discovered documentation truth tests. Current
marketing, blog, README, agent guidance, generated release truth, and CI remain one audited surface.

**Tech Stack:** Fumadocs MDX, Next.js, TypeScript, Node.js test runner, Biome, GitHub Actions.

## Global Constraints

- All code, tests, documentation, comments, and commit messages are English.
- Beginner pages define technical terms only when the reader encounters them.
- Claude Desktop MCPB users do not install Node.js separately.
- Local `npx` clients require Node.js 24 or newer and no global package installation.
- ChatGPT Desktop/Codex local configuration remains separate from ChatGPT web Secure MCP Tunnel.
- The default remains read-only; refunds, subscription cancellation, deletion, bulk operations,
  order-status changes, marking an order paid, and other money-moving operations remain unavailable.
- Preserve advanced configuration, security, deployment, evidence, support, and release material.
- Do not rewrite historical files under `web-docs/content/docs/fluentcart-mcp/_changelog/`.
- Do not change FluentCart MCP runtime behaviour.
- Source definitions, measured profiles, and client-visible tools remain distinct counts.
- Current release truth comes from `fluentcart-mcp/release-contract.json`,
  `fluentcart-mcp/manifest.json`, `fluentcart-mcp/compatibility-support.json`,
  `fluentcart-mcp/package.json`, and `web-docs/lib/versions.json`.

---

### Task 1: Expand the current-facing documentation truth boundary

**Files:**
- Modify: `scripts/check-mcp-docs.mjs`
- Modify: `scripts/check-mcp-docs.test.mjs`
- Modify: `scripts/mcp-doc-rules.mjs`
- Create: `scripts/check-mcp-docs-experience.test.mjs`

**Interfaces:**
- Consumes: tracked repository files and generated FluentCart MCP truth files.
- Produces: `currentFacingFiles()` with dynamic MDX discovery and focused beginner/cross-surface
  contract tests.

- [ ] **Step 1: Write failing dynamic-discovery tests**

Add fixtures proving that `currentFacingFiles()`:

```js
for (const name of [
  'web-docs/content/docs/fluentcart-mcp/index.mdx',
  'web-docs/content/docs/fluentcart-mcp/proof.mdx',
  'web-docs/content/docs/fluentcart-mcp/new-client.mdx',
  'web-docs/content/docs/fluentcart-mcp/_changelog/2026-07.mdx',
]) {
  // create the fixture
}

assert.ok(relativePaths.includes('web-docs/content/docs/fluentcart-mcp/proof.mdx'))
assert.ok(relativePaths.includes('web-docs/content/docs/fluentcart-mcp/new-client.mdx'))
assert.ok(!relativePaths.includes('web-docs/content/docs/fluentcart-mcp/_changelog/2026-07.mdx'))
```

Require coverage for the public landing layout, landing page, shared resource links, root/package
READMEs, tracked `AGENTS.md`, optional `CLAUDE.md`, and the comparison blog.

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
node --test --test-name-pattern="discovers every current FluentCart MCP page" scripts/check-mcp-docs.test.mjs
```

Expected: FAIL because `proof.mdx` and arbitrary new MDX pages are not discovered by the fixed list.

- [ ] **Step 3: Implement dynamic discovery**

Replace the fixed FluentCart MCP page-name array with tracked-file discovery:

```js
const currentMcpDocs = trackedFiles
  .filter((path) => path.startsWith('web-docs/content/docs/fluentcart-mcp/'))
  .filter((path) => path.endsWith('.mdx'))
  .filter((path) => !path.includes('/_changelog/'))
  .map((path) => join(repoRoot, path))
```

Add the current-facing marketing/layout files explicitly because they are not MDX documentation
pages.

- [ ] **Step 4: Add failing experience and truth tests**

Create `scripts/check-mcp-docs-experience.test.mjs` with named groups:

```js
describe('beginner client journeys', () => {})
describe('safe diagnostics', () => {})
describe('current release parity', () => {})
describe('usage policy examples', () => {})
describe('marketing and blog truth', () => {})
```

Tests must initially fail against the old structure by requiring:

- distinct existing client pages and direct chooser links;
- the common beginner recipe headings;
- one consistent dashboard verification prompt;
- Claude no-Node and local-client Node/no-global-install guidance;
- no credential-bearing `curl -u "username:application_password"`;
- no `jsonlint.com` recommendation without redaction;
- no unqualified source-definition count in marketing metadata;
- no current-facing `2.0.0` outside historical changelog files;
- unavailable file upload and reversible tax-settings truth;
- no blanket “every list tool supports page/per_page” claim;
- compatibility truth that does not claim 1.3.9 tool support.

- [ ] **Step 5: Run the new tests and verify RED**

Run:

```bash
node --test scripts/check-mcp-docs-experience.test.mjs
```

Expected: FAIL with the old shared-tab structure, unsafe diagnostic command, stale version text,
marketing count, and content-policy contradictions.

- [ ] **Step 6: Make the dynamic-discovery tests GREEN**

Run:

```bash
node --test scripts/check-mcp-docs.test.mjs
node scripts/check-mcp-docs.mjs
```

Expected: all existing scanner tests pass and current source produces no stale-claim findings.

- [ ] **Step 7: Commit**

```bash
git add scripts/check-mcp-docs.mjs scripts/check-mcp-docs.test.mjs \
  scripts/check-mcp-docs-experience.test.mjs scripts/mcp-doc-rules.mjs
git commit -m "Expand MCP documentation truth gates"
```

---

### Task 2: Build the simple client chooser and standalone beginner journeys

**Files:**
- Modify: `web-docs/content/docs/fluentcart-mcp/index.mdx`
- Modify: `web-docs/content/docs/fluentcart-mcp/setup.mdx`
- Create: `web-docs/content/docs/fluentcart-mcp/chatgpt-desktop.mdx`
- Create: `web-docs/content/docs/fluentcart-mcp/claude-desktop.mdx`
- Create: `web-docs/content/docs/fluentcart-mcp/cursor.mdx`
- Create: `web-docs/content/docs/fluentcart-mcp/other-clients.mdx`
- Modify: `web-docs/content/docs/fluentcart-mcp/meta.json`
- Test: `scripts/check-mcp-docs-experience.test.mjs`

**Interfaces:**
- Consumes: the beginner recipe contract from the design and existing tested client commands.
- Produces: stable, directly linkable client pages with one-click selection from the landing page.

- [ ] **Step 1: Confirm beginner-journey tests are RED**

Run:

```bash
node --test --test-name-pattern="beginner client journeys" scripts/check-mcp-docs-experience.test.mjs
```

Expected: FAIL because the standalone pages and direct chooser links do not exist.

- [ ] **Step 2: Rewrite the landing page as one decision**

Keep only:

- one plain-language product sentence;
- read-only and unavailable high-impact operations;
- direct cards for Claude Desktop, ChatGPT Desktop, Cursor, and Other clients;
- a separate advanced ChatGPT web link;
- three safe example questions;
- links to usage and advanced reference after the chooser.

Use these exact route targets:

```text
/docs/fluentcart-mcp/claude-desktop
/docs/fluentcart-mcp/chatgpt-desktop
/docs/fluentcart-mcp/cursor
/docs/fluentcart-mcp/other-clients
/docs/fluentcart-mcp/chatgpt-web
```

- [ ] **Step 3: Turn `setup.mdx` into the compatibility chooser**

Preserve `/docs/fluentcart-mcp/setup` for existing links. Explain that the reader chooses one app,
then link to the same standalone pages. Do not duplicate the advanced modes, multiple-store, Docker,
or Secure MCP Tunnel instructions here.

- [ ] **Step 4: Create the Claude Desktop beginner page**

Required visible sections:

```markdown
## What you need
## Create safe store access
## Install the Claude extension
## Confirm it works
## If it does not work
## Optional next steps
```

Use the existing `<McpbDownload />` component, exact current settings path, three credential fields,
write mode disabled, and the dashboard verification prompt. State plainly that no Terminal or
separate Node installation is required.

- [ ] **Step 5: Create the ChatGPT Desktop beginner page**

Repeat the required WordPress preparation, then explain Terminal and Node.js at first use. Use:

```bash
node --version
npx -y fluentcart-mcp setup
```

Then document the tested STDIO values:

```text
Name: fluentcart
Command: npx
Arguments: -y fluentcart-mcp
```

Explain that ChatGPT Desktop and Codex share the local configuration. Keep the optional repository
marketplace out of the beginner steps.

- [ ] **Step 6: Create the Cursor beginner page**

Repeat preparation and setup-wizard steps, then include only the global `mcp.json` entry:

```json
{
  "mcpServers": {
    "fluentcart": {
      "command": "npx",
      "args": ["-y", "fluentcart-mcp"]
    }
  }
}
```

Include the GUI PATH recovery: use `which npx` on macOS/Linux or `where npx` on Windows and replace
the command with the returned absolute path if Cursor reports command-not-found.

- [ ] **Step 7: Create the other-client page**

Give concise standalone recipes for Claude Code, Codex CLI/IDE, VS Code with GitHub Copilot, and
Windsurf. Each recipe includes the setup wizard prerequisite and dashboard verification prompt.
Define Docker and ChatGPT web as advanced routes, not local clients.

- [ ] **Step 8: Update sidebar metadata**

Use:

```json
{
  "title": "FluentCart MCP",
  "root": true,
  "description": "Connect your AI app to FluentCart.",
  "pages": [
    "---Getting Started---",
    "index",
    "setup",
    "claude-desktop",
    "chatgpt-desktop",
    "cursor",
    "other-clients",
    "---Use---",
    "usage",
    "---Advanced---",
    "configuration",
    "tools",
    "chatgpt-web",
    "deployment",
    "---Evidence & Support---",
    "proof",
    "troubleshooting",
    "---Releases---",
    "changelog"
  ]
}
```

- [ ] **Step 9: Run the beginner tests and verify GREEN**

Run:

```bash
node --test --test-name-pattern="beginner client journeys" scripts/check-mcp-docs-experience.test.mjs
node scripts/check-mcp-docs.mjs
```

Expected: distinct routes, required sections, runtime guidance, verification prompt, and truth scan
all pass.

- [ ] **Step 10: Commit**

```bash
git add web-docs/content/docs/fluentcart-mcp scripts/check-mcp-docs-experience.test.mjs
git commit -m "Create simple MCP client guides"
```

---

### Task 3: Preserve and repair the advanced operator documentation

**Files:**
- Create: `web-docs/content/docs/fluentcart-mcp/configuration.mdx`
- Create: `web-docs/content/docs/fluentcart-mcp/chatgpt-web.mdx`
- Modify: `web-docs/content/docs/fluentcart-mcp/deployment.mdx`
- Modify: `web-docs/content/docs/fluentcart-mcp/troubleshooting.mdx`
- Modify: `AGENTS.md`
- Test: `scripts/check-mcp-docs-experience.test.mjs`

**Interfaces:**
- Consumes: advanced material removed from the old shared setup/deployment pages.
- Produces: searchable advanced configuration, distinct ChatGPT web, safe private deployment, and
  stage-based troubleshooting.

- [ ] **Step 1: Confirm safe-diagnostics tests are RED**

Run:

```bash
node --test --test-name-pattern="safe diagnostics" scripts/check-mcp-docs-experience.test.mjs
```

Expected: FAIL on credential-bearing curl, external JSON validator, discarded Docker bearer key, and
normalised TLS bypass guidance.

- [ ] **Step 2: Create advanced configuration**

Move and preserve:

- credential file and environment variable precedence;
- credential rotation and restart behaviour;
- absolute `npx` path recovery;
- `dynamic`, `curated`, `code`, and `full` presentation modes;
- disabled and reversible write modes;
- exact unavailable-operation boundary;
- Native Abilities separate principal and fail-closed behaviour;
- multiple-store entries;
- optional OpenAI repository marketplace.

Use `fluentcart-reader` in examples and explain WordPress permission scope separately from write mode.

- [ ] **Step 3: Create the ChatGPT web page**

Move the current Secure MCP Tunnel sequence without weakening:

- outbound-only tunnel;
- OpenAI control-plane key;
- local FluentCart credential setup;
- workspace permissions;
- initialisation, doctor, run, and ChatGPT selection;
- dashboard verification;
- explicit separation from `FLUENTCART_MCP_API_KEY`.

- [ ] **Step 4: Repair Docker and private HTTP examples**

Use a retained shell variable:

```bash
FLUENTCART_HTTP_KEY="$(openssl rand -hex 32)"
```

Explain that the value must be stored in the deployment secret manager and configured in the client.
For same-host reverse-proxy/tunnel examples use:

```text
127.0.0.1:3000:3000
```

Retain bearer-key, Host, Origin, static-principal, Dokploy, Cloudflare Tunnel, Compose, health, and
HTTP client details. Keep release recovery in the monorepo's tracked `AGENTS.md`; the similarly
named developer manual belongs to the sibling playground repository and is outside this plan.

- [ ] **Step 5: Rewrite troubleshooting around failure stages**

Lead with:

```bash
npx -y fluentcart-mcp setup
```

If an advanced curl test remains, use:

```bash
curl --user "fluentcart-reader" https://your-store.com/wp-json/fluent-cart/v2/app/init
```

Explain that curl prompts for the password. Replace the external JSON-validator recommendation with
a local validation command:

```bash
python3 -m json.tool ~/.cursor/mcp.json >/dev/null
```

Use stable explicit headings for every common issue. Replace blanket pagination advice with
tool-schema-specific guidance. Lead certificate troubleshooting with valid TLS or a trusted local
CA; keep any verification bypass only as a final temporary local diagnostic with removal steps.

- [ ] **Step 6: Run safe-diagnostics and scanner tests**

Run:

```bash
node --test --test-name-pattern="safe diagnostics" scripts/check-mcp-docs-experience.test.mjs
node scripts/check-mcp-docs.mjs
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add web-docs/content/docs/fluentcart-mcp/configuration.mdx \
  web-docs/content/docs/fluentcart-mcp/chatgpt-web.mdx \
  web-docs/content/docs/fluentcart-mcp/deployment.mdx \
  web-docs/content/docs/fluentcart-mcp/troubleshooting.mdx \
  AGENTS.md scripts/check-mcp-docs-experience.test.mjs
git commit -m "Separate advanced MCP guidance"
```

---

### Task 4: Correct usage, tool, compatibility, blog, and marketing truth

**Files:**
- Modify: `web-docs/content/docs/fluentcart-mcp/usage.mdx`
- Modify: `web-docs/content/docs/fluentcart-mcp/tools.mdx`
- Modify: `fluentcart-mcp/compatibility-support.json`
- Modify: `web-docs/content/blog/fluentcart-mcp-vs-official-mcp.mdx`
- Modify: `web-docs/app/(home)/fluentcart-mcp/page.tsx`
- Modify: `web-docs/app/(home)/fluentcart-mcp/layout.tsx`
- Modify if claims require it: `web-docs/app/(home)/home-resource-links.tsx`
- Test: `scripts/check-mcp-docs-experience.test.mjs`

**Interfaces:**
- Consumes: generated release contract, manifest policy, risk registry, and current independent versus
  official product boundary.
- Produces: current merchant examples, evergreen comparison copy, and truthful marketing metadata.

- [ ] **Step 1: Confirm truth tests are RED**

Run:

```bash
node --test --test-name-pattern="current release parity|usage policy examples|marketing and blog truth" \
  scripts/check-mcp-docs-experience.test.mjs
```

Expected: FAIL on stale version copy, file upload, tax-settings statement, compatibility policy, and
unqualified marketing count.

- [ ] **Step 2: Correct merchant usage examples**

Remove file upload as an available prompt and state its unavailable policy when relevant. Correct the
global tax-settings example to the reviewed reversible behaviour. Replace hard-coded current package
versions with generated version rendering or evergreen wording.

- [ ] **Step 3: Correct tool and compatibility truth**

Remove stale current-facing `2.0.0`. Align `compatibility-support.json` with the release contract:
the 1.3.9 capture proves route surface only and does not prove tool compatibility or supported
operation.

- [ ] **Step 4: Update the comparison blog**

Keep the practical non-scorecard comparison and exact independent/official names. Add:

- the independent server is optional and not required for FluentCart or the official MCP;
- beginner links point to the client chooser;
- reversible-mode links point to advanced configuration;
- no release-specific count in evergreen prose unless rendered from generated truth;
- no unsupported official-product inference;
- no refunds, cancellations, destructive, bulk, or money-moving independent-server claim.

- [ ] **Step 5: Simplify the marketing page**

Make “Choose your app” the primary CTA. Keep the setup command secondary for local advanced users.
Remove unqualified source-definition counts from metadata and use:

```text
Open-source MCP server for reading and safely administering a FluentCart store from supported AI clients.
```

Keep only safe prompts that match current policy.

- [ ] **Step 6: Run focused truth tests**

Run:

```bash
node --test --test-name-pattern="current release parity|usage policy examples|marketing and blog truth" \
  scripts/check-mcp-docs-experience.test.mjs
node scripts/check-mcp-docs.mjs
node scripts/count-mcp-tools.mjs --check
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add web-docs/content/docs/fluentcart-mcp/usage.mdx \
  web-docs/content/docs/fluentcart-mcp/tools.mdx \
  fluentcart-mcp/compatibility-support.json \
  web-docs/content/blog/fluentcart-mcp-vs-official-mcp.mdx \
  web-docs/app/'(home)'/fluentcart-mcp \
  web-docs/app/'(home)'/home-resource-links.tsx \
  scripts/check-mcp-docs-experience.test.mjs
git commit -m "Correct MCP public documentation truth"
```

---

### Task 5: Synchronise repository guidance and Docs CI

**Files:**
- Modify: `README.md`
- Modify: `fluentcart-mcp/README.md`
- Modify: `AGENTS.md`
- Modify: `CLAUDE.md`
- Modify if present: `fluentcart-mcp/CLAUDE.md`
- Modify: `.github/workflows/docs-ci.yml`
- Test: `scripts/check-mcp-docs.test.mjs`
- Test: `scripts/check-mcp-docs-experience.test.mjs`

**Interfaces:**
- Consumes: final public page routes and truth tests.
- Produces: concise repository entry points, durable agent rules, and CI that runs every documentation
  gate when any truth input changes.

- [ ] **Step 1: Write a failing workflow contract test**

Require `.github/workflows/docs-ci.yml` to:

- run `node scripts/check-mcp-docs.mjs`;
- run `node --test scripts/check-mcp-docs.test.mjs scripts/check-mcp-docs-experience.test.mjs`;
- trigger for `scripts/mcp-doc-rules.mjs`, both test files, `compatibility-support.json`, `AGENTS.md`,
  `CLAUDE.md`, public marketing layouts/components, the comparison blog, and all MCP documentation.

- [ ] **Step 2: Run the workflow contract and verify RED**

Run:

```bash
node --test --test-name-pattern="Docs CI runs every MCP documentation gate" \
  scripts/check-mcp-docs-experience.test.mjs
```

Expected: FAIL because Docs CI currently runs only the scanner and omits truth-input paths.

- [ ] **Step 3: Update repository entry points**

Root and package READMEs lead with the chooser and describe `npx` only when local clients need it.
AGENTS and CLAUDE preserve:

- the beginner/advanced information boundary;
- current routes;
- runtime distinctions;
- blog/marketing truth scope;
- dynamic scanner and experience-test commands;
- historical changelog exclusion.

- [ ] **Step 4: Update Docs CI**

Add all required path filters and run:

```yaml
- name: Test FluentCart MCP documentation contracts
  run: node --test scripts/check-mcp-docs.test.mjs scripts/check-mcp-docs-experience.test.mjs
```

Keep the scanner as a separate named step for readable findings.

- [ ] **Step 5: Run repository and workflow truth gates**

Run:

```bash
node --test scripts/check-mcp-docs.test.mjs scripts/check-mcp-docs-experience.test.mjs
node scripts/check-mcp-docs.mjs
actionlint .github/workflows/docs-ci.yml
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add README.md fluentcart-mcp/README.md AGENTS.md CLAUDE.md \
  fluentcart-mcp/CLAUDE.md .github/workflows/docs-ci.yml \
  scripts/check-mcp-docs.test.mjs scripts/check-mcp-docs-experience.test.mjs
git commit -m "Synchronise MCP documentation guidance"
```

---

### Task 6: Verify the complete reader journey and release the documentation

**Files:**
- Modify only if verification finds an in-scope defect.

**Interfaces:**
- Consumes: all completed documentation tasks.
- Produces: fresh evidence that beginner, advanced, blog, marketing, build, and CI surfaces agree.

- [ ] **Step 1: Run every focused documentation contract**

```bash
node --test scripts/check-mcp-doc-links.test.mjs
node --test scripts/check-mcp-docs.test.mjs scripts/check-mcp-docs-experience.test.mjs
node scripts/check-mcp-doc-links.mjs
node scripts/check-mcp-docs.mjs
node scripts/count-mcp-tools.mjs --check
```

Expected: all tests and scanners pass with zero findings.

- [ ] **Step 2: Run workflow and formatting checks**

```bash
actionlint .github/workflows/docs-ci.yml
cd web-docs
npm run lint
```

Expected: zero errors.

- [ ] **Step 3: Build the production site**

```bash
cd web-docs
npm run build
```

Expected: successful production build with every new route generated.

- [ ] **Step 4: Validate primary links**

Start the production preview or development server and verify:

- every landing chooser card reaches the intended visible client page;
- `/docs/fluentcart-mcp/setup` remains useful for old inbound links;
- every beginner page links to usage, troubleshooting, and advanced configuration;
- ChatGPT web links to its dedicated page;
- the blog links to beginner and advanced destinations;
- MCPB and official FluentCart documentation links resolve.

- [ ] **Step 5: Inspect desktop and 390-pixel mobile rendering**

At both widths verify:

- all four client choices are visible without horizontal discovery;
- no page-level horizontal overflow;
- each client recipe is scannable;
- code blocks scroll internally;
- sidebar grouping matches the information architecture;
- troubleshooting headings are directly addressable.

- [ ] **Step 6: Run independent reviews**

Dispatch:

- a complete-beginner review of landing → client page → verification → recovery;
- an advanced-coverage review against the preservation matrix;
- a blog/marketing truth review against the release contract and official/independent boundary.

Address only source-backed findings, then rerun affected focused and full gates.

- [ ] **Step 7: Verify Git state**

```bash
git diff --check
git status --short --branch
git log --oneline -8
```

Expected: only intentional committed changes and no generated build output.

- [ ] **Step 8: Push and observe Docs CI**

```bash
git push origin main
gh run list --repo vcode-sh/fchub-plugins --branch main --limit 10
```

Wait for Docs CI and any MCP documentation-triggered workflows. If one fails, inspect the failing
job from primary logs, fix the exact cause, rerun full affected gates, commit, push, and observe the
replacement run.

- [ ] **Step 9: Verify live content**

After deployment, verify HTTP 200 and expected beginner markers for:

```text
https://fchub.co/docs/fluentcart-mcp
https://fchub.co/docs/fluentcart-mcp/claude-desktop
https://fchub.co/docs/fluentcart-mcp/chatgpt-desktop
https://fchub.co/docs/fluentcart-mcp/cursor
https://fchub.co/docs/fluentcart-mcp/configuration
https://fchub.co/docs/fluentcart-mcp/chatgpt-web
https://fchub.co/blog/fluentcart-mcp-vs-official-mcp
```

- [ ] **Step 10: Final commit only if verification required a correction**

Use a narrow imperative English commit message describing the verified correction.
