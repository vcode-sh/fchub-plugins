# FluentCart MCP 2.0.1 Client Distribution Design

**Date:** 29 July 2026
**Status:** Approved for implementation
**Release:** `fluentcart-mcp` 2.0.1

## Problem

FluentCart MCP 2.0.0 is published and technically usable, but its public
onboarding tells the story backwards:

- Claude Desktop is presented first while ChatGPT, Codex, Cursor, VS Code and
  Windsurf are buried;
- the Claude Desktop extension incorrectly tells users to install Node.js even
  though Claude Desktop supplies its own Node runtime;
- `npx -y fluentcart-mcp` is not explained as an on-demand download, so users
  can reasonably assume a global package installation is missing;
- the generated release truth only names Claude Desktop and Cursor as
  configuration recipes even though the documentation describes more clients;
- ChatGPT web is described as generic deployment guidance even though its
  supported private path is now OpenAI Secure MCP Tunnel;
- the released MCPB contains stale release metadata from before npm Trusted
  Publishing was corrected.

This is a product defect, not an excuse to build a hosted integration company.

## Product principle

The simple mental model is:

1. Give FluentCart MCP access to one WordPress store.
2. Add the same MCP command to the client you already use.
3. Start asking about the store.

The implementation may do substantial work to preserve that model, but the
user must not be made to understand release pipelines, transports, package
managers, OAuth infrastructure or client evidence contracts before the first
successful question.

## Source-backed constraints

The design follows current primary documentation:

- ChatGPT Desktop, Codex CLI and the Codex IDE extension support STDIO and
  Streamable HTTP MCP servers and share the same Codex configuration on a host.
  ChatGPT Desktop provides **Settings → MCP servers → Add server**.
- ChatGPT web does not read local Codex configuration. Private web access uses
  a registered remote connection, with OpenAI Secure MCP Tunnel providing an
  outbound-only route to a private STDIO or HTTP server.
- A ChatGPT/Codex plugin may bundle a local MCP server through `.mcp.json`.
  `.app.json` is only for an already registered, account-specific remote
  connection and therefore cannot be shipped as a universal placeholder.
- Public OpenAI directory submission is not available to an unofficial
  third-party connector without the service provider's authorisation.
- Claude Desktop supplies a built-in Node runtime for Node-based MCPB
  extensions. The MCPB bundle still contains JavaScript and production
  dependencies rather than a Node executable.

Primary sources:

- <https://learn.chatgpt.com/docs/extend/mcp>
- <https://developers.openai.com/plugins/build/plugins>
- <https://developers.openai.com/api/docs/guides/secure-mcp-tunnels>
- <https://developers.openai.com/plugins/deploy/connect-chatgpt>
- <https://developers.openai.com/plugins/app-guidelines>
- <https://support.claude.com/en/articles/10949351-getting-started-with-local-mcp-servers-on-claude-desktop>
- <https://github.com/modelcontextprotocol/mcpb/blob/70fe3b34cd6dff1b3bba046638edc72a6467a4fb/MANIFEST.md>

## Supported distribution paths

### Claude Desktop extension

The MCPB remains a Node MCPB using manifest format 0.3.

The user flow is:

1. Download `fluentcart-mcp.mcpb` from the current GitHub Release.
2. Open it in Claude Desktop.
3. Enter the WordPress URL, username and Application Password.
4. Enable the extension and verify one read.

The documentation must say that Claude Desktop supplies Node for extensions.
It must not claim that the archive contains a Node executable. The release is
not complete until the exact 2.0.1 MCPB is installed and observed on the
available Claude Desktop without relying on a system `node` executable.

A compiled binary MCPB is explicitly out of scope. It would add per-platform
builds and certification without removing a user prerequisite that Claude
Desktop already removes.

### ChatGPT Desktop and Codex

ChatGPT Desktop, Codex CLI and the Codex IDE extension use the same local
server configuration:

```text
command: npx
arguments: -y fluentcart-mcp
```

Users do not install `fluentcart-mcp` globally. `npx -y` downloads the package
on first use and reuses the package-manager cache afterward.

Credentials are configured once with:

```bash
npx -y fluentcart-mcp setup
```

The setup wizard writes the existing private FluentCart MCP configuration file.
Clients then start the same STDIO server without duplicating store credentials
inside every client configuration.

The release will also contain an optional local ChatGPT/Codex plugin package:

```text
fluentcart-mcp/openai-plugin/
├── .codex-plugin/plugin.json
└── .mcp.json
```

The plugin manifest identifies FluentCart MCP and points to `.mcp.json`. The
MCP configuration invokes the exact released npm version through `npx -y`.
The repository marketplace exposes the plugin as an optional installation
surface. It does not contain `.app.json`, because no universal registered
remote connection exists.

