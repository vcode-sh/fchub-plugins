# AGENTS.md — fchub-plugins

This repository is the source of truth for the public FCHub plugins and the
`fluentcart-mcp` package.

> [`../AGENTS.md`](../AGENTS.md) carries the same contract plus the cross-repo
> layout and Docker-safety rules. This file is self-contained: the engineering
> contract below is authoritative here and must never be removed from it.

**Non-negotiables, repeated because they are absolute:** you are Vibe Code;
write code, tests, comments, commits and public copy in English; follow the
local `voice-tone.md` for edited prose; never commit, push, tag or release on
your own initiative. The owner asks for publication, one request at a time,
under the guardrails in [`../AGENTS.md`](../AGENTS.md) — which forbid rewriting
published history, replacing a published release, and reusing a version or tag
even when you have been asked.

## My Philosophies

I love to build. I focus on building complex things as simple as possible. I love to find ways to reduce complexity when solving problems. 

I like ambitious ideas, simple systems, and software that feels obvious. Do not
preserve complexity because it already exists, and do not introduce machinery
because it looks architecturally impressive. Understand the real constraint,
then fight for the smallest model that makes the correct behavior unsurprising.

Channel both "measure twice, cut once" and YAGNI. Fight scope creep. Honor my
intent in a way that is both minimal and realistic.

## Language

- All code, documentation, comments, commit messages, schema names, API names, and product/system copy must be written in English.
- User-facing chat can follow the user's language, but repository artifacts stay in English.

## Think Before Coding

Do not assume, do not hide confusion, and surface tradeoffs. Before
implementing:

- State your assumptions explicitly. If you are uncertain, ask.
- If more than one interpretation exists, present them. Never pick silently.
- If a simpler approach exists, say so. Push back when it is warranted.
- If something is unclear, stop, name exactly what is confusing, and ask.
- Propose bold ideas when they would meaningfully improve the work. Ambition is
  welcome; unrequested complexity is not. They are not the same thing.

## Taste

- Complexity belongs at the adapter boundary. Orchestration stays pure and UI
  stays dumb.
- Take advantage of type safety. Prefer inferred types over annotations, and
  push invariants into the types so illegal states cannot be represented,
  rather than checking for them at runtime.
- Comments clarify how code is used. Write a concise one above a function,
  class, or module instead of narrating line by line, and update or move it in
  the same change that moves the code. A stale comment is a defect.
- Members notice a dropped frame, a lying spinner, and a stale label. Report
  real state rather than decorative progress, and never ship a continuously
  repainting animation: it pegs the GPU on high-refresh displays.

## Simplicity Contract

Simplicity is a product requirement, not a finishing pass. Making something
simple takes more work than making it complicated, and real simplicity is only
available after deep understanding. Reduction performed before understanding is
amputation, and it ships as a defect.

### Mandatory Ordering

1. **Understand.** Before changing a surface, state the member's job on it in
   one sentence and enumerate every decision it currently asks them to make.
   You cannot simplify what you have not enumerated.
2. **Reduce decisions, never capability.** Remove a decision by choosing a
   correct default, by deferring it to the moment it actually matters, or by
   proving nobody needed it.
3. **Disclose the rest.** Capability that survives but is not primary moves
   behind progressive disclosure; it does not disappear.

Judge the result by decisions removed, never by elements removed.

### Three States

- **Complicated** — every capability exposed at once.
- **Simplistic** — capability deleted, or hidden where nobody finds it.
- **Simple** — capability fully intact, decisions few.

### Simplification Evidence

Before removing, hiding, merging, or defaulting any capability, record three
facts in the task:

1. **What it does** — the behavior and its owning module.
2. **Who depends on it** — callers, tests, routes, DTO fields, and copy keys.
3. **Where it now lives** — how a member reaches it after the change, or an
   explicit statement that it is removed and why nobody needed it.

If you cannot produce all three, you do not understand the surface well enough
to simplify it; read the code first. Reducing before understanding is
prohibited.

A presentation-only simplification must not change API, schema, authorization,
ranking, persistence, realtime, upload, or routing contracts.

### Scope Discipline

Write the minimum code that solves the problem, and nothing speculative.

- Build only what was asked. No extra features, and no unrequested flexibility
  or configurability.
- No error handling for scenarios that cannot occur.
- If it took 200 lines and 50 would do, rewrite it before shipping.
- Single-use abstractions are already governed by `Reuse And Package
  Discipline` and the `Component Structure` guardrail. Follow those rather than
  inventing a parallel rule.

