# FCHub Plugins

The WordPress plugins that FluentCart and FluentCommunity should've shipped but didn't. One monorepo, nine plugins, an MCP server, and a mass exodus tool from WooCommerce. All open source because vendor lock-in is a personality disorder. Read [the manifesto](https://fchub.co/blog/fchub-manifesto) if you want the full rant.

<a href="https://fchub.co">
  <img src="https://img.shields.io/badge/docs-fchub.co-blue?style=flat-square" alt="Docs">
</a>

<iframe src="https://github.com/sponsors/vcode-sh/button" title="Sponsor vcode-sh" height="32" width="114" style="border: 0; border-radius: 6px;"></iframe>

Running this operation on caffeine and stubbornness. If any of these plugins saved you time, money, or a conversation with your accountant — consider sponsoring. Or don't. I'll keep building anyway, but slower and angrier.

## Plugins

| Plugin | What it does | Requires |
|--------|-------------|----------|
| [fchub-p24](plugins/fchub-p24/) | Przelewy24 gateway — because Stripe doesn't speak Polish | FluentCart |
| [fchub-fakturownia](plugins/fchub-fakturownia/) | Fakturownia invoices + KSeF 2.0 — automate paperwork before the tax office automates you | FluentCart |
| [fchub-memberships](plugins/fchub-memberships/) | Memberships, content gating, drip scheduling — 15k lines of PHP and Vue so people can pay to read your blog | FluentCart |
| [fchub-multi-currency](plugins/fchub-multi-currency/) | Display-layer multi-currency with exchange rates — because not everyone lives in USD-land | FluentCart |
| [fchub-portal-extender](plugins/fchub-portal-extender/) | Custom portal pages without writing PHP — for the "I'm not a developer" crowd | FluentCart |
| [fchub-stream](plugins/fchub-stream/) | Video streaming via Cloudflare Stream & Bunny.net — because the WP media library and video is a war crime | FluentCommunity |
| [fchub-thank-you](plugins/fchub-thank-you/) | Per-product post-payment redirects — because "Thank you for your order" is not a personality | FluentCart |
| [fchub-wishlist](plugins/fchub-wishlist/) | Wishlists with guest sessions, portal support, and FluentCRM automations | FluentCart |
| [wc-fc](plugins/wc-fc/) | WooCommerce → FluentCart migrator — products, orders, subscriptions, customers, coupons. Your escape hatch | FluentCart + WooCommerce |

WordPress 6.7+. PHP 8.3+. Patience optional. Full docs, install guides, and changelogs at **[fchub.co](https://fchub.co)**.

## FluentCart MCP Server

An [MCP server](fluentcart-mcp/) that lets AI agents talk to FluentCart's REST API. Orders, products, customers, subscriptions — the whole shop, controlled by a robot. Published on npm as `fluentcart-mcp`. Docs and setup at **[fchub.co/fluentcart-mcp](https://fchub.co/fluentcart-mcp)**.

Because if you're going to let AI run your business, at least give it proper tooling.

## Downloads

Grab ZIPs from [GitHub Releases](https://github.com/vcode-sh/fchub-plugins/releases) or visit **[fchub.co](https://fchub.co)** for docs, install guides, and per-plugin pages with changelogs and screenshots.

## Translations

| Language | Coverage | Path |
|----------|----------|------|
| [Polish (pl_PL)](translations/fluent-cart/) | ~96% | `translations/fluent-cart/` |

Translated FluentCart into Polish because nobody else was going to. PRs welcome if you fancy finishing the last 4%.

## Build

```bash
./build.sh                    # all plugins
./build.sh fchub-p24          # just one
```

ZIPs land in `dist/`. Correct directory structure, ready for WordPress upload.

## Release

Tag per plugin. Push. CI builds the ZIP and publishes the release. Disagree with the version in the plugin header and the build fails loudly. You deserve it.

```bash
git tag fchub-p24/v1.0.1
git push origin fchub-p24/v1.0.1
```

MCP server has its own tag pattern: `fluentcart-mcp/v1.1.0` → npm publish + GitHub Release + Docker image to GHCR and Docker Hub.

## CI

PRs touching `plugins/` get:

- **PHPUnit** — fchub-p24, fchub-memberships, fchub-stream, fchub-wishlist, fchub-multi-currency, fchub-thank-you
- **LOC budget** — fchub-wishlist architecture guard (`scripts/check-loc.sh`)
- **Vite build** — fchub-memberships, fchub-portal-extender, fchub-stream (making sure the Vue apps didn't spontaneously combust)

PRs touching `fluentcart-mcp/` get their own CI pipeline. PRs touching `web-docs/` trigger docs-ci. There's also an AI-powered issue triage workflow because even GitHub Issues need a bouncer.

## Development

Everything lives in `plugins/{slug}/`. Edit there. That's the source of truth.

### Docker

Use a companion dev repo with volume mounts:

```yaml
volumes:
  - ../fchub-plugins/plugins/fchub-p24:/var/www/html/wp-content/plugins/fchub-p24
  # ... you get the idea
```

Edit file. Refresh browser. See change. Revolutionary.

### Tests & builds

```bash
# PHP tests
cd plugins/fchub-p24 && composer install && ./vendor/bin/phpunit

# Vue admin app (memberships)
cd plugins/fchub-memberships && npm install && npm run dev

# Vue admin app (portal extender)
cd plugins/fchub-portal-extender && npm install && npm run dev

# Vue apps (stream)
cd plugins/fchub-stream/admin-app && npm install && npm run dev
cd plugins/fchub-stream/portal-app && npm install && npm run dev
```

## Repo structure

```
plugins/
  fchub-p24/              Przelewy24 gateway
  fchub-fakturownia/      Fakturownia invoices
  fchub-memberships/      Membership system
  fchub-multi-currency/   Multi-currency display layer
  fchub-portal-extender/  Custom portal endpoints
  fchub-stream/           Video streaming for FluentCommunity
  fchub-thank-you/        Custom thank you pages
  fchub-wishlist/         Wishlist system
  wc-fc/                  WooCommerce migrator
fluentcart-mcp/           MCP server for FluentCart API
translations/
  fluent-cart/            Polish translation
web-docs/                 fchub.co documentation site
.github/workflows/
  release.yml             Tag → ZIP → GitHub Release
  ci.yml                  PR checks (plugins)
  mcp-release.yml         FluentCart MCP npm + GitHub Release
  mcp-docker.yml          FluentCart MCP Docker image
  mcp-ci.yml              FluentCart MCP PR checks
  docs-ci.yml             Docs site checks
```

## Why this exists

Short version: FluentCart is great, its ecosystem is empty.

Long version: **[The FCHub Manifesto](https://fchub.co/blog/fchub-manifesto)**. Read it if you want to understand why someone would build nine plugins for a platform most WordPress developers haven't heard of yet.

## Contributing

Plugin submissions, bug fixes, translations — all welcome. If you've built something for FluentCart or FluentCommunity, it probably belongs here.

- **[fchub.co/contribute](https://fchub.co/contribute)** — full contributor guide and plugin submission process
- **[CONTRIBUTING.md](CONTRIBUTING.md)** — code contribution guidelines
- Plugin submissions accepted via [GitHub Issues](https://github.com/vcode-sh/fchub-plugins/issues/new?title=Plugin+submission:+&labels=plugin-submission)

## License

GPLv2 or later. Built by [Vibe Code](https://x.com/vcode_sh). Documented at [fchub.co](https://fchub.co).
