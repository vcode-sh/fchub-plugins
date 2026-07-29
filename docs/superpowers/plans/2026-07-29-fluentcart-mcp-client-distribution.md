# FluentCart MCP 2.0.1 Client Distribution Implementation Plan

> **Execution:** Use subagent-driven development. Each implementation task gets
> focused RED evidence, implementation, focused GREEN evidence, self-review and
> independent task review before the next task.

**Goal:** Ship FluentCart MCP 2.0.1 with honest, client-first installation for
Claude Desktop, ChatGPT Desktop, Codex, Cursor, VS Code, Windsurf and private
ChatGPT web, then publish and verify the release.

**Architecture:** Keep one local STDIO server and one local credential file.
Use `npx -y` for local clients, Claude's Node MCPB for Claude Desktop, an
optional OpenAI local plugin package for ChatGPT/Codex, and OpenAI Secure MCP
Tunnel for private ChatGPT web. Do not add a hosted gateway or OAuth server.

**Tech stack:** Node.js 24, TypeScript 5.9, MCP SDK 2.0, Vitest 4, Node test
runner, MCPB 2.1.2, MDX/Fumadocs, GitHub Actions, npm Trusted Publishing.

**Design:** `docs/superpowers/specs/2026-07-29-fluentcart-mcp-client-distribution-design.md`

## Global constraints

- All code, documentation, tests and commits are English.
- Do not touch `plugins/fchub-stream/`.
- Preserve dynamic mode and disabled writes as defaults.
- Never put WordPress, OpenAI or registry credentials in source, fixtures,
  release assets, logs or command output.
- Do not claim certification for a client without an observed,
  candidate-bound handshake.
- Do not claim a public OpenAI Plugins Directory listing.
- Do not add an `.app.json` placeholder; that file requires a real registered
  `plugin_asdk_app...` identifier.
- Do not require `npm install -g fluentcart-mcp`.
- Keep the existing npm Trusted Publishing and interactive-2FA release model.
- No 60-minute soak is part of this patch.
- Generated release metadata must be rebuilt from the final source.

## Task 1: Make release and client truth complete

**Modify:**

- `fluentcart-mcp/package.json`
- `fluentcart-mcp/package-lock.json`
- `fluentcart-mcp/tests/fixtures/releases/previous-release-state.json`
- `fluentcart-mcp/compatibility-support.json`
- `fluentcart-mcp/scripts/release-truth.mjs`
- `fluentcart-mcp/tests/tooling/task10-release.test.mjs`

**Generate later:**

- `fluentcart-mcp/release-contract.json`
- `fluentcart-mcp/manifest.json`

### Steps

1. Change the focused release test to expect version 2.0.1 and the complete
   configuration-recipe matrix:
   - ChatGPT Desktop: STDIO, local;
   - Codex CLI: STDIO, local;
   - Codex IDE extension: STDIO, local;
   - Claude Desktop: MCPB/STDIO, extension;
   - Cursor: STDIO, local;
   - VS Code with GitHub Copilot: STDIO, local;
   - Windsurf: STDIO, local;
   - ChatGPT web: Secure MCP Tunnel, private web.
2. Require every entry to contain `status`, `transport`, `distribution`,
   `capabilitySource` and `reason`.
3. Run the focused tooling test and record the expected RED failure.
4. Bump package and lockfile to 2.0.1.
5. Run `npm run capture:release-state` after the version bump so the fixture
   captures public npm 2.0.0 and both current public Docker digests as immutable
   recovery state for candidate 2.0.1.
6. Implement one ordered recipe catalogue in `release-truth.mjs` and use it to
   generate the release contract and MCPB metadata.
7. Update `compatibility-support.json` release evidence to 2.0.1 without
   changing unrelated capability evidence.
8. Run the focused test GREEN.

## Task 2: Package the optional local ChatGPT/Codex plugin

**Create:**

- `fluentcart-mcp/openai-plugin/.codex-plugin/plugin.json`
- `fluentcart-mcp/openai-plugin/.mcp.json`
- `.agents/plugins/marketplace.json`
- `fluentcart-mcp/tests/tooling/openai-plugin-package.test.mjs`

**Modify:**

