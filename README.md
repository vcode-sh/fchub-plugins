# fchub-plugins

Monorepo for FCHub WordPress plugins — the FluentCart ecosystem extensions that actually do things.

## Plugins

| Plugin | What it does | Version |
|--------|-------------|---------|
| [fchub-p24](plugins/fchub-p24/) | Przelewy24 payment gateway | 1.0.0 |
| [fchub-fakturownia](plugins/fchub-fakturownia/) | Fakturownia invoices + KSeF 2.0 | 1.0.0 |
| [fchub-memberships](plugins/fchub-memberships/) | Membership system, content gating, drip | 1.0.0 |
| [wc-fc](plugins/wc-fc/) | WooCommerce → FluentCart migrator | 1.0.0 |

All plugins require [FluentCart](https://fluentcart.com) and WordPress 6.0+ / PHP 7.4+.

## Build

```bash
# Build all plugin ZIPs
./build.sh

# Build a single plugin
./build.sh fchub-p24
```

ZIPs land in `dist/`. Each ZIP has the correct directory structure for WordPress plugin upload.

## Release

Push a tag, get a GitHub Release with a ZIP attached. That's it.

```bash
# Tag format: {plugin-slug}/v{version}
git tag fchub-p24/v1.0.0
git push origin fchub-p24/v1.0.0
```

The release workflow validates the version in the plugin header matches the tag, builds assets if needed (memberships), and creates the release. If the versions don't match, it yells at you.

## CI

Pull requests touching `plugins/` trigger:

- **PHPUnit** for fchub-p24 and fchub-memberships
- **Vite build check** for fchub-memberships (makes sure the Vue app still compiles)

## Development

Each plugin lives in `plugins/{slug}/`. Edit files there, they're the source of truth.

### Local dev with Docker

If you're using the companion dev environment repo, volume mounts point here:

```yaml
# docker-compose.yml in your dev repo
volumes:
  - ../fchub-plugins/plugins/fchub-p24:/var/www/html/wp-content/plugins/fchub-p24
  # ... etc
```

Edit a file in this repo → refresh browser → see changes. The dream.

### Plugin-specific dev

```bash
# fchub-p24 / fchub-memberships — PHP tests
cd plugins/fchub-p24
composer install
./vendor/bin/phpunit

# fchub-memberships — Vue admin app
cd plugins/fchub-memberships
npm install
npm run dev     # HMR dev server
npm run build   # production build → assets/dist/
```

## Repo structure

```
plugins/
  fchub-p24/              Payment gateway
  fchub-fakturownia/      Invoice integration
  fchub-memberships/      Membership system (Vue admin, 15k+ LOC)
  wc-fc/                  WooCommerce migrator
.github/workflows/
  release.yml             Tag → ZIP → GitHub Release
  ci.yml                  PR checks (PHPUnit + Vite)
build.sh                  Local ZIP builder
```

## License

All plugins are GPLv2 or later. Built by [Vibe Code](https://vcode.sh).
