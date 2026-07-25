# FCHub Product Centre Design

**Status:** Approved on 24 July 2026

## Summary

FCHub will be an optional first-party WordPress plugin that gives site administrators one calm place to discover, install, activate, update, open, and understand the supported FCHub product suite.

The hub is a control plane, not a shared runtime. Every product keeps its own business logic, settings, data, migrations, release cycle, update support, and uninstall behaviour. Removing FCHub must not disable or damage another product. One plugin taking the rest hostage would be impressively modular in entirely the wrong direction.

## Product Goal

The first release must answer three questions without making the administrator excavate WordPress:

1. Which FCHub products are installed and active?
2. Does anything useful need attention?
3. Where can the administrator install, update, configure, or learn about a product?

The customer-facing promise is:

> One calm place to discover, manage, and understand every FCHub product on this site.

## Research Direction

The design follows the optional suite-manager pattern used by products such as the [WPMU DEV Dashboard](https://wpmudev.com/docs/wpmu-dev-plugins/wpmu-dev-dashboard-plugin-instructions/) and [WooCommerce Update Manager](https://woocommerce.com/document/managing-woocommerce-com-subscriptions/installing-extensions/). It deliberately avoids turning the existing plugins into modules inside one monolith.

WordPress plugin dependencies do not support dependency version constraints or guaranteed load order. The hub must therefore not become a required dependency for existing FCHub products. See [Introducing Plugin Dependencies in WordPress 6.5](https://make.wordpress.org/core/2024/03/05/introducing-plugin-dependencies-in-wordpress-6-5/).

## Scope

### Included stable products

The version-one catalogue contains:

- FCHub - Przelewy24 (`fchub-p24`)
- FCHub - Fakturownia (`fchub-fakturownia`)
- FCHub - Memberships (`fchub-memberships`)
- FCHub - Portal Extender (`fchub-portal-extender`)
- FCHub - Wishlist (`fchub-wishlist`)
- FCHub - Multi-Currency (`fchub-multi-currency`)

### Explicit exclusions

- FCHub Stream is discontinued and must not appear in the catalogue, health calculations, tests, or release work.
- FCHub Thank You and FCHub Redsys are not stable catalogue products.
- CartShift remains a separate product identity and is not shown as part of the FCHub suite.
- WPLove is postponed and is not an architectural dependency, distribution route, or future assumption.

### Version-one non-goals

- No FCHub account, licence service, billing, or telemetry.
- No remote management of other WordPress sites.
- No general plugin marketplace or arbitrary ZIP installer.
- No product data inspection or product-specific database queries.
- No custom background auto-update scheduler.
- No deletion of product plugins from the FCHub interface.
- No reparenting or removal of the existing product admin menus.
- No mandatory migration of the existing per-product updater in this release.

## Compatibility Contract

FCHub itself supports:

- WordPress 6.4 or newer.
- PHP 8.1 or newer.
- A single-site or multisite WordPress installation, with operations applied only to the current site in version one.

The hub can run on a site that does not meet a product's requirements. It shows the product as incompatible and withholds unsafe actions. This is essential because Memberships, Wishlist, and Multi-Currency currently require newer WordPress and PHP versions than Przelewy24, Fakturownia, and Portal Extender.

FCHub has no runtime dependency on FluentCart. Products that require FluentCart remain visible, but installation and activation actions explain the requirement instead of quietly installing or activating another platform.

## Architecture

The plugin slug is `fchub`, the main file is `plugins/fchub/fchub.php`, and the initial public version is `1.0.0`.

The production namespace is `FChubHub\`. Composer is development tooling only; the distributed plugin uses a small internal PSR-4-style autoloader and ships no Composer vendor directory.

### 1. Product catalogue

The catalogue is first-party, allow-listed, and schema-versioned.

The source metadata lives in `web-docs/lib/fchub-products.json`. Released versions continue to live in `web-docs/lib/versions.json`. `scripts/sync-fchub-catalog.mjs` combines the two sources into `plugins/fchub/resources/catalog.json`, which is committed and bundled as the offline fallback, and `web-docs/lib/fchub-catalog.json`, which is committed for the public endpoint.

The website exposes the same merged representation at:

`https://fchub.co/api/v1/products`

The endpoint and bundled file return:

```json
{
  "schema_version": 1,
  "hub": {
    "version": "1.0.0",
    "plugin_file": "fchub/fchub.php",
    "release_url": "https://github.com/vcode-sh/fchub-plugins/releases/tag/fchub/v1.0.0",
    "package_url": "https://github.com/vcode-sh/fchub-plugins/releases/download/fchub/v1.0.0/fchub-1.0.0.zip",
    "checksum_url": "https://github.com/vcode-sh/fchub-plugins/releases/download/fchub/v1.0.0/fchub-1.0.0.zip.sha256"
  },
  "products": {
    "fchub-memberships": {
      "name": "Memberships",
      "description": "A calmer way to run memberships.",
      "version": "1.4.0",
      "plugin_file": "fchub-memberships/fchub-memberships.php",
      "requires_wp": "6.7",
      "requires_php": "8.3",
      "dependencies": ["fluentcart"],
      "docs_url": "https://fchub.co/docs/fchub-memberships",
      "release_url": "https://github.com/vcode-sh/fchub-plugins/releases/tag/fchub-memberships/v1.4.0",
      "package_url": "https://github.com/vcode-sh/fchub-plugins/releases/download/fchub-memberships/v1.4.0/fchub-memberships-1.4.0.zip",
      "checksum_url": "https://github.com/vcode-sh/fchub-plugins/releases/download/fchub-memberships/v1.4.0/fchub-memberships-1.4.0.zip.sha256",
      "admin_path": "admin.php?page=fchub-memberships"
    }
  }
}
```

The production endpoint sets a public cache header and an ETag. The plugin keeps:

- A six-hour freshness transient.
- A persistent last-known-good catalogue option.
- The bundled catalogue as the final offline fallback.

Invalid remote data never replaces the last-known-good copy.

### 2. Site discovery and state resolution

The discovery service reads WordPress's installed plugin inventory and active plugin state, then merges them with the trusted catalogue.

Each product has four independent state dimensions:

- Lifecycle: `not_installed`, `inactive`, or `active`.
- Update: `current`, `available`, or `unknown`.
- Compatibility: `compatible`, `blocked`, or `unknown`.
- Health: `healthy`, `attention`, or `unknown`.

Keeping these dimensions separate avoids absurd states such as pretending an active but outdated plugin is either only "active" or only "outdated".

The state resolver checks:

- Installed plugin header version.
- Current WordPress and PHP versions.
- Declared platform dependencies such as FluentCart.
- Remote or fallback catalogue version.
- Optional active-product descriptors.

### 3. Optional product descriptor contract

Active plugins may enrich their known catalogue entry without depending on a shared PHP class:

```php
add_filter('fchub/products', static function (array $products): array {
    $products['fchub-memberships'] = [
        'schema_version' => 1,
        'plugin_file'    => plugin_basename(__FILE__),
        'admin_path'     => 'admin.php?page=fchub-memberships',
        'health'         => [
            'status'  => 'healthy',
            'message' => __('Memberships is ready.', 'fchub-memberships'),
        ],
    ];

    return $products;
});
```

FCHub applies this filter after active plugins have loaded. It accepts descriptors only for catalogue slugs whose `plugin_file` exactly matches the trusted catalogue. Descriptors may enrich local administration and health information; they may not replace the trusted version, download, checksum, compatibility, or documentation fields.

No existing product must implement this filter for the first release. Catalogue fallbacks provide useful settings and documentation links for older versions.

### 4. Safe product operations

The operation service uses WordPress core APIs:

- `Plugin_Upgrader` for installation and replacement.
- `activate_plugin()` for activation.
- `deactivate_plugins()` for deactivation.
- Core plugin inventory and update-cache APIs for discovery.

Before an installation or update, FCHub:

1. Resolves the product from the trusted catalogue.
2. Checks the operation-specific WordPress capability.
3. Checks WordPress, PHP, and platform compatibility.
4. Downloads only from the approved GitHub release hosts.
5. Fetches and verifies the SHA-256 asset when it exists.
6. Hands the verified local ZIP to WordPress's upgrader.
7. Confirms the resulting plugin file and installed version.
8. Deletes the temporary package.
9. Refreshes the product state returned to the interface.

Actions remain explicit: `Install`, `Install and activate`, `Activate`, `Update`, and `Deactivate`. An installation never activates a product unless the selected action says so.

The hub does not remove product files and does not modify product-owned options or tables.

### 5. Independent updating

Existing products retain their standalone update behaviour. The FCHub interface can orchestrate a product update from the trusted catalogue, but the product does not require FCHub to receive its normal WordPress update notification.

FCHub uses the catalogue's top-level `hub` release record through its own namespaced updater for `fchub/fchub.php`. The hub record is update metadata and is not rendered as a product card. FCHub does not rely on the existing global `FCHub_GitHub_Updater` class because the oldest loaded copy currently wins, which is a charming way to make update behaviour depend on alphabetical chance.

A later, separate project may migrate all product updaters to the first-party catalogue endpoint.

## WordPress Interface

FCHub registers one top-level `FCHub` admin menu. Existing product menus remain unchanged so products stay fully usable when the hub is absent.

The interface is a Vue 3 and Vite application with three routes:

### Overview

- A calm summary that leads with overall health.
- Counts for active products, useful updates, and compatibility issues.
- Cards for installed products.
- At most one concise attention section when action is genuinely needed.
- A secondary discovery row for one or more available products.

### Products

- All stable catalogue products.
- Filters for all, installed, and updates.
- Product cards with friendly descriptions and exact lifecycle badges.
- One primary action per state.
- Secondary links for documentation, release notes, and settings where available.

### System

- WordPress, PHP, and FluentCart compatibility summary.
- Catalogue source, last successful refresh, and a manual refresh action.
- FCHub version and update status.
- Concise actionable errors, not raw transport payloads or stack traces.

## Visual Language

The approved direction is the calm overview shown during design review. It uses the existing FCHub visual system rather than inventing another brand.

Required light tokens:

```css
--fchub-page-bg: #F3F5FA;
--fchub-card-bg: #FFFFFF;
--fchub-primary: #4D6EF5;
--fchub-text-primary: #151D26;
--fchub-text-secondary: #565865;
--fchub-border-color: #EAECF0;
--fchub-stat-blue: #122368;
--fchub-stat-blue-bg: #EBF1FF;
--fchub-stat-orange: #71330A;
--fchub-stat-orange-bg: #FFF3EB;
--fchub-stat-pink: #68123D;
--fchub-stat-pink-bg: #FFEBF4;
--fchub-stat-purple: #351A75;
--fchub-stat-purple-bg: #EFEBFF;
```

The application uses the existing FCHub dark tokens, eight-pixel cards, twelve-pixel summary surfaces, restrained shadows, and Inter-compatible typography. Inter is bundled locally; opening FCHub must not contact a font CDN.

The copy is friendly, brief, and product-led. It explains what the user can do, not which database table had an emotional episode. Technical details remain available only where they help resolve a problem.

## REST API

The namespace is `fchub/v1`.

Read route:

- `GET /products` — returns catalogue metadata, resolved site states, summary counts, and current capabilities.

Mutation routes:

- `POST /products/{slug}/install`
- `POST /products/{slug}/install-and-activate`
- `POST /products/{slug}/activate`
- `POST /products/{slug}/update`
- `POST /products/{slug}/deactivate`
- `POST /catalogue/refresh`

All routes require an authenticated WordPress REST nonce.

Capabilities are operation-specific:

- Viewing and refreshing: `manage_options`.
- Installing: `install_plugins`.
- Activating and deactivating: `activate_plugins`.
- Updating: `update_plugins`.

Successful responses return the refreshed product and summary. Failures use stable public error codes and friendly messages:

```json
{
  "success": false,
  "code": "product_incompatible",
  "message": "Memberships needs PHP 8.3 before it can be activated.",
  "product": "fchub-memberships"
}
```

Internal paths, stack traces, remote response bodies, and credentials are never returned.

## Failure Behaviour

- Remote catalogue unavailable: use the unexpired cache, then last-known-good, then bundled catalogue.
- Remote catalogue invalid: reject it and preserve the previous valid copy.
- Checksum unavailable on an older release: allow the trusted HTTPS package with an internal `checksum_unavailable` note; do not present it as an error to the customer.
- Checksum mismatch: delete the package, stop the operation, and return `package_verification_failed`.
- Download or upgrader failure: leave other products untouched and return the sanitised WordPress error.
- Compatibility failure: make the action unavailable and explain the exact missing requirement.
- Product descriptor invalid: ignore the descriptor and continue with trusted catalogue data.
- One product operation failing never changes another product's state.

## Data Ownership and Uninstallation

FCHub owns only:

- `fchub_catalogue_last_good`
- `fchub_catalogue_etag`
- `fchub_catalogue_last_refresh`
- `fchub_catalogue_fresh` transient
- Its own WordPress update cache entries

`uninstall.php` deletes only these values, including their multisite variants where applicable. It does not deactivate, delete, or modify another plugin.

## Testing Strategy

### PHP unit tests

Cover:

- Catalogue schema and host validation.
- Cache and fallback precedence.
- Product lifecycle, update, compatibility, and health resolution.
- Descriptor allow-listing.
- Capability checks.
- Package checksum verification.
- Sanitised public errors.
- FCHub-only uninstall cleanup.

### Vue tests

Cover:

- Summary counts and calm healthy state.
- Update and incompatibility attention states.
- Product filters and action labels.
- Disabled actions with useful explanations.
- REST success and failure state refresh.
- Keyboard navigation and focus return after actions.

### Browser smoke tests

Cover Overview, Products, and System with healthy, offline, incompatible, update, and failed-operation fixtures. Test the production Vite build rather than a development-only component harness.

### Disposable WordPress lifecycle test

A dedicated Docker Compose harness creates a fresh WordPress and MariaDB installation plus a local fixture server. It uses an allow-listed `fchub-p24` fixture package and a minimal FluentCart fixture, so the production catalogue validator is exercised rather than quietly replaced with a test-shaped hole. It:

1. Builds the FCHub ZIP.
2. Installs and activates FCHub through WP-CLI.
3. Serves a deterministic local catalogue, package, and checksum.
4. Installs and activates a stable fixture product through the real FCHub interface.
5. Updates the fixture product.
6. Deactivates and uninstalls FCHub.
7. Confirms the product remains installed, active, and functional.
8. Confirms FCHub-owned options and transients are removed.
9. Reinstalls FCHub and confirms product discovery still works.
10. Destroys the disposable containers and volumes.

The harness never uses the long-lived playground database.

## Build and Release

The repository build script adds `fchub|fchub.php`, runs the FCHub Vite build, validates `assets/dist`, and creates `dist/fchub-1.0.0.zip`.

The central version registry adds:

```json
"fchub": {
  "version": "1.0.0",
  "tagName": "fchub/v1.0.0",
  "zipFilename": "fchub-1.0.0.zip",
  "mcpbFilename": null
}
```

The release workflow accepts `fchub/v*`, runs PHP, JavaScript, browser, catalogue-contract, and disposable lifecycle gates, builds the release ZIP, creates a SHA-256 sidecar, and uploads both assets to the GitHub release.

The tag format is:

`fchub/v1.0.0`

No release is created until the plugin header, package version, and `web-docs/lib/versions.json` agree.

## Documentation

The existing `/docs/fchub` section becomes the product-centre documentation root. It contains:

- Overview
- Installation
- Managing products
- Understanding compatibility and system status
- Troubleshooting
- Changelog

The existing general explanation of the FCHub suite remains on the overview page but is corrected to reflect actual plugin compatibility floors and the discontinued status of Stream.

## Acceptance Criteria

The design is complete when:

- FCHub can be installed on WordPress 6.4 and PHP 8.1 without FluentCart.
- The six stable products appear with correct installed, active, update, and compatibility states.
- Product operations use trusted catalogue entries and WordPress core APIs.
- A catalogue outage does not make the interface useless.
- Removing FCHub leaves all product plugins and their data untouched.
- The interface uses the established FCHub colour system and the approved calm overview hierarchy.
- Automated PHP, Vue, browser, disposable lifecycle, build, catalogue, and release gates pass.
- The generated ZIP installs through WordPress and contains no development dependencies or source-only assets.