- `fluentcart-mcp/package.json`
- `fluentcart-mcp/scripts/release-contract-inputs.mjs`

### Steps

1. Write a Node contract test that requires:
   - plugin name `fluentcart-mcp`;
   - plugin version equal to `package.json`;
   - `mcpServers` equal to `./.mcp.json`;
   - no `apps` field and no `.app.json`;
   - one server called `fluentcart`;
   - command `npx`;
   - arguments exactly `-y`, `fluentcart-mcp@<package version>`;
   - no credential values or lifecycle hooks;
   - marketplace source is the repository's plugin subdirectory and carries
     `AVAILABLE`, `ON_INSTALL`, and a relevant category.
2. Run the focused test and record RED.
3. Add the minimal OpenAI plugin and marketplace manifests using only fields
   documented by OpenAI's current plugin packaging specification.
4. Include the plugin package in the release source digest. Include it in the
   npm package only if the focused package-boundary test proves the installed
   layout remains valid; otherwise keep repository-marketplace distribution
   only and state that boundary in documentation.
5. Run the focused test and `npm pack --dry-run` GREEN.

## Task 3: Correct dynamic tool annotations

**Modify:**

- `fluentcart-mcp/tests/tools/dynamic.test.ts`
- `fluentcart-mcp/src/tools/dynamic.ts`

### Steps

1. Extend the existing annotation test to require both
   `fluentcart_search_tools` and `fluentcart_describe_tools` to advertise:
   - `readOnlyHint: true`;
   - `destructiveHint: false`;
   - `openWorldHint: false`.
2. Run only `tests/tools/dynamic.test.ts` and record RED.
3. Add the missing `destructiveHint: false` values.
4. Re-run the focused test GREEN.

## Task 4: Rewrite onboarding around the client

**Modify current-facing product surfaces:**

- `fluentcart-mcp/README.md`
- `README.md`
- `AGENTS.md`
- `CLAUDE.md` (local ignored guidance; do not change ignore rules)
- `web-docs/content/docs/fluentcart-mcp/index.mdx`
- `web-docs/content/docs/fluentcart-mcp/setup.mdx`
- `web-docs/content/docs/fluentcart-mcp/deployment.mdx`
- `web-docs/content/docs/fluentcart-mcp/troubleshooting.mdx`
- `web-docs/content/docs/fluentcart-mcp/_changelog/2026-07.mdx`
- `web-docs/lib/versions.json`
- `scripts/mcp-doc-rules.mjs`
- `fluentcart-mcp/tests/tooling/task10-release.test.mjs`

**Inspect and update if current-facing:**

- `web-docs/app/(home)/fluentcart-mcp/page.tsx`
- `web-docs/content/blog/fluentcart-mcp-vs-official-mcp.mdx`
- sibling/playground `AGENTS.md`, `CLAUDE.md`, `README.md` references.

### Steps

1. Add executable documentation-contract assertions before rewriting prose:
   - Claude extension copy must say Claude Desktop supplies Node and must not
     ask extension users to install Node;
   - local recipes must explain that `npx -y` downloads on demand and does not
     install globally;
   - ChatGPT Desktop/Codex shared configuration and exact UI path must exist;
   - all eight generated configuration targets must be represented;
   - ChatGPT web must name Secure MCP Tunnel, Developer mode and its separate
     OpenAI permissions;
   - static FluentCart bearer-key auth must not be offered as ChatGPT plugin
     auth;
   - public-directory copy must state the current authorisation boundary;
   - no current-facing page may claim the MCPB contains Node.
2. Run the focused docs tests/checker and record RED.
3. Rewrite the main page to start with a compact client chooser and a
   three-step first-run path.
4. Rewrite setup so each client has an exact current recipe and verification
   step. Keep direct ChatGPT Desktop **Add server** as the shortest OpenAI path
   and present the optional local plugin afterward.
5. Rewrite ChatGPT web deployment around Secure MCP Tunnel using the exact
   official sequence. Link to Platform tunnel settings for runtime-key
   creation; do not print or store a real key.
6. Correct Claude extension runtime claims and preserve the distinction between
   Claude's supplied runtime and the archive contents.