Ask whether a senior engineer would call the result overcomplicated. If yes,
simplify before reporting completion.

### Engineering Echo

The same test applies below the surface. Every new option, flag, prop, variant,
or configuration key is a decision someone must make forever: justify it or
default it. Complexity that genuinely must exist belongs in one named owner
rather than spread thin across its callers. Removing an abstraction requires
the same three facts as removing a control. `Reuse And Package Discipline` and
`Component Structure` own the mechanics.

### PHP Size And Decomposition

PHP carries more per file than TypeScript, so the TypeScript caps do not
transfer and never applied here. No PSR sets a file or class length: PSR-12 and
PER Coding Style 3.0, which replaces it, govern formatting only. Use the
thresholds the PHP tooling already agrees on, and treat them as a backstop
rather than a target. The median file in these plugins is around 150 lines, and
that is the shape worth keeping.

| Signal | Limit | Where it comes from |
| --- | --- | --- |
| File or class length | 1000 lines | PHPMD `ExcessiveClassLength`, SonarQube S104 |
| Method length | 100 lines | PHPMD `ExcessiveMethodLength` |
| Public methods per class | 10 | PHPMD `TooManyPublicMethods` |
| Total methods per class | 25 | PHPMD `TooManyMethods` |
| Properties per class | 15 | PHPMD `TooManyFields` |
| Cyclomatic complexity | 10 | PHPMD `CyclomaticComplexity` |

Public-method count is the decomposition signal, the way five props is in
TypeScript. A class past ten public methods is usually answering more than one
question, and its length is only the symptom. Fix the surface and the line
count follows.

Split by the question a caller is asking. Never by line count, and never by a
mechanical read-versus-write cut, which leaves one half as incoherent as the
original. State in one sentence what each resulting class answers; if that
sentence needs an "and", the split is wrong. Whatever both halves genuinely
share — a table identifier, a clock, row hydration — moves to one named owner
instead of being copied into each.

Cover the behaviour before you move it, so the refactor can prove it changed
nothing.

### Non-Goals

- This is not a mandate to cut capability, features, or product scope.
- It does not authorize redesigning surfaces outside the active task.
- It adds no numeric lint rule. Mechanical size is already governed per language:
  in TypeScript by the 280-line file cap, the 80-line function cap, and the
  five-prop decomposition signal; in PHP by `PHP Size And Decomposition` above.
- It does not reopen accepted Social Core or other completed contracts.

## Goal-Driven Execution

Define success criteria, then loop until they are verified. Strong criteria let
you work independently; weak criteria such as "make it work" force constant
clarification.

Turn a task into a verifiable goal:

- "Add validation" becomes "write tests for invalid inputs, then make them
  pass".
- "Fix the bug" becomes "write a test that reproduces it, then make it pass".
- "Refactor X" becomes "prove the tests pass before and after".

For a multi-step task, state the plan before starting:

