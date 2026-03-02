# fluentcart-mcp

I built an MCP server for [FluentCart](https://fluentcart.com). It gives AI assistants direct access to your store — orders, products, customers, subscriptions, coupons, reports, the lot. 194 tools (or 3, if you're feeling dynamic), open source, MIT licensed.

Works with Claude Desktop, Claude Code, Cursor, VS Code + Copilot, Windsurf, Codex CLI, ChatGPT, and anything else that speaks MCP.

## Quick Start

### Claude Desktop

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

For remote access, ChatGPT, or always-on deployments — run the HTTP transport in Docker:

```bash
docker run -d \
  -p 3000:3000 \
  -e FLUENTCART_URL=https://your-store.com \
  -e FLUENTCART_USERNAME=admin \
  -e FLUENTCART_APP_PASSWORD="aBcD eFgH iJkL mNoP qRsT uVwX" \
  -e FLUENTCART_MCP_API_KEY=your-secret-key \
  vcodesh/fluentcart-mcp
```

Your MCP endpoint is now at `http://localhost:3000/mcp`. Point ChatGPT or any HTTP-capable MCP client at it.

The `FLUENTCART_MCP_API_KEY` is optional but recommended — if set, every request needs `Authorization: Bearer your-secret-key`. Without it, anyone who finds your endpoint can talk to your store. Your call.

Also available on GitHub Container Registry: `ghcr.io/vcode-sh/fluentcart-mcp`.

## Authentication

Uses **WordPress Application Passwords** (built into WordPress 5.6+). Zero plugin dependencies.

1. WordPress admin → **Users → Profile**
2. Scroll to **Application Passwords**
3. Enter a name, click **Add New Application Password**
4. Copy the password (WordPress shows it exactly once)

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
// ~/.config/fluentcart-mcp/config.json
{
  "url": "https://your-store.com",
  "username": "admin",
  "appPassword": "aBcD eFgH iJkL mNoP qRsT uVwX"
}
```

### 3. Interactive Setup

```bash
npx fluentcart-mcp setup
```

## Transports

| Transport | Flag | Use Case |
|-----------|------|----------|
| **stdio** (default) | — | Local clients: Claude Desktop, Cursor, VS Code |
| **HTTP** | `--transport http` | Remote clients: ChatGPT, VPS deployments, Docker |

HTTP transport uses Streamable HTTP on port 3000 (configurable with `--port` and `--host`).

### Toolset Modes

| Mode | Flag | Tools | Token Cost |
|------|------|-------|------------|
| **static** (default) | — | 194 tools registered upfront | ~20K tokens |
| **dynamic** | `--mode dynamic` | 3 meta-tools (search, describe, execute) | ~1.5K tokens |

Dynamic mode gives the AI 3 tools to discover and execute any of the 194 tools on demand. Same capabilities, ~96% fewer tokens in context. Trade-off: 2-3 extra tool calls per workflow.

## What's Inside

194 tools across 17 modules:

| Module | Tools | What It Covers |
|--------|-------|----------------|
| **Orders** | 23 | List, create, update, refund, disputes, bulk actions |
| **Products** | 53 | CRUD, pricing, variants, downloads, categories |
| **Customers** | 17 | Profiles, addresses, stats, lifetime value |
| **Subscriptions** | 7 | List, pause, resume, cancel, reactivate |
| **Coupons** | 11 | Create, apply, eligibility, settings |
| **Reports** | 27 | Revenue, sales, top products, customer insights |
| **Order Bumps** | 5 | Upsell management |
| **Product Options** | 10 | Attribute groups and terms |
| **Integrations** | 12 | Addon and feed management |
| **Settings** | 8 | Store config, payment methods, permissions |
| **Labels** | 3 | Order organisation |
| **Activity** | 3 | Audit log |
| **Notes** | 1 | Order annotations |
| **Dashboard** | 2 | Overview stats |
| **Application** | 4 | App init, widgets |
| **Public** | 4 | Unauthenticated product views |
| **Miscellaneous** | 4 | Country/form lookups |

Every tool has rich descriptions with business context, validated parameters via Zod, and AI-friendly annotations (read-only, destructive, idempotent hints).

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

## Requirements

- **Node.js** >= 22.0.0 (for npx/stdio mode)
- **Docker** (for HTTP/container mode — no Node.js needed)
- **WordPress** >= 5.6 with FluentCart installed
- **Administrator** WordPress account

## Documentation

Full docs with setup guides for every platform, usage examples, deployment guides, and troubleshooting:

**[fchub.co/docs/fluentcart-mcp](https://fchub.co/docs/fluentcart-mcp)**

## License

MIT — [Vibe Code](https://vcode.sh)