7. Add the 2.0.1 changelog entry and bump the central version.
8. Update all agent/contributor guidance so it names the complete recipe
   matrix and current release boundary.
9. Run the docs checker, tooling release test and web-docs build GREEN.

## Task 5: Regenerate and inspect the 2.0.1 candidate

**Generate/update:**

- `fluentcart-mcp/release-contract.json`
- `fluentcart-mcp/manifest.json`
- candidate archives under ignored `fluentcart-mcp/dist-packages/`

### Steps

1. Run:

   ```bash
   npm run build
   npm run build:contract
   npm run build:manifest
   npm run check:contract
   npm run check:manifest
   npm run pack:release
   ```

2. Inspect the npm tarball and MCPB with the repository inspectors.
3. Unpack both into temporary directories and verify:
   - version 2.0.1 everywhere;
   - corrected Trusted Publishing metadata;
   - complete configuration-recipe matrix;
   - no credentials;
   - MCPB production dependencies and entry point present;
   - optional OpenAI plugin package is either intentionally present and valid
     or intentionally repository-only as decided by Task 2 evidence.
4. Run package-boundary and manifest contract tests.

## Task 6: Run complete release verification

### Focused and full local gates

Run from `fluentcart-mcp/`:

```bash
npm run test:unit
npm run test:tooling
npm run test:acceptance
npm run test:conformance
npm run typecheck
npm run typecheck:tests
npm run lint
npm run build
npm run check:contract
npm run check:manifest
npm run check:compatibility
```

Run from repository root:

```bash
node scripts/check-mcp-docs.mjs
actionlint
```

Run the web documentation lint/build commands declared by `web-docs/package.json`.

Run the candidate-bound client and Docker lanes already required by the release
contract. Do not add the retired 60-minute soak.

### Claude Desktop observation

1. Install the exact candidate `fluentcart-mcp.mcpb` in the available Claude
   Desktop.
2. Configure it against the authorised test WordPress installation.
3. Observe the process startup and one read-only tool call.
4. Capture the Claude version, extension version, candidate digest and the
   evidence that Desktop's extension runtime was used rather than a required
   system Node installation.
5. Remove or replace only the FluentCart test extension/configuration created
   by this task if cleanup is needed; do not alter unrelated extensions.

### OpenAI local plugin observation

1. Validate the repo marketplace with the current `codex plugin marketplace`
   commands.
2. Install/enable the FluentCart MCP plugin in the ChatGPT desktop app if the
   current account surface permits it.
3. Verify the shared MCP server appears in ChatGPT Desktop and Codex and
   performs one read-only request against the test store.
4. If the account lacks a required permission, record `BLOCKED` with the exact
   UI/CLI evidence and keep public copy at configuration-target status.

## Task 7: Independent review and correction

1. Generate a complete review package from the starting commit through the
   candidate commit.
2. Dispatch an independent whole-change reviewer with the design, plan, diff
   and test evidence.
3. Fix all Critical and Important findings in one bounded fix wave.
4. Run one scoped re-review and the affected tests.
5. Re-run generated-file checks after every source correction.

## Task 8: Publish and verify 2.0.1

1. Confirm the working tree contains only intended 2.0.1 changes.
2. Commit in English and push `main`.
3. Confirm main-branch CI is green.
4. Create and push tag `fluentcart-mcp/v2.0.1`.
5. Observe both tag workflows:
   - npm/GitHub staged release workflow;
   - Docker workflow on `ubuntu-latest` where provenance is supported.
6. Complete the npm native staged-publish interactive 2FA approval.
7. Dispatch promotion with exact version, source SHA and staging run ID.
8. Verify:
   - npm `fluentcart-mcp@2.0.1` and `latest`;
   - npm provenance and package contents;
   - GitHub Release and checksums;
   - MCPB download contents and digest;
   - GHCR and Docker Hub version and `latest` digests;
   - live web documentation shows 2.0.1 and current client instructions;
   - every GitHub Actions workflow triggered by main/tag/promotion succeeded.
9. Update the sibling playground's MCP completion/status docs if they describe
   current release state, commit in English and push its `main`.
10. Confirm both worktrees are clean and all local tags match their remotes.
