# FCHub Plugins

All plugins, one MCP server, and a WooCommerce escape hatch — all crammed into a monorepo because FluentCart shipped a brilliant e-commerce platform and then left the ecosystem as empty as a WordPress plugin review queue on a Friday.

Everything's open source. Not because I'm noble. Because vendor lock-in is a personality disorder and I refuse to participate.

**[The full rant →](https://fchub.co/blog/fchub-manifesto)**

[![Sponsor vcode-sh](https://img.shields.io/badge/sponsor-vcode--sh-ea4aaa?style=for-the-badge&logo=github-sponsors&logoColor=white)](https://github.com/sponsors/vcode-sh)

This whole thing runs on caffeine and spite. If any of these plugins saved you from writing code, hiring a developer, or having a conversation with your accountant — [consider sponsoring](https://github.com/sponsors/vcode-sh). Or don't. I'll keep shipping anyway. Just slower. And angrier.

---

## The plugins

| Plugin | The damage | Needs |
|--------|-----------|-------|
| [fchub-p24](plugins/fchub-p24/) | Przelewy24 gateway. Stripe doesn't speak Polish, someone had to | FluentCart |
| [fchub-fakturownia](plugins/fchub-fakturownia/) | Fakturownia invoices + KSeF 2.0. Automate the paperwork before the tax office automates you | FluentCart |
| [fchub-memberships](plugins/fchub-memberships/) | A complete membership workspace: guided setup, protected content, member care, automation, integrations, and fewer mystery fires | FluentCart |
| [fchub-multi-currency](plugins/fchub-multi-currency/) | Display-layer multi-currency with exchange rates. Not everyone lives in USD-land, shocking I know | FluentCart |
| [fchub-portal-extender](plugins/fchub-portal-extender/) | Custom portal pages without writing PHP. For the "I'm not a developer" crowd, and fair enough | FluentCart |
| [fchub-stream](plugins/fchub-stream/) | **Discontinued.** Video streaming via Cloudflare Stream & Bunny.net. Retained as-is, without support or updates | FluentCommunity |
| [fchub-thank-you](plugins/fchub-thank-you/) | Per-product post-payment redirects. "Thank you for your order" is not a personality | FluentCart |
| [fchub-wishlist](plugins/fchub-wishlist/) | Wishlists with guest sessions, portal support, FluentCRM automations. The basics, done properly | FluentCart |
| [CartShift](plugins/cartshift/) | WooCommerce → FluentCart migrator. Products, orders, subscriptions, customers, coupons. Your escape hatch | Both |

WordPress 6.7+. PHP 8.3+. Patience optional.

### FCHub Stream status

FCHub Stream is discontinued, with maintenance suspended indefinitely. It receives no support, bug fixes, compatibility updates, security updates, or new releases. The source and existing releases remain available under GPLv2, so anyone may fork it and continue in their own direction. I may return to it someday. There is no roadmap or schedule, because apparently even abandoned video plugins deserve fewer fictional deadlines.

Docs, install guides, changelogs, screenshots — all at **[fchub.co](https://fchub.co)**. This README is not a documentation site and it's not going to pretend to be one.

---

## FluentCart MCP server

An [MCP server](fluentcart-mcp/) that lets AI agents talk to your FluentCart store. Orders, products, customers, subscriptions — the whole shop, remote-controlled by a robot. On npm as `fluentcart-mcp`. Docs at **[fchub.co/fluentcart-mcp](https://fchub.co/fluentcart-mcp)**.

If you're going to let AI run your business, at least give it proper tooling.

---

## Get the plugins

ZIPs on [GitHub Releases](https://github.com/vcode-sh/fchub-plugins/releases). Or go to **[fchub.co](https://fchub.co)** like a civilised person and use the install guides.

## Translations

Translated FluentCart into Polish (~96%) because nobody else was going to. PRs welcome if you fancy finishing the last 4%. Files in `translations/fluent-cart/`.

---

## Why does this exist

FluentCart is genuinely great software with the ecosystem of a brand new SaaS that launched yesterday. No payment gateways for half of Europe. No invoicing. No memberships. No video. No wishlists. No WooCommerce migration path for the millions of shops that might actually want to switch.

So I built all of it.

**[Read the manifesto →](https://fchub.co/blog/fchub-manifesto)** if you want to understand why someone would write nine plugins for a platform most WordPress developers haven't heard of yet.

---

## Dev stuff

Everything lives in `plugins/{slug}/`. That's the source of truth. Edit there or cry later.

**Build ZIPs:**
```bash
./build.sh                    # all of them
./build.sh fchub-p24          # just one
```

**Tag & release:** Tag per plugin, push, CI builds the ZIP and publishes the release. Get the version wrong and the build fails loudly. You deserve it.
```bash
git tag fchub-p24/v1.0.1
git push origin fchub-p24/v1.0.1
```

MCP server: `fluentcart-mcp/v1.1.0` → npm publish + GitHub Release + Docker to GHCR & Docker Hub.

**Tests:**
```bash
cd plugins/fchub-p24 && composer install && ./vendor/bin/phpunit
```

**Vue apps for maintained plugins:**
```bash
cd plugins/fchub-memberships && npm install && npm run dev
cd plugins/fchub-portal-extender && npm install && npm run dev
```

**Docker:** Volume-mount plugins from this repo into a WordPress container. Edit file, refresh browser, see change. Revolutionary.

**CI on PRs:** PHPUnit, Vite builds, LOC budget guard. Separate pipelines for plugins, MCP server, and docs. There's also an AI-powered issue triage because even GitHub Issues need a bouncer.

---

## Contributing

Plugin submissions, bug fixes, translations — all welcome. Built something for FluentCart or FluentCommunity? It probably belongs here.

- **[fchub.co/contribute](https://fchub.co/contribute)** — contributor guide and plugin submission process
- **[CONTRIBUTING.md](CONTRIBUTING.md)** — the code bits
- Submit plugins via [GitHub Issues](https://github.com/vcode-sh/fchub-plugins/issues/new?title=Plugin+submission:+&labels=plugin-submission)

---

GPLv2 or later. Built by [Vibe Code](https://x.com/vcode_sh). Documented at **[fchub.co](https://fchub.co)**.
