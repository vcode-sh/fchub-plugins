# FluentCart MCP Server

[![npm](https://img.shields.io/npm/v/fluentcart-mcp)](https://www.npmjs.com/package/fluentcart-mcp)
[![Node.js](https://img.shields.io/badge/node-%3E%3D24-brightgreen)](https://nodejs.org)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

An MCP server that connects AI assistants to your [FluentCart](https://fluentcart.com) store — orders, products, customers, subscriptions, coupons, reports, shipping, tax, email notifications, and more. Read-only until you deliberately say otherwise. Open source, MIT licensed.

The server speaks MCP `2025-11-25` and `2026-07-28`. The recipes below are setup guidance, not
client certification. Candidate certification requires a candidate-bound handshake from MCP Inspector
(stdio and HTTP), Claude Code (stdio and HTTP), and Docker HTTP.

## Quick Start

### Claude Desktop — One Click

Download the extension — no JSON editing or terminal configuration. Node.js 24+ is still required
because the bundle launches the Node server declared in its manifest:

**[Download the latest fluentcart-mcp.mcpb](https://github.com/vcode-sh/fchub-plugins/releases?q=fluentcart-mcp&expanded=true)**

Double-click the file. Claude Desktop prompts for your WordPress URL, username, and Application Password. Fill those in, then confirm the connection in Claude Desktop.

### Setup Wizard

```bash
npx fluentcart-mcp setup
```

Asks three questions, tests the connection, saves the config. Your AI client reads the saved credentials automatically.

### Claude Desktop — Manual Config

Add to `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "fluentcart": {
      "command": "npx",
      "args": ["-y", "fluentcart-mcp"],
      "env": {
        "FLUENTCART_URL": "https://your-store.com",
        "FLUENTCART_USERNAME": "admin",
        "FLUENTCART_APP_PASSWORD": "aBcD eFgH iJkL mNoP qRsT uVwX"
      }
    }
  }
}
```

### Claude Code

```bash
claude mcp add fluentcart \
  -e FLUENTCART_URL=https://your-store.com \
  -e FLUENTCART_USERNAME=admin \
  -e FLUENTCART_APP_PASSWORD="aBcD eFgH iJkL mNoP qRsT uVwX" \
  -- npx -y fluentcart-mcp
```

### Cursor / VS Code / Windsurf

Same JSON config as Claude Desktop — paste into your MCP settings file. [Full setup guide](https://fchub.co/docs/fluentcart-mcp/setup) has platform-specific paths.

### Docker

For a local or always-on endpoint on the same machine, use the Docker recipe below. For a public
hostname, follow the deployment guide and replace the allowlists with that hostname. ChatGPT is
deployment guidance only, not a certified client.

```bash
MCP_API_KEY="$(openssl rand -hex 32)"
docker run -d \
  -p 3000:3000 \
  -e FLUENTCART_URL=https://your-store.com \
  -e FLUENTCART_USERNAME=admin \
  -e FLUENTCART_APP_PASSWORD="aBcD eFgH iJkL mNoP qRsT uVwX" \
  -e FLUENTCART_MCP_API_KEY="$MCP_API_KEY" \
  -e FLUENTCART_MCP_ALLOWED_HOSTS=localhost \
  -e FLUENTCART_MCP_ALLOWED_ORIGINS=localhost \
  vcodesh/fluentcart-mcp
```

Your MCP endpoint is at `http://localhost:3000/mcp`. Also available on GHCR: `ghcr.io/vcode-sh/fluentcart-mcp`.

## Authentication

Uses **WordPress Application Passwords** (built into WordPress 5.6+). No extra plugins needed.

1. WordPress admin → **Users → Profile**
2. Scroll to **Application Passwords**
3. Enter a name, click **Add New Application Password**
4. Copy the password (WordPress shows it once)

Pick a role that carries the FluentCart REST capabilities you actually intend to use. An admin account works, but it is not the only option: the server discovers what your role can reach and exposes nothing beyond it, so a narrower account is the safer choice.

The server keeps some authorised configuration and reference reads in memory for at most 60 seconds. Restart the MCP process after rotating or revoking its Application Password to purge those responses immediately; credentials are loaded once at startup and are never hot-reloaded.

## Configuration

Three options, checked in this order:

### 1. Environment Variables

```bash
FLUENTCART_URL=https://your-store.com
FLUENTCART_USERNAME=admin
FLUENTCART_APP_PASSWORD=aBcD eFgH iJkL mNoP qRsT uVwX
```

FluentCart 1.5.4+ also supplies native WordPress Abilities for richer analytics and advanced
queries. They are an explicit, read-only opt-in:

```bash
FLUENTCART_ABILITIES_MODE=enabled
FLUENTCART_ABILITIES_USERNAME=ability-reader
FLUENTCART_ABILITIES_APP_PASSWORD=aBcD eFgH iJkL mNoP qRsT uVwX
```

Use a separate Application Password for that principal. The bridge never reuses
`FLUENTCART_USERNAME` or `FLUENTCART_APP_PASSWORD`, discovers the live catalogue at startup, and
intersects it with the read names audited against FluentCart 1.5.5. Unknown abilities and native
writes stay absent. The external FluentCart MCP adapter is not required.

FluentCart 1.5.5 omits the WordPress read-only annotation on some reads. The compatibility
workaround admits one only when its canonical name, input schema and execution metadata match the
captured fingerprint. Any drift is fail-closed: the Ability stays absent instead of being promoted
to “probably read-only”, the traditional prelude to a very long afternoon.

### 2. Config File

```json
// ~/.config/fluentcart-mcp/config.json (macOS/Linux)
// %APPDATA%\fluentcart-mcp\config.json (Windows)
{
  "url": "https://your-store.com",
  "username": "admin",
  "appPassword": "aBcD eFgH iJkL mNoP qRsT uVwX"
}
```

### 3. Setup Wizard

```bash
npx fluentcart-mcp setup
```

## Transports

| Transport | Flag | Use Case |
|-----------|------|----------|
| **stdio** (default) | — | Local clients: Claude Desktop, Cursor, VS Code |
| **HTTP** | `--transport http` | Remote clients: ChatGPT, VPS deployments, Docker |

HTTP transport uses Streamable HTTP on port 3000 and has two profiles. The **local profile** binds
loopback for one machine. The **private profile** is required for a non-loopback bind and refuses
to listen without a 32-byte bearer key plus explicit Host and Origin allowlists. The static bearer
maps to one configured WordPress principal; 2.0.0 has no OAuth and no multi-user identity mapping.
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
order paid and disputes are not product tools in 2.0.0. No mode or environment variable enables
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
| **Subscriptions** | — | List and inspect subscriptions. Pause, resume, reactivate and cancellation are not shipped actions. |
| **Coupons** | — | Read coupons; reversibly create or update them |
| **Reports** | — | Revenue, sales, growth, retention, cohorts and customer insights |
| **Shipping** | — | Read and reversibly manage zones, methods and classes |
| **Tax** | — | Read and reversibly manage classes, rates and reviewed settings |
| **Configuration** | — | Read store, email, role, integration and reference settings |

The server also ships resources and prompts for common store-analysis workflows. Check the built
manifest for the exact packaged surface.

## Example Prompts

Once connected, just talk. The read-only examples work out of the box; the ones that change something need `FLUENTCART_WRITE_MODE` raised first.

- "Show me today's orders"
- "What's my revenue this month?"
- "Create a 20% off coupon that expires Friday"
- "Find customer john@example.com and show their order history"
- "Show subscriptions that are currently paused"
- "Which products sold the most this week?"
- "Show me the transactions on order #1234"
- "Set up 23% VAT for Poland"
- "Create a shipping zone for Europe at €5 flat rate"
- "Show me all email notification templates"

## Requirements

- **Node.js** >= 24.0.0 (for npx/stdio mode)
- **Docker** (for HTTP/container mode — no Node.js needed)
- **WordPress** with [FluentCart](https://fluentcart.com) installed; the verified release
  combination is WordPress 7.0.2, FluentCart 1.5.5 and FluentCart Pro 1.5.4
- A **WordPress account** with an Application Password and the FluentCart REST capabilities you plan to use

## Upgrading to 2.0

2.0.0 is deliberately breaking: Node 24 is required, the modular MCP SDK v2 packages replace the
legacy SDK, dynamic mode is the default, both protocol eras above are supported, and private HTTP
requires key and allowlists before listen. If a client is not ready, keep the 1.x line explicit:

```bash
npx -y fluentcart-mcp@1
```

Release evidence is generated in `release-contract.json`. It records the supported protocols,
measured presentation profiles and the five automated candidate handshakes. Claude Desktop, Cursor,
VS Code, Windsurf and Codex CLI use configuration recipes; they are not silently promoted to
certified clients.

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
