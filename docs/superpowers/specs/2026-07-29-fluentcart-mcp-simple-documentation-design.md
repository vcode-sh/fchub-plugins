# FluentCart MCP Simple-First Documentation Design

## Status

Approved direction: standalone beginner guides with a clearly separated advanced section.

## Purpose

Make FluentCart MCP understandable and usable by a complete beginner without removing the
configuration, security, deployment, evidence, and release material advanced users rely on.

The first successful connection must feel like one small task:

1. choose an AI client;
2. give it safe access to one FluentCart store;
3. follow one self-contained recipe;
4. ask one read-only verification question.

Everything else remains available after that success, not before it.

## Product Principles

### Simple does not mean incomplete

Beginner pages remove decisions, cross-references, and unexplained terminology. They do not hide
material privacy or security facts. Advanced pages preserve operational detail without interrupting
the first-run journey.

### One decision must produce one useful route

The first client chooser links directly to a standalone recipe. It must not send every reader to the
same default tab or require horizontal scrolling to discover hidden clients.

### Each beginner recipe is self-contained

A reader must not assemble a setup from a shared prerequisites section, a separate client tab, and a
troubleshooting page. Each primary recipe repeats the few required preparation steps, the exact
client configuration, the verification prompt, and the next recovery action.

### Evidence before reassurance

A green client indicator shows that a process started. Returned FluentCart dashboard data proves the
store connection. The documentation must keep that distinction.

### Advanced users remain first-class readers

Presentation modes, reversible writes, Native Abilities, multiple stores, local path debugging,
Claude Code, Codex CLI, VS Code, Windsurf, ChatGPT web, Docker, private HTTP, security controls,
evidence, release mechanics, and compatibility boundaries remain documented and searchable.

## Audience Model

### Beginner

A shop owner who uses WordPress and an AI desktop application but may not know what MCP, Node.js,
STDIO, a configuration file, a shell, or JSON means.

### Experienced client user

A user comfortable with a terminal or editor configuration who wants an exact command and a concise
explanation of credential storage and permissions.

### Operator or contributor

A user deploying HTTP or Docker, managing several stores, enabling reversible writes or Native
Abilities, investigating protocol evidence, or maintaining releases.

## Information Architecture

Use flat, stable documentation routes to avoid unverified nested-route behaviour.

### Getting Started

- `index.mdx` — plain-language overview and one client choice.
- `setup.mdx` — compatibility landing page and client chooser for existing inbound links.
- `chatgpt-desktop.mdx` — self-contained ChatGPT Desktop and shared Codex configuration guide.
- `claude-desktop.mdx` — self-contained MCPB guide, labelled as the easiest no-Terminal route.
- `cursor.mdx` — self-contained Cursor guide.
- `other-clients.mdx` — Claude Code, Codex CLI/IDE, VS Code, and Windsurf recipes.

### Use

- `usage.mdx` — safe prompts and merchant workflows.

### Advanced

- `configuration.mdx` — credential locations, absolute `npx` paths, presentation modes, reversible
  writes, Native Abilities, multiple stores, rotation, and privacy boundaries.
- `tools.mdx` — exact capability and policy reference.
- `chatgpt-web.mdx` — Secure MCP Tunnel as a distinct web connection.
- `deployment.mdx` — Docker and private HTTP only.

### Evidence and Support

- `proof.mdx` — release evidence and measured boundaries.
- `troubleshooting.mdx` — diagnosis organised by the stage that failed.

### Releases

- `changelog.mdx` and `_changelog/*` — current index and immutable historical entries.

Release recovery belongs in this monorepo's tracked `AGENTS.md`, not in the public merchant
deployment journey. The similarly named developer manual belongs to the sibling playground
repository; this plan neither duplicates nor edits it.

## Beginner Landing Page

The page starts with:

> Connect the AI app you already use to one FluentCart store. It reads by default and cannot refund,
> cancel, delete, or move money.

It then presents four direct choices:

1. **Claude Desktop** — “Easiest: no Terminal or separate Node.js.”
2. **ChatGPT Desktop** — “Use the MCP server already supported by ChatGPT and Codex.”
3. **Cursor** — “Add one global MCP server.”
4. **Other clients** — “Claude Code, Codex CLI/IDE, VS Code, and Windsurf.”

ChatGPT web is shown separately as an advanced private connection because it cannot run a local
command or read desktop configuration.