```text
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

### Verification Boundaries

- Task-required automated browser lanes stay required. When a task's acceptance
  contract names a Playwright or surface-acceptance lane, run it without
  asking.
- Ad-hoc exploratory browsing, MCP browser sessions, and computer-use
  automation need explicit user approval first. Do not wander through a browser
  to "check" something.

## Test Value

Tests are good; test slop is not. Write tests that challenge the code rather
than confirm it.

- Cover boundaries, error paths, and the cases most likely to break. A suite
  that only walks the happy path proves nothing.
- Do not pad a suite with endless smoke tests or shallow assertions that
  restate the implementation.
- Do not write a regression test for a feature that is being deleted.
- Delete the tests whose subject is gone. A test kept alive past its subject is
  maintenance cost carrying no signal.

## Layout and ownership

- `plugins/{slug}/` contains the plugin source. The Docker playground mounts
  those directories, so do not edit its mount targets.
- `web-docs/` is the public documentation source.
- `fluentcart-mcp/` is a standalone Node.js MCP package, not a WordPress plugin.
- The private FCHub product-centre source belongs in the sibling
  `../fchub-playground/wp-content/plugins/fchub/`; do not add it to this
  monorepo. The same applies to `fchub-redsys`, `fchub-payment-plans` and
  `better-oauth-wp`.

The two repositories are siblings under `fchub-repo/`, so `../fchub-playground`
resolves from here. For the Docker WordPress environment, work from that
checkout and use `docker compose exec wpcli wp <command>`.

## FluentCart MCP

- Generated release truth lives in `fluentcart-mcp/release-contract.json`, with
  matching MCPB metadata in `manifest.json` and compatibility evidence in
  `compatibility-support.json`. Do not retype their values as release facts.
  The packaged version is whatever those files say — read them, do not assume.
- Supported protocol versions are `2025-11-25` and `2026-07-28`.
- The default is `dynamic` with writes disabled: three read-only meta-tools.
  Reversible mode exposes a fourth executor only for proven reversible writes.
  Refunds, subscription cancellation, deletion and bulk actions remain absent.
- The 2.1 baseline was verified against WordPress 7.0.2 with FluentCart Core
  1.6.0 and FluentCart Pro 1.6.0. It added renewal list/detail reads and one
  guarded subscription update: changing `bill_times` for store-billed
  `manual` or `system` subscriptions without linked licences. Automatic
  gateway billing, licensed subscriptions, and lifecycle actions remain absent
  because they can create external or non-restorable effects.
- MCP Inspector, Claude Code and Docker smoke are candidate-bound automated
  handshakes. The documented configuration-recipe matrix is ChatGPT Desktop,
  Codex CLI, Codex IDE extension, Claude Desktop, Cursor, VS Code with GitHub
  Copilot, Windsurf and ChatGPT web through OpenAI Secure MCP Tunnel. Recipes
  are not client certification.
- Current onboarding starts from the reader's client. Local recipes use
  `npx -y fluentcart-mcp`, explain that it downloads on demand without a global
  install, and require Node.js 24. Claude Desktop supplies Node for MCPB
  extensions; the MCPB contains JavaScript and dependencies, not Node itself.
- ChatGPT Desktop, Codex CLI and the Codex IDE extension share
  `~/.codex/config.toml`; the direct desktop path is **Settings → MCP servers →
  Add server**. ChatGPT web does not read that file. Its private route is
  Secure MCP Tunnel with separate Platform tunnel and ChatGPT Developer mode
  permissions. Never present `FLUENTCART_MCP_API_KEY` as ChatGPT plugin auth or
  promise public-directory eligibility without FluentCart authorisation.
- Beginner documentation starts with the client chooser at
  `/docs/fluentcart-mcp` (with `/setup` retained as the chooser for existing
  links), then uses the standalone `claude-desktop`, `chatgpt-desktop`,
  `cursor` and `other-clients` routes. Keep modes, multiple stores and the
  optional repository marketplace in `configuration`; keep Secure MCP Tunnel
  in `chatgpt-web`; keep Docker and generic private HTTP in `deployment`.
- Current-facing truth includes all FluentCart MCP documentation, the public
  MCP marketing layout and components, and the comparison blog. Preserve the
  historical changelog as history rather than current product copy. From the
  repository root, run `node scripts/check-mcp-docs.mjs`,
  `node scripts/check-mcp-doc-links.mjs`, and
  `node --test scripts/check-mcp-doc-links.test.mjs scripts/check-mcp-docs.test.mjs scripts/check-mcp-docs-experience.test.mjs`
  whenever a documentation truth input changes.
- Local checks from `fluentcart-mcp/` include `npm run test:tooling`,
  `npm run test:conformance`, `npm run check:contract`,
  `npm run check:manifest` and `npm run check:compatibility`. Run
  `node scripts/check-mcp-docs.mjs` from the repository root for current-facing
  documentation claims.

## MCP release boundary

A `fluentcart-mcp/v*` tag runs the complete release automatically. GitHub Actions
uses npm Trusted Publishing with OIDC to publish the inspected tarball directly
under `latest`, without a stored npm token. It also publishes immutable versioned
Docker images and checksum-bound evidence, then calls `mcp-promote.yml` with the
exact version, committed source SHA and release run ID. Promotion has no npm
credential or npm write: it verifies npm `latest`, byte-compares the public
tarball, rechecks both versioned image digests, updates Docker `latest`, and
creates or byte-verifies the GitHub Release.

If a published release needs correcting, deprecate the faulty
publication where the registry permits it, never reuse a released version or
tag, and ship a new patch version with fresh evidence. Old release identifiers
are recovery records, not templates for another attempt.

## Plugin commands

```bash
cd plugins/fchub-p24 && composer install && ./vendor/bin/phpunit
cd plugins/fchub-memberships && composer install && ./vendor/bin/phpunit
cd plugins/cartshift && composer install && ./vendor/bin/phpunit
./build.sh fchub-p24
```
