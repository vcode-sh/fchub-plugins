# fchub-plugins

I got tired of having my FluentCart plugins scattered across a dev repo like socks after laundry day. So here they are. One monorepo. Four plugins. Zero excuses.

## Plugins

| Plugin | The pitch | Version |
|--------|-----------|---------|
| [fchub-p24](plugins/fchub-p24/) | Przelewy24 gateway — take money from Polish people, professionally | 1.0.0 |
| [fchub-fakturownia](plugins/fchub-fakturownia/) | Fakturownia invoices + KSeF 2.0 — keep the tax office off your back | 1.0.0 |
| [fchub-memberships](plugins/fchub-memberships/) | Memberships, content gating, drip — the full "pay me to read" stack | 1.0.0 |
| [wc-fc](plugins/wc-fc/) | WooCommerce → FluentCart migrator — your escape plan | 1.0.0 |

All require [FluentCart](https://fluentcart.com). WordPress 6.8+. PHP 8.3+. A functioning will to live (optional).

## Translations

| Language | Status | Path |
|----------|--------|------|
| [Polish (pl_PL)](translations/fluent-cart/) | ~96% | `translations/fluent-cart/` |

I translated FluentCart into Polish because nobody else was going to. PRs welcome if you fancy finishing the last 4%.

## Build

```bash
./build.sh                    # all plugins
./build.sh fchub-p24          # just one
```

ZIPs land in `dist/`. Correct directory structure, ready for WordPress upload. No magic required.

## Release

Tag it. Push it. GitHub Actions does the rest.

```bash
git tag fchub-p24/v1.0.0
git push origin fchub-p24/v1.0.0
```

The workflow checks that the version in your plugin header matches the tag. If they don't match, it fails loudly. You deserve it.

## CI

PRs touching `plugins/` get:

- **PHPUnit** — fchub-p24 and fchub-memberships
- **Vite build** — fchub-memberships (making sure the Vue app didn't spontaneously combust)

## Development

Everything lives in `plugins/{slug}/`. That's the source of truth. Edit there.

### Docker

I use a companion dev repo with volume mounts pointing here:

```yaml
volumes:
  - ../fchub-plugins/plugins/fchub-p24:/var/www/html/wp-content/plugins/fchub-p24
  # ... you get the idea
```

Edit file. Refresh browser. See change. Revolutionary, I know.

### Tests & builds

```bash
# PHP tests
cd plugins/fchub-p24 && composer install && ./vendor/bin/phpunit

# Vue admin app (memberships)
cd plugins/fchub-memberships && npm install && npm run dev
```

## Repo structure

```
plugins/
  fchub-p24/              Przelewy24 gateway
  fchub-fakturownia/      Fakturownia invoices
  fchub-memberships/      Membership system (15k+ LOC of questionable decisions)
  wc-fc/                  WooCommerce migrator
translations/
  fluent-cart/            Polish translation
.github/workflows/
  release.yml             Tag → ZIP → GitHub Release
  ci.yml                  PR checks
build.sh                  Local ZIP builder
```

## License

GPLv2 or later. Built by me — [Vibe Code](https://x.com/vcode_sh)