The landing page contains no source-definition count, protocol detail, candidate-certification
language, presentation-mode table, environment-variable table, or deployment instruction.

## Beginner Recipe Contract

Every primary client page follows the same visible structure:

1. **What you need**
   - WordPress with FluentCart active.
   - A dedicated WordPress user that can read the FluentCart information the owner intends to share.
   - HTTPS unless the guide explicitly describes a local development exception.
2. **Create safe store access**
   - Exact WordPress clicks for creating an Application Password.
   - Explain that this is not the normal WordPress login password.
   - Explain that WordPress permissions determine readable data and write mode determines exposed
     mutations.
   - State that returned store data is processed by the selected AI client.
3. **Connect the client**
   - Exact clicks or commands for this client only.
   - Define a technical term only when the reader encounters it.
4. **Confirm it works**
   - Ask: “Show me the FluentCart dashboard stats.”
   - Explain what successful store data looks like.
5. **If it does not work**
   - Link directly to the relevant troubleshooting stage.
6. **Optional next step**
   - Link to usage examples.
   - Link advanced users to configuration.

The Claude Desktop page does not instruct extension users to install Node.js. It explains that the
MCPB is the extension file and that Claude Desktop supplies the runtime.

Local `npx` pages explain Node.js and the Terminal in one sentence, state that `npx -y` downloads on
demand without a global install, and include an absolute-path recovery for GUI applications that do
not inherit the shell PATH.

Optional OpenAI repository-marketplace installation does not interrupt the ChatGPT Desktop direct
setup. It lives under “Other ways to connect” on the advanced configuration page.

## Required Versus Verified Software

Beginner pages distinguish:

### Required

- WordPress;
- FluentCart active;
- an HTTPS-reachable WordPress REST API for non-local stores;
- a WordPress user with the intended FluentCart read permissions;
- Node.js 24 or newer only for local `npx` clients.

### Verified release stack

- WordPress 7.0.2;
- FluentCart 1.5.5;
- FluentCart Pro 1.5.4.

FluentCart Pro is not presented as a universal prerequisite. It contributes Pro-backed routes when
installed. Older FluentCart route-surface evidence is not described as tool compatibility or
support.

## Safety and Privacy Language

Replace “blast radius roughly zero” and similar absolutes with explicit boundaries:

- A dedicated WordPress user limits which store data the MCP server can read.
- Read-only mode prevents FluentCart mutations; it does not make customer or commercial data
  non-sensitive.
- The configured AI client receives the returned store data.
- Reversible writes are an advanced opt-in.
- Refunds, subscription cancellation, deletion, bulk operations, order-status changes, marking an
  order paid, and other money-moving operations remain unavailable.

Examples use a neutral dedicated username such as `fluentcart-reader`, not `admin`.

## Troubleshooting Design

Organise the page around where setup failed:

1. The credential wizard cannot reach the store.
2. The client cannot start the server.
3. The server connects but no store data returns.
4. One expected tool is missing.
5. Advanced HTTP, proxy, certificate, or payload issues.

The first diagnostic is the setup wizard. Do not place a real Application Password in a command
argument. If an advanced curl check remains, use an interactive password prompt and explain the
history/process-list risk.

Do not ask readers to paste credential-bearing JSON into a third-party validator. Prefer a local
JSON validation command or require complete redaction first.

Every issue has a stable heading anchor. Absolute claims such as “every failure mode” become “common
failure modes.”

The global `NODE_TLS_REJECT_UNAUTHORIZED=0` workaround is not a normal fix. Lead with a valid
certificate or trusted local CA. If retained, the bypass is a last, temporary, local-only diagnostic
with an explicit restart/removal step.

## Deployment Design

### ChatGPT web

Move the Secure MCP Tunnel guide to `chatgpt-web.mdx`. Explain the desktop/web distinction before
any command. Keep OpenAI control-plane authentication separate from FluentCart HTTP bearer
authentication.

### Docker and private HTTP

Generate the bearer key once, preserve it in a secret store, and use the same value in the server and
client configuration. Examples must not generate a value inline and then discard it.

Bind the container to `127.0.0.1:3000:3000` when a same-host proxy or tunnel is the intended public
entry point. Non-loopback examples require the bearer key and explicit Host and Origin allowlists.

Dokploy, Cloudflare Tunnel, Compose, health checks, HTTP client configuration, static-principal
limits, and environment-variable reference remain in the advanced deployment page.

## Blog and Marketing Truth

Audit every current-facing blog and marketing mention, including:

