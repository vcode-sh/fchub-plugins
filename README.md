# FCHub Plugins

FluentCart and FluentCommunity plugins that actually do things WordPress forgot to ship. One monorepo, five plugins, zero vendor lock-in.

**[fchub.co](https://fchub.co)** — docs, downloads, and everything else.

## Plugins

| Plugin | What it does | Version |
|--------|-------------|---------|
| [fchub-p24](plugins/fchub-p24/) | Przelewy24 gateway — because Stripe doesn't speak Polish | 1.0.0 |
| [fchub-fakturownia](plugins/fchub-fakturownia/) | Fakturownia invoices + KSeF 2.0 — automate paperwork before the tax office automates you | 1.0.0 |
| [fchub-memberships](plugins/fchub-memberships/) | Memberships, content gating, drip scheduling — 15k lines of PHP and Vue so people can pay to read your blog | 1.0.0 |
| [fchub-stream](plugins/fchub-stream/) | Video streaming via Cloudflare Stream & Bunny.net — because the WP media library and video is a war crime | 1.0.0 |
| [wc-fc](plugins/wc-fc/) | WooCommerce → FluentCart migrator — products, orders, subscriptions, customers, coupons. Your escape hatch | 1.0.0 |

All require [FluentCart](https://fluentcart.com) except fchub-stream which needs [FluentCommunity](https://fluentcommunity.com). WordPress 6.5+. PHP 8.3+.

## Downloads

Grab the latest ZIPs from [GitHub Releases](https://github.com/vcode-sh/fchub-plugins/releases) or visit **[fchub.co](https://fchub.co)** for docs and install guides.

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

Tag per plugin. Push. GitHub Actions builds the ZIP and publishes the release.

```bash
git tag fchub-p24/v1.0.1
git push origin fchub-p24/v1.0.1
```

Version in the plugin header must match the tag. If they disagree, the build fails loudly. You deserve it.

## CI

PRs touching `plugins/` get:

- **PHPUnit** — fchub-p24, fchub-memberships, fchub-stream
- **Vite build** — fchub-memberships and fchub-stream (making sure the Vue apps didn't spontaneously combust)

## Development

Everything lives in `plugins/{slug}/`. Edit there. That's the source of truth.

### Docker

I use a companion dev repo with volume mounts:

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
  fchub-stream/           Video streaming for FluentCommunity
  wc-fc/                  WooCommerce migrator
translations/
  fluent-cart/            Polish translation
web-docs/                 fchub.co documentation site
.github/workflows/
  release.yml             Tag → ZIP → GitHub Release
  ci.yml                  PR checks
build.sh                  Local ZIP builder
```

## License

GPLv2 or later. Built by [Vibe Code](https://x.com/vcode_sh).
