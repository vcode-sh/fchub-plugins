# FluentCart MCP Server

[![npm](https://img.shields.io/npm/v/fluentcart-mcp)](https://www.npmjs.com/package/fluentcart-mcp)
[![Node.js](https://img.shields.io/badge/node-%3E%3D22-brightgreen)](https://nodejs.org)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

An MCP server that connects AI assistants to your [FluentCart](https://fluentcart.com) store — orders, products, customers, subscriptions, coupons, reports, shipping, tax, email notifications, and more. Read-only until you deliberately say otherwise. Open source, MIT licensed.

Works with Claude Desktop, Claude Code, Cursor, VS Code + Copilot, Windsurf, Codex CLI, ChatGPT, and anything else that speaks [MCP](https://modelcontextprotocol.io).

## Quick Start

### Claude Desktop — One Click

Download the extension — no Node.js, no JSON, no terminal:

**[Download the latest fluentcart-mcp.mcpb](https://github.com/vcode-sh/fchub-plugins/releases?q=fluentcart-mcp&expanded=true)**

Double-click the file. Claude Desktop prompts for your WordPress URL, username, and Application Password. Fill those in. Done.

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

For remote access, ChatGPT, or always-on deployments:

```bash
docker run -d \
  -p 3000:3000 \
  -e FLUENTCART_URL=https://your-store.com \
  -e FLUENTCART_USERNAME=admin \
  -e FLUENTCART_APP_PASSWORD="aBcD eFgH iJkL mNoP qRsT uVwX" \
  -e FLUENTCART_MCP_API_KEY=your-secret-key \
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

## Configuration

Three options, checked in this order:

### 1. Environment Variables

```bash
FLUENTCART_URL=https://your-store.com
FLUENTCART_USERNAME=admin
FLUENTCART_APP_PASSWORD=aBcD eFgH iJkL mNoP qRsT uVwX
```

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

HTTP transport uses Streamable HTTP on port 3000 (configurable with `--port` and `--host`). It binds `127.0.0.1` by default. Ask it to bind anything else and it refuses to start unless `FLUENTCART_MCP_API_KEY` is set to at least 32 characters — an unauthenticated store API on a public interface is not a configuration anyone should be able to reach by accident.

## Toolset Modes

| Mode | Flag | Tools | Definition cost |
|------|------|-------|-----------------|
| **dynamic** (default) | — | 5 meta-tools: search, describe, and one executor per risk class | 863 tokens |
| **curated** | `--mode curated` | 18–20 reviewed everyday tools | 2,820–3,652 tokens |
| **code** | `--mode code` | 2 tools: search the API, run read-only JavaScript in a WASM sandbox | 532 tokens |
| **full** | `--mode full` | 148–174 tools, whatever the policy permits | 20,410–27,046 tokens |

Ranges span the default read-only policy and reversible write mode. Counts are measured from the built server's `tools/list` response with serializer `mcp-tools-list-v1` and tokenizer `gpt-tokenizer@3.4.0`; see `release-contract.json` for the full matrix.

Dynamic mode is the default because it keeps the definition payload small no matter how large the store API grows: the AI searches for what it needs and executes it, rather than loading everything upfront.

## Write Modes

Writes are absent, not merely disabled. Under the default `FLUENTCART_WRITE_MODE=disabled` a write tool cannot be listed, searched, described or called by name — 148 of the source tree's 276 definitions are exposed.

| `FLUENTCART_WRITE_MODE` | What it adds |
|-------------------------|--------------|
| `disabled` (default) | Reads only |
| `reversible` | Writes with a verified read-back and a supported undo |
| `guarded` | Same tools as `reversible` today. `FLUENTCART_GUARD_SECRET` and `FLUENTCART_GUARD_STATE_DIR` are reserved for the real-money guard and change nothing yet |

`fluentcart_order_refund` and `fluentcart_subscription_cancel` are classified `execution: none` in 2.0.0, so **no write mode exposes them** — not even a fully configured `guarded`. They need the signed-preview and durable-idempotency guard to be provable first, and until that lands they stay hidden rather than shipping a refund nobody can stop replaying.

## What's Inside

Tools are grouped into 27 modules. The source tree carries 276 definitions; how many you see depends on your write mode and on what your store and role actually support.

| Module | Tools | What It Covers |
|--------|-------|----------------|
| **Orders** | 23 | List, create, update, disputes, bulk actions |
| **Products** | 55 | CRUD, pricing, variants, downloads, categories |
| **Customers** | 19 | Profiles, addresses, stats, lifetime value |
| **Subscriptions** | 7 | List, pause, resume, reactivate |
| **Coupons** | 12 | Create, apply, eligibility, settings |
| **Reports (Core)** | 24 | Revenue, sales, dashboard, order charts |
| **Reports (Insights)** | 21 | Growth, retention, cohorts, heatmaps |
| **Shipping** | 15 | Zones, methods, classes |
| **Tax** | 22 | Classes, rates, EU VAT, records |
| **Email Notifications** | 8 | Templates, settings, toggles |
| **Roles** | 7 | Role management, user lists |
| **Order Bumps** | 5 | Upsell management |
| **Product Options** | 10 | Attribute groups and terms |
| **Integrations** | 12 | Addon and feed management |
| **Settings** | 14 | Store config, payment methods, modules |
| **Files** | 4 | Upload, list, delete |
| **Labels** | 3 | Order organisation |
| **Activity** | 3 | Audit log |
| **Notes** | 1 | Order annotations |
| **Dashboard** | 2 | Overview stats |
| **Application** | 4 | App init, widgets, attachments |
| **Public** | 4 | Unauthenticated product views |
| **Miscellaneous** | 4 | Country/form lookups |

Plus **4 MCP Resources** (store config, countries, payment methods, filter options) and **5 MCP Prompts** (store analysis, order investigation, customer overview, catalog summary, subscription health).

## Example Prompts

Once connected, just talk. The read-only examples work out of the box; the ones that change something need `FLUENTCART_WRITE_MODE` raised first.

- "Show me today's orders"
- "What's my revenue this month?"
- "Create a 20% off coupon that expires Friday"
- "Find customer john@example.com and show their order history"
- "Pause subscription #42"
- "Which products sold the most this week?"
- "Show me the transactions on order #1234"
- "Set up 23% VAT for Poland"
- "Create a shipping zone for Europe at €5 flat rate"
- "Show me all email notification templates"

## Requirements

- **Node.js** >= 24.0.0 (for npx/stdio mode)
- **Docker** (for HTTP/container mode — no Node.js needed)
- **WordPress** >= 6.9 with [FluentCart](https://fluentcart.com) installed
- A **WordPress account** with an Application Password and the FluentCart REST capabilities you plan to use

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