Direct **Add server** remains the shortest supported route. The plugin package
exists for users and workspaces that prefer the Plugins Directory and managed
enable/disable controls.

### Claude Code, Cursor, VS Code and Windsurf

Each client gets a first-class setup section using its current supported MCP
configuration surface. Every recipe invokes `npx -y fluentcart-mcp`; none asks
for `npm install -g`.

The release contract records these as documented configuration targets, not
automated certification, unless an exact candidate-bound handshake is actually
observed.

### ChatGPT web

The recommended private path is OpenAI Secure MCP Tunnel:

1. Run the credential setup locally.
2. Create an OpenAI tunnel with the required organisation/workspace
   permissions.
3. Configure `tunnel-client` to start `npx -y fluentcart-mcp`.
4. Keep the tunnel client running.
5. In ChatGPT Plugins developer mode, add a Tunnel connection and select the
   tunnel.

The tunnel's runtime API key authenticates the outbound tunnel client to
OpenAI. It is not a FluentCart MCP bearer token and must never be committed.

The existing static bearer HTTP profile remains supported for generic private
HTTP clients. It must not be described as ChatGPT web authentication because
ChatGPT does not accept a merchant's arbitrary API key as remote plugin auth.

## Generated release truth

The singular configuration-recipe matrix contains:

- ChatGPT Desktop;
- Codex CLI;
- Codex IDE extension;
- Claude Desktop;
- Cursor;
- VS Code with GitHub Copilot;
- Windsurf;
- ChatGPT web through Secure MCP Tunnel.

Each entry records:

- status `CONFIGURATION_TARGET`;
- its supported transport;
- whether it is local, extension or private-web distribution;
- the current primary documentation URL;
- a reason that it is documented rather than candidate-certified.

The matrix is generated into the release contract and MCPB metadata. Tests
must reject missing clients, stale promotion metadata and mismatched versions.

## Tool metadata correction

The dynamic `fluentcart_search_tools` and `fluentcart_describe_tools` tools
must explicitly advertise `destructiveHint: false`. This is required metadata
for the ChatGPT plugin review surface and is true for both operations.

No broader output-schema retrofit belongs in this patch. Output schemas and
`structuredContent` require per-tool response-contract design and are not
required to make the current tool-only local/private integration usable.

## Documentation information architecture

The main page answers these questions before architecture:

1. Which client do you use?
2. Do you need to install anything?
3. Where do the WordPress credentials go?
4. How do you know it worked?

Client choices appear together near the top. Claude Desktop is one option, not
the product's implied centre.

The setup page separates:

- **No separate package install:** Claude Desktop extension;
- **No global install:** local clients using `npx`;
- **Private web connection:** ChatGPT web using Secure MCP Tunnel;
- **Server deployment:** Docker and the private HTTP profile.

README, root README, AGENTS.md, local CLAUDE.md guidance, web documentation,
changelog, release contract and MCPB metadata must agree. Historical changelog
entries may retain historical facts, but current-facing summaries cannot
repeat superseded instructions.

## Security defaults

- The default write mode remains disabled.
- Store credentials remain local and are never put in repository marketplace
  metadata, release assets or documentation examples.
- The setup wizard retains mode `0600` for its credential file.
- ChatGPT web examples use a dedicated least-privilege WordPress principal.
- Secure MCP Tunnel credentials are created and stored through OpenAI's tunnel
  tooling, never in FluentCart MCP source.
- No public listener is required for the recommended ChatGPT web path.

## Release acceptance

2.0.1 is releasable only when:

- focused tests show the new release/client metadata and annotations failed
  before implementation and pass afterward;
- all generated files are rebuilt from the final source;
- npm, MCPB and Docker artefacts pass the repository inspectors;
- the exact MCPB is observed in the installed Claude Desktop without a
  separate system Node prerequisite;
- the ChatGPT/Codex plugin manifests pass repository contract tests;
- the full unit, tooling, acceptance, conformance, type, lint, build, docs and
  workflow gates pass;
- npm Trusted Publishing creates the native staged publish and interactive 2FA
  approval makes 2.0.1 public;
- npm `latest`, both Docker registries, the GitHub Release, live documentation
  and GitHub Actions are verified after promotion;
- both repository worktrees are clean.

There is no 60-minute soak in this patch. It would not prove any of these
distribution paths and would merely make the calendar feel involved.

## Explicit non-goals

- a public universal Plugins Directory submission;
- a hosted multi-tenant FluentCart credential broker;
- implementing an OAuth authorisation server;
- collecting WordPress Application Passwords in ChatGPT tool inputs;
- a compiled cross-platform MCPB;
- custom ChatGPT UI components;
- claiming client certification without a candidate-bound observation.
