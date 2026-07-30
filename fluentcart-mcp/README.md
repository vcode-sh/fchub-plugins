# FluentCart MCP Server

[![npm](https://img.shields.io/npm/v/fluentcart-mcp)](https://www.npmjs.com/package/fluentcart-mcp)
[![Node.js](https://img.shields.io/badge/node-%3E%3D24-brightgreen)](https://nodejs.org)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

An MCP server that connects AI assistants to your [FluentCart](https://fluentcart.com) store — orders, products, customers, subscriptions, coupons, reports, shipping, tax, email notifications, and more. Read-only until you deliberately say otherwise. Open source, MIT licensed.

**New in 2.1.0:** FluentCart 1.6+ is the release theme. It adds direct renewal list and detail
reads, a narrowly guarded subscription billing-cycle-limit update, and richer subscription context without
opening the door to charges, cancellation, or renewal manipulation. The exact verified runtime is
WordPress 7.0.2 with FluentCart Core 1.6.0 and FluentCart Pro 1.6.0; “1.6+” does not pretend we
have tested every future release, because that would be a rather efficient way to turn a version
label into fiction.

The guarded update applies only to store-billed subscriptions whose collection method is `manual`
or `system`. Gateway-billed `automatic` subscriptions fail closed before any write. FluentCart 1.6
does not provide an atomic version precondition: the tool performs a just-in-time preflight and
read-back, returns the previous limit on success, and tells the caller to re-fetch rather than
blindly retry when the outcome cannot be verified. Subscriptions with linked FluentCart Pro
licences fail closed because the upstream update event can change licence state.

The server speaks MCP `2025-11-25` and `2026-07-28`. The recipes below are setup guidance, not
client certification. Candidate certification requires a candidate-bound handshake from MCP Inspector
(stdio and HTTP), Claude Code (stdio and HTTP), and Docker HTTP.

## Pick Your Client

| Client | Shortest route |
|---|---|
| **ChatGPT Desktop** | **Settings → MCP servers → Add server** |
| **Codex CLI / Codex IDE extension** | Shared `~/.codex/config.toml` |
| **Claude Desktop** | Install the MCPB extension |
| **Claude Code** | `claude mcp add` |
| **Cursor** | Global `mcp.json` |
| **VS Code with GitHub Copilot** | User or workspace `mcp.json` |
| **Windsurf** | `mcp_config.json` |
| **ChatGPT web** | OpenAI Secure MCP Tunnel |

Start with the [client chooser](https://fchub.co/docs/fluentcart-mcp), then use the standalone
guide for that client. It keeps local STDIO, Claude Desktop MCPB, and ChatGPT web tunnel setup from
becoming one large configuration accident.

## Three-Step First Run

1. Create a WordPress Application Password for a suitably narrow FluentCart user.
2. For a local client with Node.js 24 or newer, save it once:

   ```bash
   npx -y fluentcart-mcp setup
   ```

   `npx -y` downloads FluentCart MCP on demand and reuses the package-manager cache. It does not
   install the package globally. Local clients require Node.js 24 or newer.
3. Add command `npx` with arguments `-y fluentcart-mcp` to your client, then ask:
   *"Show me the FluentCart dashboard stats."*

### ChatGPT Desktop and Codex

ChatGPT Desktop, Codex CLI and the Codex IDE extension share MCP configuration in
`~/.codex/config.toml`. In ChatGPT Desktop use **Settings → MCP servers → Add server**, choose
STDIO, set command `npx`, set arguments `-y fluentcart-mcp`, save and restart. Direct **Add server**
is the shortest OpenAI route; the optional local plugin is available afterward for workspaces that
prefer plugin-managed controls.

### Claude Desktop extension

**[Download the latest fluentcart-mcp.mcpb](https://github.com/vcode-sh/fchub-plugins/releases?q=fluentcart-mcp&expanded=true)**,
then open **Settings → Extensions → Advanced settings → Install Extension**.

Claude Desktop supplies a built-in Node runtime for MCPB extensions, so extension users do not
install Node separately. The MCPB contains the server's JavaScript and production dependencies; it
does not contain a Node executable. Enter the WordPress URL, username and Application Password,
enable the extension, then ask the verification question above.

### ChatGPT web

ChatGPT web does not read local Codex configuration. Use the
[OpenAI Secure MCP Tunnel recipe](https://fchub.co/docs/fluentcart-mcp/chatgpt-web)
for a private, outbound-only connection. The FluentCart HTTP bearer key is not ChatGPT plugin
authentication.

## Authentication

Uses **WordPress Application Passwords** (built into WordPress 5.6+). No extra plugins needed.

1. WordPress admin → **Users → Profile**
2. Scroll to **Application Passwords**
3. Enter a name, click **Add New Application Password**
4. Copy the password (WordPress shows it once)

Create a dedicated account such as `fluentcart-reader` and give it only the FluentCart REST
capabilities you actually intend to use. Its WordPress permissions decide what the server can read;
read-only mode separately prevents MCP writes.

The server keeps some authorised configuration and reference reads in memory for at most 60 seconds. Restart the MCP process after rotating or revoking its Application Password to purge those responses immediately; credentials are loaded once at startup and are never hot-reloaded.

## Configuration

Three options, checked in this order:

### 1. Environment Variables

```bash
FLUENTCART_URL=https://your-store.com
FLUENTCART_USERNAME=fluentcart-reader
FLUENTCART_APP_PASSWORD="paste the generated Application Password here"
```

FluentCart supplies native WordPress Abilities for richer analytics and advanced queries. They are
an explicit, read-only opt-in; this release refreshes the audited target against FluentCart 1.6.0:

```bash
FLUENTCART_ABILITIES_MODE=enabled
FLUENTCART_ABILITIES_USERNAME=fluentcart-abilities-reader
FLUENTCART_ABILITIES_APP_PASSWORD="paste its separate Application Password here"
```

Use a separate Application Password for that principal. The bridge never reuses
`FLUENTCART_USERNAME` or `FLUENTCART_APP_PASSWORD`, discovers the live catalogue at startup, and
intersects it with the audited read names. Unknown abilities and native writes stay absent. The
external FluentCart MCP adapter is not required.

Some captured FluentCart releases omit the WordPress read-only annotation on otherwise-safe reads.
The compatibility workaround admits one only when its canonical name, input schema and execution
metadata match the captured fingerprint. Any drift is fail-closed: the Ability stays absent instead
of being promoted to “probably read-only”, the traditional prelude to a very long afternoon.

### 2. Config File

Save this as `~/.config/fluentcart-mcp/config.json` on macOS/Linux or
`%APPDATA%\fluentcart-mcp\config.json` on Windows:

```json
{
  "url": "https://your-store.com",
  "username": "fluentcart-reader",
  "appPassword": "paste the generated Application Password here"
}
```

### 3. Setup Wizard for local Node.js 24+ clients

```bash
npx -y fluentcart-mcp setup
```

## Transports

| Transport | Flag | Use Case |
|-----------|------|----------|
| **stdio** (default) | — | Local clients using the recipes above |
| **HTTP** | `--transport http` | Generic private HTTP clients, VPS deployments, Docker |

HTTP transport uses Streamable HTTP on port 3000 and has two profiles. The **local profile** binds
loopback for one machine. The **private profile** is required for a non-loopback bind and refuses
to listen without a 32-byte bearer key plus explicit Host and Origin allowlists. The static bearer
maps to one configured WordPress principal; this release has no OAuth and no multi-user identity mapping.
Run separate processes when separate callers need separate WordPress principals.

## Toolset Modes

| Mode | Flag | Client surface |
|------|------|----------------|
| **dynamic** (default) | — | Three read meta-tools; reversible mode adds its write executor |
| **curated** | `--mode curated` | A reviewed shortlist of common shop tools |
| **code** | `--mode code` | Search plus read-only JavaScript in a WASM sandbox |
| **full** | `--mode full` | Every definition admitted for this store, role and write policy |

Measured counts and context costs come from the built server's `tools/list` response. See
`release-contract.json` for the current matrix instead of copying its numbers into configuration
guidance.

Dynamic mode is the default because it keeps the definition payload small no matter how large the store API grows: the AI searches for what it needs and executes it, rather than loading everything upfront.

When the native Abilities bridge is enabled, full and curated mode list its three read-only
meta-tools directly. Dynamic and code mode can find and call the same tools through their existing
search-and-execute surfaces. If discovery or its dedicated credentials fail, startup fails closed
instead of quietly pretending the optional surface works.

## Write Modes

Writes are absent, not merely disabled. Under the default
`FLUENTCART_WRITE_MODE=disabled`, a write tool cannot be listed, searched, described or called by
name. Capability discovery then prunes the reviewed read surface to the connected store and role.

| `FLUENTCART_WRITE_MODE` | What it adds |
|-------------------------|--------------|
| `disabled` (default) | Reads only |
| `reversible` | Writes with a verified read-back and a supported undo |

Refunds, subscription cancellation, deletions, bulk mutations, order-status changes, marking an
order paid and disputes are not product tools in this release. No mode or environment variable enables
them.

The native Abilities bridge does not bypass this policy. `refund-order`, order and subscription
status changes, label and note writes, coupon management, and customer upserts are never available
through its generic executor. The exact comparison is recorded in
`compatibility-support.json`.

## What's Inside

The reviewed source is grouped by business area. How many tools you see depends on write mode,
the routes your store serves and the configured WordPress role. The generated
`release-contract.json` is the count and context-cost authority.

| Module | Tools | What It Covers |
|--------|-------|----------------|
| **Orders** | — | Read orders, transactions, addresses and activity |
| **Products** | — | Read products and pricing; reversibly create or update products and variants |
| **Customers** | — | Read profiles and history; reversibly create or update customers |
| **Subscriptions & renewals** | — | List and inspect subscriptions, inspect individual renewals, and update only a subscription's guarded billing-cycle limit when reversible mode permits it. |
| **Coupons** | — | Read coupons; reversibly create or update them |
| **Reports** | — | Revenue, sales, growth, retention, cohorts and customer insights |
| **Shipping** | — | Read and reversibly manage zones, methods and classes |
| **Tax** | — | Read and reversibly manage classes, rates and reviewed settings |
| **Configuration** | — | Read store, email, role, integration and reference settings |

The server also ships resources and prompts for common store-analysis workflows. Check the built
manifest for the exact packaged surface.

Subscription gateway resync is deliberately absent. In FluentCart 1.6 it can contact the payment
gateway and change local subscription state, so it is not a read and does not meet the reversible
write contract. Use the FluentCart admin for that job.

## Example Prompts

Once connected, just talk. The read-only examples work out of the box; the ones that change something need `FLUENTCART_WRITE_MODE` raised first.

- "Show me today's orders"
- "What's my revenue this month?"
- "Create a 20% off coupon that expires Friday"
- "Find customer john@example.com and show their order history"
- "Show subscriptions that are currently paused"
- "Show the renewals due next week and open the details for the ones that need attention"
- "Which products sold the most this week?"
- "Show me the transactions on order #1234"
- "Set up 23% VAT for Poland"
- "Create a shipping zone for Europe at €5 flat rate"
- "Show me all email notification templates"

## Requirements

- **Node.js** >= 24.0.0 (for npx/stdio mode)
- **Docker** (for HTTP/container mode — no Node.js needed)
- **WordPress** with [FluentCart](https://fluentcart.com) installed. The verified 2.1.0 release
  combination is WordPress 7.0.2, FluentCart Core 1.6.0 and FluentCart Pro 1.6.0. Pro is optional
  for normal connection; it adds only the Pro-backed routes your store serves.
- A **WordPress account** with an Application Password and the FluentCart REST capabilities you plan to use

## Upgrading to 2.0

2.0 is deliberately breaking: Node 24 is required for local clients, the modular MCP SDK v2 packages replace the
legacy SDK, dynamic mode is the default, both protocol eras above are supported, and private HTTP
requires key and allowlists before listen. For a local Node.js 24+ client that is not ready, keep the
1.x line explicit:

```bash
npx -y fluentcart-mcp@1
```

Release evidence is generated in `release-contract.json`. It records the supported protocols,
measured presentation profiles and automated candidate handshakes. ChatGPT Desktop, Codex CLI,
Codex IDE extension, Claude Desktop, Cursor, VS Code with GitHub Copilot, Windsurf and ChatGPT web
through Secure MCP Tunnel are documented configuration targets; recipes are not silently promoted
to certified clients.

## Documentation

Full docs with setup guides, usage examples, tool reference, deployment guide, and troubleshooting:

**[fchub.co/docs/fluentcart-mcp](https://fchub.co/docs/fluentcart-mcp)**

## Links

- [Documentation](https://fchub.co/docs/fluentcart-mcp)
- [Setup Guide](https://fchub.co/docs/fluentcart-mcp/setup)
- [Tool Reference](https://fchub.co/docs/fluentcart-mcp/tools)
- [Troubleshooting](https://fchub.co/docs/fluentcart-mcp/troubleshooting)
- [npm Package](https://www.npmjs.com/package/fluentcart-mcp)
- [Docker Hub](https://hub.docker.com/r/vcodesh/fluentcart-mcp)
- [GitHub Issues](https://github.com/vcode-sh/fchub-plugins/issues)

## License

MIT — [Vibe Code](https://vcode.sh)
