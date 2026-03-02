# fluentcart-mcp

I built an MCP server for [FluentCart](https://fluentcart.com). It gives AI assistants direct access to your store — orders, products, customers, subscriptions, coupons, reports, the lot. 200+ tools, open source, MIT licensed.

Works with Claude Desktop, Claude Code, Cursor, VS Code + Copilot, Windsurf, Codex CLI, and anything else that speaks MCP.

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

## What's Inside

I built 200+ tools across 17 modules:

| Module | Tools | What It Covers |
|--------|-------|----------------|
| **Orders** | 23 | List, create, update, refund, disputes, bulk actions |
| **Products** | 53 | CRUD, pricing, variants, downloads, categories |
| **Customers** | 19 | Profiles, addresses, stats, lifetime value |
| **Subscriptions** | 7 | List, pause, resume, cancel, reactivate |
| **Coupons** | 12 | Create, apply, eligibility, settings |
| **Reports** | 31 | Revenue, sales, top products, customer insights |
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

- **Node.js** >= 22.0.0
- **WordPress** >= 5.6 with FluentCart installed
- **Administrator** WordPress account

## Documentation

Full docs with setup guides for every platform, usage examples, and troubleshooting:

**[fchub.co/docs/fluentcart-mcp](https://fchub.co/docs/fluentcart-mcp)**

## License

MIT — [Vibe Code](https://vcode.sh)
