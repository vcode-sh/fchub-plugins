# FCHub Plugins

This repository is the source of truth for the public FCHub plugins and the
`fluentcart-mcp` package. Write code, tests, comments, commits and public copy
in English. For edited prose, follow the tracked `voice-tone.md` where it is
available. Do not commit, push, tag, publish or create releases: the project
owner performs publication.

## Layout and ownership

- `plugins/{slug}/` contains the plugin source. The Docker playground mounts
  those directories, so do not edit its mount targets.
- `web-docs/` is the public documentation source.
- `fluentcart-mcp/` is a standalone Node.js MCP package, not a WordPress plugin.
- The private FCHub product-centre source belongs in the sibling
  `fchub-playground/wp-content/plugins/fchub/`; do not add it to this monorepo.

## FluentCart MCP 2.0

- Generated release truth lives in `fluentcart-mcp/release-contract.json`, with
  matching MCPB metadata in `manifest.json` and compatibility evidence in
  `compatibility-support.json`. Do not retype their values as release facts.
- Supported protocol versions are `2025-11-25` and `2026-07-28`.
- The default is `dynamic` with writes disabled: three read-only meta-tools.
  Reversible mode exposes a fourth executor only for proven reversible writes.
  Refunds, subscription cancellation, deletion and bulk actions remain absent.
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

A `fluentcart-mcp/v*` tag uses npm Trusted Publishing to create a native staged
publish with the immutable `latest` tag. It also publishes versioned Docker
images and evidence, but does not make npm public, create mutable Docker tags or
create a GitHub Release. The owner reviews and approves the npm stage with
interactive 2FA, then dispatches `mcp-promote.yml` with the exact version,
committed source SHA and staging run ID. Promotion has no npm credential or npm
write: it verifies that npm `latest` is the approved version, rechecks the public
bytes and versioned images, updates Docker `latest`, and creates the GitHub
Release.

If a staged or promoted release needs correcting, deprecate the faulty
publication where the registry permits it, never reuse a released version or
tag, and ship a new patch version with fresh evidence. Old release identifiers
are recovery records, not templates for another attempt.

## Plugin commands

```bash
cd plugins/fchub-p24 && composer install && ./vendor/bin/phpunit
cd plugins/fchub-memberships && composer install && ./vendor/bin/phpunit
./build.sh fchub-p24
```

## FCHub Stream - Discontinued

FCHub Stream is discontinued, with maintenance suspended indefinitely. Do not support, fix, update, test, review, triage, release, or otherwise maintain `plugins/fchub-stream/`. The source and existing tooling stay in the repository because the project may return and others may fork it. Resume work only if the project owner explicitly reactivates the plugin.