- `web-docs/content/blog/fluentcart-mcp-vs-official-mcp.mdx`;
- `web-docs/app/(home)/fluentcart-mcp/page.tsx`;
- `web-docs/app/(home)/fluentcart-mcp/layout.tsx`;
- shared home resource links and metadata containing FluentCart MCP claims.

The comparison article must:

- identify the official FluentCart MCP and the independent FluentCart MCP unambiguously;
- state that the independent server is optional and is not required to use FluentCart or the
  official MCP;
- avoid winner language and unverified official-product claims;
- distinguish source definitions, measured profiles, and client-visible tools;
- avoid evergreen prose tied to a release-specific count where the generated contract can change;
- link beginner readers directly to the correct setup chooser or client page;
- link write-mode discussion to advanced configuration, not the generic first-run page;
- keep refunds, cancellations, destructive operations, and money-moving work unavailable;
- describe setup recipes as recipes, not broad client certification.

The marketing layout must not present the source-definition inventory as an unqualified number of
available tools. The homepage CTA leads to the simple client chooser rather than a Terminal command
as the primary action.

## Current Truth Corrections

Implementation must correct all current-facing inconsistencies found during the audit:

- stale `2.0.0` references outside historical changelog entries;
- unavailable file-upload prompts;
- the incorrect claim that reviewed global tax settings cannot be saved in reversible mode;
- blanket pagination claims that do not match every tool schema;
- the unqualified source-definition count in SEO metadata;
- the compatibility-support statement that could be read as support for the captured 1.3.9 route
  surface;
- inconsistent trailing-slash guidance;
- unsafe Docker bearer-key handling and public port examples;
- unsafe credential-bearing curl and external JSON-validator instructions.

Historical changelog fragments remain unchanged.

## Documentation Truth Gates

Use test-first changes.

### Dynamic current-page discovery

The documentation checker discovers every tracked current-facing FluentCart MCP MDX page and excludes
only `_changelog/*`. It also covers:

- the public proof page;
- the public landing page and layout metadata;
- relevant shared marketing/resource components;
- root and package READMEs;
- all tracked `AGENTS.md`;
- optional local `CLAUDE.md`;
- the official-versus-independent comparison article.

### Beginner journey contracts

Tests require:

- every primary chooser link points to a distinct existing page;
- every primary recipe contains preparation, client connection, verification, troubleshooting, and
  advanced-next-step sections;
- Claude Desktop explicitly requires no separate Node installation;
- local `npx` recipes state Node 24+, no global package installation, and GUI PATH recovery;
- ChatGPT Desktop and ChatGPT web remain separate routes;
- the verification prompt is consistent;
- no beginner or troubleshooting example embeds an Application Password in a command;
- no beginner marketing metadata claims the source-definition count as available tools.

### Cross-surface truth

Tests check:

- version parity across package, manifest, release contract, compatibility support, and web-docs;
- protocol, HTTP profile, client-evidence, and legacy-support parity;
- every generated client configuration target has a setup recipe and verification step;
- setup recipes are not labelled as release certification;
- current-facing hard-coded package versions are absent outside approved generated/version
  components;
- usage prompts do not offer `execution: none` operations;
- file upload is unavailable;
- reviewed global tax-settings save is reversible;
- tool-specific pagination guidance is schema-aware.

### CI

Docs CI runs both the scanner and its unit tests. Its path filters include the scanner, rule
catalogue, tests, compatibility truth, agent guidance, public layouts, marketing components, blog
article, and every FluentCart MCP documentation page.

## Verification

Before completion:

1. prove each new documentation contract fails against the old structure;
2. make the minimal documentation and checker changes;
3. run the focused documentation tests;
4. run the complete documentation scanner;
5. lint all web-docs files;
6. build the production documentation site;
7. inspect desktop and 390-pixel mobile rendering;
8. verify every primary client choice reaches the correct visible recipe in one decision;
9. verify all internal and external links used by the beginner flow;
10. confirm README, package README, AGENTS, CLAUDE, marketing, blog, docs, version truth, and CI
    guidance agree;
11. obtain an independent beginner-flow and advanced-coverage review.

## Non-Goals

- Building an interactive JavaScript setup wizard.
- Claiming universal client certification.
- Hiding advanced configuration or evidence.
- Rewriting historical changelog entries.
- Enabling writes or changing runtime behaviour.
- Creating a public universal ChatGPT plugin-directory listing.
