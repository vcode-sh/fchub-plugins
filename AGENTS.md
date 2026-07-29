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
  handshakes. Claude Desktop and Cursor are configuration recipes, not client
  certification.
- Local checks from `fluentcart-mcp/` include `npm run test:tooling`,
  `npm run test:conformance`, `npm run check:contract`,
  `npm run check:manifest` and `npm run check:compatibility`. Run
  `node scripts/check-mcp-docs.mjs` from the repository root for current-facing
  documentation claims.

## MCP release boundary

A `fluentcart-mcp/v*` tag stages immutable candidate bytes only: versioned
Docker images and an inspected npm package under the `next` tag. It does not
move npm `latest`, create mutable Docker tags or create a GitHub Release.
The owner alone dispatches `mcp-promote.yml` with the exact version, committed
source SHA and staging run ID. Promotion re-verifies the staged artefacts, moves
the same npm and Docker bytes to `latest`, creates the GitHub Release, then
removes `next`.

## Plugin commands

```bash
cd plugins/fchub-p24 && composer install && ./vendor/bin/phpunit
cd plugins/fchub-memberships && composer install && ./vendor/bin/phpunit
./build.sh fchub-p24
```

## FCHub Stream - Discontinued

FCHub Stream is discontinued, with maintenance suspended indefinitely. Do not support, fix, update, test, review, triage, release, or otherwise maintain `plugins/fchub-stream/`. The source and existing tooling stay in the repository because the project may return and others may fork it. Resume work only if the project owner explicitly reactivates the plugin.
