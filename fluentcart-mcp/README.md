# FluentCart MCP Server

[![npm](https://img.shields.io/npm/v/fluentcart-mcp)](https://www.npmjs.com/package/fluentcart-mcp)
[![Node.js](https://img.shields.io/badge/node-%3E%3D22-brightgreen)](https://nodejs.org)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

An MCP server that gives AI assistants full access to your [FluentCart](https://fluentcart.com) store. 276 tools across 27 modules — orders, products, customers, subscriptions, coupons, reports, shipping, tax, email notifications, and more. Open source, MIT licensed.

Works with Claude Desktop, Claude Code, Cursor, VS Code + Copilot, Windsurf, Codex CLI, ChatGPT, and anything else that speaks [MCP](https://modelcontextprotocol.io).

## Quick Start

### Claude Desktop — One Click

Download the extension — no Node.js, no JSON, no terminal:

**[Download fluentcart-mcp.mcpb](https://github.com/vcode-sh/fchub-plugins/releases/download/fluentcart-mcp/v1.0.0/fluentcart-mcp.mcpb)**

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

Use an **Administrator** account — FluentCart's API requires admin capabilities.

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

HTTP transport uses Streamable HTTP on port 3000 (configurable with `--port` and `--host`).

## Toolset Modes

| Mode | Flag | Tools | Token Cost |
|------|------|-------|------------|
| **static** (default) | — | All 276 tools registered upfront | ~30K tokens |
| **dynamic** | `--mode dynamic` | 3 meta-tools (search, describe, execute) | ~1.5K tokens |

Dynamic mode gives the AI 3 tools to discover and execute any of the 276 tools on demand. Same capabilities, ~96% fewer tokens in context.

## What's Inside

276 tools across 27 modules:

| Module | Tools | What It Covers |
|--------|-------|----------------|
| **Orders** | 23 | List, create, update, refund, disputes, bulk actions |
| **Products** | 52 | CRUD, pricing, variants, downloads, categories |
| **Customers** | 19 | Profiles, addresses, stats, lifetime value |
| **Subscriptions** | 7 | List, pause, resume, cancel, reactivate |
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

Plus: **4 MCP Resources** (store config, countries, payment methods, filter options), **5 MCP Prompts** (store analysis, order investigation, customer overview, catalog summary, subscription health), and in-memory caching for static data.

## Example Prompts

Once connected, just talk:

- "Show me today's orders"
- "What's my revenue this month?"
- "Create a 20% off coupon that expires Friday"
- "Find customer john@example.com and show their order history"
- "Pause subscription #42"
- "Which products sold the most this week?"
- "Refund order #1234"
- "Set up 23% VAT for Poland"
- "Create a shipping zone for Europe at €5 flat rate"
- "Show me all email notification templates"

## Requirements

- **Node.js** >= 22.0.0 (for npx/stdio mode)
- **Docker** (for HTTP/container mode — no Node.js needed)
- **WordPress** >= 5.6 with [FluentCart](https://fluentcart.com) installed
- **Administrator** WordPress account with an Application Password

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
