# FCHub Product Centre Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build and release FCHub 1.0.0 as an optional, polished WordPress product centre for discovering and safely managing the six stable FCHub plugins on one site.

**Architecture:** A standalone `fchub` plugin owns a trusted first-party product catalogue, site-state resolver, safe WordPress plugin operations, and a Vue 3 admin application. Products remain autonomous and require no shared runtime; the website supplies a cacheable catalogue while the plugin retains a generated offline copy and a persistent last-known-good response.

**Tech Stack:** PHP 8.1, WordPress 6.4 APIs, Vue 3, Vue Router, Element Plus, Vite, PHPUnit 10, Vitest, Playwright, Next.js 16/Fumadocs, Docker Compose, GitHub Actions.

## Global Constraints

- All code, documentation, tests, and proposed commit messages are in English.
- Follow `voice-tone.md`: warm, concise, slightly cheeky, and never corporate.
- Do not commit or push. The repository owner handles Git publication.
- Do not modify, test, catalogue, or release `plugins/fchub-stream/`.
- Do not include FCHub Thank You, FCHub Redsys, CartShift, or WPLove in the catalogue.
- FCHub version 1.0.0 supports WordPress 6.4+ and PHP 8.1+.
- FCHub must run without FluentCart and must never become a product runtime dependency.
- Deactivating or uninstalling FCHub must leave every product plugin and its data untouched.
- Use the approved FCHub colours: `#F3F5FA`, `#FFFFFF`, `#4D6EF5`, `#151D26`, `#565865`, and `#EAECF0`, plus the existing FCHub status tokens from the design specification.
- The UI has exactly three primary routes: Overview, Products, and System.
- Product installation and mutation operate only on allow-listed catalogue entries through WordPress core APIs.
- No telemetry, licences, remote site management, arbitrary ZIP installation, product deletion, or custom auto-update scheduler.
- Existing product menus and standalone update behaviour remain unchanged.
- The approved design is `docs/superpowers/specs/2026-07-24-fchub-product-centre-design.md`.

---

## File Structure

### New WordPress plugin

```text
plugins/fchub/
├── .distignore
├── composer.json
├── composer.lock
├── fchub.php
├── index.php
├── package.json
├── package-lock.json
├── phpunit.xml.dist
├── playwright.config.js
├── uninstall.php
├── vite.config.js
├── app/
│   ├── Core/
│   │   └── Plugin.php
│   ├── Catalogue/
│   │   ├── CatalogueRepository.php
│   │   ├── CatalogueValidator.php
│   │   ├── DescriptorRegistry.php
│   │   └── ProductStateResolver.php
│   ├── Http/
│   │   ├── ProductController.php
│   │   └── Routes.php
│   ├── Operations/
│   │   ├── OperationError.php
│   │   ├── ProductOperationService.php
│   │   └── VerifiedPackageDownloader.php
│   └── Support/
│       ├── AdminMenu.php
│       ├── AssetManifest.php
│       └── HubUpdater.php
├── assets/
│   ├── dist/
│   ├── fonts/inter-latin.woff2
│   └── icons/fchub.svg
├── resources/
│   ├── admin/
│   │   ├── api/client.js
│   │   ├── components/
│   │   │   ├── AttentionPanel.vue
│   │   │   ├── ProductCard.vue
│   │   │   ├── StatusBadge.vue
│   │   │   └── SummaryCard.vue
│   │   ├── pages/
│   │   │   ├── OverviewPage.vue
│   │   │   ├── ProductsPage.vue
│   │   │   └── SystemPage.vue
│   │   ├── router/index.js
│   │   ├── stores/products.js
│   │   ├── styles/global.css
│   │   ├── styles/variables.css
│   │   ├── App.vue
│   │   └── main.js
│   └── catalog.json
└── tests/
    ├── Unit/
    │   ├── CatalogueRepositoryTest.php
    │   ├── CatalogueValidatorTest.php
    │   ├── DescriptorRegistryTest.php
    │   ├── ProductOperationServiceTest.php
    │   ├── ProductStateResolverTest.php
    │   ├── RoutesTest.php
    │   ├── UninstallTest.php
    │   └── VerifiedPackageDownloaderTest.php
    ├── admin/
    │   ├── ProductCard.test.js
    │   ├── ProductsStore.test.js
    │   └── setup.js
    ├── e2e/
    │   ├── fixtures/
    │   │   └── nginx.conf
    │   ├── docker-compose.yml
    │   ├── fchub-lifecycle.spec.js
    │   └── run-lifecycle.sh
    └── bootstrap.php
```

### Catalogue, website, CI, and release integration

```text
scripts/sync-fchub-catalog.mjs
tests/repository/fchub-catalog.test.mjs
web-docs/lib/fchub-products.json
web-docs/lib/fchub-catalog.json
web-docs/app/api/v1/products/route.ts
web-docs/content/docs/fchub/
├── changelog.mdx
├── index.mdx
├── installation.mdx
├── managing-products.mdx
├── meta.json
├── system-status.mdx
└── troubleshooting.mdx
```

---

### Task 1: Establish the catalogue source of truth and public endpoint

**Files:**

- Create: `web-docs/lib/fchub-products.json`
- Modify: `web-docs/lib/versions.json`
- Create: `scripts/sync-fchub-catalog.mjs`
- Create: `plugins/fchub/resources/catalog.json`
- Create: `web-docs/lib/fchub-catalog.json`
- Create: `web-docs/app/api/v1/products/route.ts`
- Create: `tests/repository/fchub-catalog.test.mjs`
- Modify: `web-docs/package.json`

**Interfaces:**

- Produces: catalogue schema version `1` with a `products` object keyed by the six allow-listed slugs.
- Produces: a top-level `hub` release record used only to update FCHub itself.
- Produces: `buildCatalogue(metadata, versions)` and `validateCatalogue(catalogue)` exports from `scripts/sync-fchub-catalog.mjs`.
- Produces: `GET https://fchub.co/api/v1/products`.
- Consumed later by: `CatalogueRepository`, the Vue product store, release validation, and the disposable lifecycle fixture.

- [ ] **Step 1: Write the failing repository contract**

Create `tests/repository/fchub-catalog.test.mjs`:

```js
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import {
  buildCatalogue,
  validateCatalogue,
} from '../../scripts/sync-fchub-catalog.mjs'

const root = new URL('../../', import.meta.url)
const readJson = async (path) =>
  JSON.parse(await readFile(new URL(path, root), 'utf8'))

test('catalogue contains exactly the six stable FCHub products', async () => {
  const metadata = await readJson('web-docs/lib/fchub-products.json')
  const versions = await readJson('web-docs/lib/versions.json')
  const catalogue = buildCatalogue(metadata, versions)

  assert.deepEqual(Object.keys(catalogue.products).sort(), [
    'fchub-fakturownia',
    'fchub-memberships',
    'fchub-multi-currency',
    'fchub-p24',
    'fchub-portal-extender',
    'fchub-wishlist',
  ])
  assert.equal(validateCatalogue(catalogue), true)
})

test('catalogue excludes discontinued, experimental, and separate products', async () => {
  const bundled = await readJson('plugins/fchub/resources/catalog.json')

  for (const slug of [
    'fchub-stream',
    'fchub-thank-you',
    'fchub-redsys',
    'cartshift',
  ]) {
    assert.equal(bundled.products[slug], undefined)
  }
})

test('bundled catalogue matches generated source exactly', async () => {
  const metadata = await readJson('web-docs/lib/fchub-products.json')
  const versions = await readJson('web-docs/lib/versions.json')
  const bundled = await readJson('plugins/fchub/resources/catalog.json')

  assert.deepEqual(bundled, buildCatalogue(metadata, versions))
})

test('website and plugin catalogue copies are identical', async () => {
  const bundled = await readJson('plugins/fchub/resources/catalog.json')
  const website = await readJson('web-docs/lib/fchub-catalog.json')
  assert.deepEqual(website, bundled)
})
```

- [ ] **Step 2: Run the contract and confirm the missing-source failure**

Run:

```bash
node --test tests/repository/fchub-catalog.test.mjs
```

Expected: FAIL because `scripts/sync-fchub-catalog.mjs` and catalogue files do not exist.

- [ ] **Step 3: Add the exact product metadata**

Create `web-docs/lib/fchub-products.json` with `schema_version: 1`. Use these exact product contracts:

| Slug | Name | Plugin file | WP | PHP | Admin path | Docs |
|---|---|---|---:|---:|---|---|
| `fchub-p24` | Przelewy24 | `fchub-p24/fchub-p24.php` | 6.4 | 8.1 | `admin.php?page=fluent-cart#/settings/payment-methods` | `/docs/fchub-p24` |
| `fchub-fakturownia` | Fakturownia | `fchub-fakturownia/fchub-fakturownia.php` | 6.4 | 8.1 | `admin.php?page=fluent-cart#/integrations/fakturownia` | `/docs/fchub-fakturownia` |
| `fchub-memberships` | Memberships | `fchub-memberships/fchub-memberships.php` | 6.7 | 8.3 | `admin.php?page=fchub-memberships` | `/docs/fchub-memberships` |
| `fchub-portal-extender` | Portal Extender | `fchub-portal-extender/fchub-portal-extender.php` | 6.4 | 8.1 | `admin.php?page=fchub-portal-extender` | `/docs/fchub-portal-extender` |
| `fchub-wishlist` | Wishlist | `fchub-wishlist/fchub-wishlist.php` | 6.7 | 8.3 | `admin.php?page=fluent-cart#/settings/wishlist` | `/docs/fchub-wishlist` |
| `fchub-multi-currency` | Multi-Currency | `fchub-multi-currency/fchub-multi-currency.php` | 6.7 | 8.3 | `admin.php?page=fluent-cart#/settings/multi-currency` | `/docs/fchub-multi-currency` |

Every entry has `dependencies: ["fluentcart"]`, a friendly two-sentence-or-shorter description, and `status: "stable"`. Store paths, not absolute site URLs, for `admin_path`.

- [ ] **Step 4: Register FCHub 1.0.0 centrally**

Add this entry to `web-docs/lib/versions.json`:

```json
"fchub": {
  "version": "1.0.0",
  "tagName": "fchub/v1.0.0",
  "zipFilename": "fchub-1.0.0.zip",
  "mcpbFilename": null
}
```

- [ ] **Step 5: Implement deterministic catalogue generation**

Create `scripts/sync-fchub-catalog.mjs` with these public functions:

```js
export const STABLE_SLUGS = Object.freeze([
  'fchub-p24',
  'fchub-fakturownia',
  'fchub-memberships',
  'fchub-portal-extender',
  'fchub-wishlist',
  'fchub-multi-currency',
])

export function buildCatalogue(metadata, versions) {
  const products = Object.fromEntries(STABLE_SLUGS.map((slug) => {
    const product = metadata.products[slug]
    const release = versions.plugins[slug]
    if (!product || !release) {
      throw new Error(`Missing catalogue source for ${slug}`)
    }

    const base = 'https://github.com/vcode-sh/fchub-plugins'
    const asset = `${base}/releases/download/${release.tagName}/${release.zipFilename}`

    return [slug, {
      ...product,
      version: release.version,
      docs_url: `https://fchub.co${product.docs_path}`,
      release_url: `${base}/releases/tag/${release.tagName}`,
      package_url: asset,
      checksum_url: `${asset}.sha256`,
    }]
  }))

  const hubRelease = versions.plugins.fchub
  const hubBase = 'https://github.com/vcode-sh/fchub-plugins'
  const hubAsset = `${hubBase}/releases/download/${hubRelease.tagName}/${hubRelease.zipFilename}`

  return {
    schema_version: 1,
    hub: {
      version: hubRelease.version,
      plugin_file: 'fchub/fchub.php',
      release_url: `${hubBase}/releases/tag/${hubRelease.tagName}`,
      package_url: hubAsset,
      checksum_url: `${hubAsset}.sha256`,
    },
    products,
  }
}

export function validateCatalogue(catalogue) {
  if (
    catalogue?.schema_version !== 1
    || catalogue?.hub?.plugin_file !== 'fchub/fchub.php'
    || !catalogue.products
  ) return false
  return Object.entries(catalogue.products).every(([slug, product]) =>
    STABLE_SLUGS.includes(slug)
    && product.status === 'stable'
    && product.plugin_file === `${slug}/${slug}.php`
    && /^https:\/\/fchub\.co\/docs\//.test(product.docs_url)
    && /^https:\/\/github\.com\/vcode-sh\/fchub-plugins\/releases\//.test(product.package_url)
  )
}
```

The executable part reads the two JSON sources, writes the same formatted JSON plus a trailing newline to `plugins/fchub/resources/catalog.json` and `web-docs/lib/fchub-catalog.json`, and supports `--check` by comparing both committed outputs without writing.

- [ ] **Step 6: Add the cacheable Next.js route**

Create `web-docs/app/api/v1/products/route.ts`. Import `web-docs/lib/fchub-catalog.json`, calculate a SHA-256 ETag from the serialised body, honour `If-None-Match`, and return:

```ts
return new Response(body, {
  status: 200,
  headers: {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "public, max-age=300, s-maxage=3600, stale-while-revalidate=86400",
    ETag: etag,
  },
});
```

Export `dynamic = "force-dynamic"` so the route can honour `If-None-Match`; rely on the explicit CDN cache headers for public caching. Ensure the output contains no unpublished or discontinued entries.

- [ ] **Step 7: Add catalogue scripts and run the green contract**

Add to `web-docs/package.json`:

```json
"catalogue:check": "node ../scripts/sync-fchub-catalog.mjs --check"
```

Run:

```bash
node scripts/sync-fchub-catalog.mjs
node --test tests/repository/fchub-catalog.test.mjs
cd web-docs && npm run catalogue:check && npm run build
```

Expected: catalogue tests PASS, catalogue check reports no drift, and Next.js builds the `/api/v1/products` route.

**Owner checkpoint:** Report the catalogue diff and test output. Do not commit.

---

### Task 2: Scaffold the autonomous FCHub plugin and WordPress admin entry

**Files:**

- Create: `plugins/fchub/fchub.php`
- Create: `plugins/fchub/index.php`
- Create: `plugins/fchub/uninstall.php`
- Create: `plugins/fchub/.distignore`
- Create: `plugins/fchub/composer.json`
- Create: `plugins/fchub/composer.lock`
- Create: `plugins/fchub/phpunit.xml.dist`
- Create: `plugins/fchub/app/Core/Plugin.php`
- Create: `plugins/fchub/app/Support/AdminMenu.php`
- Create: `plugins/fchub/app/Support/AssetManifest.php`
- Create: `plugins/fchub/assets/icons/fchub.svg`
- Create: `plugins/fchub/tests/bootstrap.php`
- Create: `plugins/fchub/tests/Unit/PluginBootstrapTest.php`
- Create: `plugins/fchub/tests/Unit/AssetManifestTest.php`
- Create: `plugins/fchub/tests/Unit/UninstallTest.php`

**Interfaces:**

- Produces: `FChubHub\Core\Plugin::boot(): void`.
- Produces: WordPress admin page slug `fchub`.
- Produces: constants `FCHUB_HUB_VERSION`, `FCHUB_HUB_FILE`, `FCHUB_HUB_PATH`, and `FCHUB_HUB_URL`.
- Consumes: generated `resources/catalog.json`.

- [ ] **Step 1: Write failing header, boot, menu, and uninstall tests**

The tests must assert:

```php
self::assertStringContainsString('Plugin Name: FCHub', $source);
self::assertStringContainsString('Version: 1.0.0', $source);
self::assertStringContainsString('Requires at least: 6.4', $source);
self::assertStringContainsString('Requires PHP: 8.1', $source);
self::assertStringContainsString('Update URI: https://fchub.co/fchub', $source);
self::assertSame('fchub', $registeredMenu['menu_slug']);
self::assertSame('manage_options', $registeredMenu['capability']);
```

`UninstallTest` seeds all four FCHub-owned option/transient keys plus a fake `fchub_membership_plans` option, executes `uninstall.php`, and asserts that only the hub keys are removed.

- [ ] **Step 2: Confirm the scaffold tests fail**

Run:

```bash
cd plugins/fchub
composer install
./vendor/bin/phpunit
```

Expected: FAIL because the plugin bootstrap files do not exist.

- [ ] **Step 3: Create the plugin header and isolated autoloader**

`fchub.php` must define version `1.0.0`, the approved compatibility headers, and this autoloader contract:

```php
spl_autoload_register(static function (string $class): void {
    $prefix = 'FChubHub\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = FCHUB_HUB_PATH . 'app/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

\FChubHub\Core\Plugin::boot();
```

Do not require FluentCart or the existing global updater.

Create `composer.json` with `php: >=8.1`, `phpunit/phpunit: ^10.5` as the only development dependency, and PSR-4 mappings for `FChubHub\` and `FChubHub\Tests\`. Generate `composer.lock` with Composer 2.

- [ ] **Step 4: Implement the boot and menu boundary**

`Plugin::boot()` registers:

```php
add_action('admin_menu', [AdminMenu::class, 'register'], 28);
add_filter('plugin_action_links_' . plugin_basename(FCHUB_HUB_FILE), [AdminMenu::class, 'actionLinks']);
```

`AdminMenu::register()` creates one top-level `FCHub` menu with slug `fchub`, `manage_options`, and the bundled FCHub SVG as a data URI. It adds only Overview, Products, and System hash-route submenu entries. It does not touch another plugin's menu.

- [ ] **Step 5: Implement manifest-driven asset loading**

`AssetManifest` accepts the dist directory and entry key, reads `.vite/manifest.json`, recursively collects imported CSS once, and returns:

```php
[
    'script' => 'assets/fchub-admin.js',
    'styles' => ['assets/fchub-admin.css'],
    'version' => (string) filemtime($manifestPath),
]
```

`AdminMenu::render()` enqueues only on the FCHub page, adds `type="module"` to the FCHub script handle, injects `window.fchubAdmin` with `rest_url`, `nonce`, `admin_url`, `version`, and `locale`, and renders `<div id="fchub-app"></div>`.

- [ ] **Step 6: Implement narrow uninstall cleanup**

`uninstall.php` requires `WP_UNINSTALL_PLUGIN`, deletes:

```php
[
    'fchub_catalogue_last_good',
    'fchub_catalogue_etag',
    'fchub_catalogue_last_refresh',
]
```

and deletes transient `fchub_catalogue_fresh`. On multisite, iterate sites in bounded pages of 100 and restore the original blog after cleanup.

- [ ] **Step 7: Define the distribution boundary**

Create `.distignore` that excludes development and source-only files while retaining `resources/catalog.json`:

```text
.git/
.gitignore
.distignore
node_modules/
vendor/
tests/
smoke/
resources/admin/
.phpunit.cache/
test-results/
composer.json
composer.lock
package.json
package-lock.json
vite.config.js
playwright.config.js
phpunit.xml
phpunit.xml.dist
*.md
.DS_Store
Thumbs.db
```

- [ ] **Step 8: Run the scaffold suite**

Run:

```bash
cd plugins/fchub
composer audit --locked --no-interaction
./vendor/bin/phpunit
```

Expected: all scaffold tests PASS on PHP 8.1.

**Owner checkpoint:** Report the new plugin surface and PHP test count. Do not commit.

---

### Task 3: Implement catalogue validation, fallback, descriptors, and state resolution

**Files:**

- Create: `plugins/fchub/app/Catalogue/CatalogueValidator.php`
- Create: `plugins/fchub/app/Catalogue/CatalogueRepository.php`
- Create: `plugins/fchub/app/Catalogue/DescriptorRegistry.php`
- Create: `plugins/fchub/app/Catalogue/ProductStateResolver.php`
- Create: `plugins/fchub/app/Support/HubUpdater.php`
- Modify: `plugins/fchub/app/Core/Plugin.php`
- Create: `plugins/fchub/tests/Unit/CatalogueValidatorTest.php`
- Create: `plugins/fchub/tests/Unit/CatalogueRepositoryTest.php`
- Create: `plugins/fchub/tests/Unit/DescriptorRegistryTest.php`
- Create: `plugins/fchub/tests/Unit/ProductStateResolverTest.php`
- Create: `plugins/fchub/tests/Unit/HubUpdaterTest.php`

**Interfaces:**

- Produces: `CatalogueValidator::validate(array $catalogue): array`.
- Produces: `CatalogueRepository::get(bool $forceRefresh = false): array`.
- Produces: `DescriptorRegistry::collect(array $catalogue): array`.
- Produces: `ProductStateResolver::resolve(array $catalogue, array $installed, array $active, array $descriptors): array`.
- Produces: isolated `HubUpdater::register()` handling only `fchub/fchub.php`.
- Consumed later by: REST routes, updater, Vue store, and operation refresh responses.

- [ ] **Step 1: Write catalogue validator tests**

Cover the exact rejection cases:

```php
#[DataProvider('invalidCatalogues')]
public function testRejectsInvalidCatalogue(array $catalogue, string $code): void
{
    $this->expectExceptionMessage($code);
    (new CatalogueValidator())->validate($catalogue);
}
```

Data providers include schema version other than `1`, an unknown slug, a plugin file that does not equal `{$slug}/{$slug}.php`, a non-HTTPS URL, a docs host other than `fchub.co`, and a package host outside `github.com`.

- [ ] **Step 2: Write repository fallback tests**

Inject callables for remote fetch, option access, transient access, and clock. Assert this precedence:

```text
fresh transient -> valid remote -> last-known-good option -> bundled catalogue
```

Also assert an invalid remote response does not call the last-known-good writer.

- [ ] **Step 3: Write descriptor and state tests**

Use a known Memberships catalogue entry and assert:

```php
self::assertSame('active', $state['lifecycle']);
self::assertSame('available', $state['update']);
self::assertSame('blocked', $state['compatibility']);
self::assertSame('php', $state['compatibility_reason']['requirement']);
```

Test PHP 8.2 against a PHP 8.3 product, an inactive installed plugin, a missing FluentCart dependency, an invalid descriptor plugin file, and a healthy valid descriptor.

- [ ] **Step 4: Confirm the domain tests fail**

Run:

```bash
cd plugins/fchub
./vendor/bin/phpunit tests/Unit/CatalogueValidatorTest.php tests/Unit/CatalogueRepositoryTest.php tests/Unit/DescriptorRegistryTest.php tests/Unit/ProductStateResolverTest.php
```

Expected: FAIL because the four domain classes do not exist.

- [ ] **Step 5: Implement strict catalogue validation**

Allow only the six stable slugs. Normalise each product to the exact keys in the design. Validate versions with `version_compare`, validate URLs with `wp_parse_url`, and restrict hosts:

```php
private const DOCS_HOSTS = ['fchub.co'];
private const PACKAGE_HOSTS = [
    'github.com',
    'objects.githubusercontent.com',
    'release-assets.githubusercontent.com',
];
```

Validate the top-level `hub` record with the same HTTPS and release-host rules, while keeping it outside the rendered product map. Return a normalised catalogue. Throw `UnexpectedValueException` with stable internal codes such as `catalogue_schema_invalid` and `catalogue_package_host_invalid`.

Expose two narrowly scoped filters for the disposable local harness:

```php
$hosts = apply_filters('fchub/catalogue/allowed_package_hosts', self::PACKAGE_HOSTS);
$allowHttp = (bool) apply_filters('fchub/catalogue/allow_http', false, $url);
```

The default remains the fixed HTTPS allow-list; the lifecycle MU-plugin permits only local host `catalogue`.

- [ ] **Step 6: Implement cached remote retrieval and safe fallback**

Use `wp_safe_remote_get()` with an eight-second timeout, `Accept: application/json`, the stored ETag, and user agent `FCHub/1.0.0`. A `304` refreshes the freshness transient without replacing the body. A valid `200` writes the last-known-good option, ETag, refresh time, and six-hour transient. Every failure returns the previous valid layer.

Expose the source in the returned envelope as `remote`, `last_good`, or `bundled`; never mutate the catalogue product data to store cache metadata.

- [ ] **Step 7: Implement the descriptor allow-list**

Apply:

```php
$descriptors = apply_filters('fchub/products', []);
```

Accept only arrays with `schema_version === 1`, a known slug, and an exact `plugin_file` match. Allow only `admin_path` and `health`. Restrict `health.status` to `healthy`, `attention`, or `unknown`, and sanitise the message with `sanitize_text_field()`.

- [ ] **Step 8: Implement independent state dimensions**

Resolve:

```php
[
    'slug' => $slug,
    'lifecycle' => 'not_installed|inactive|active',
    'update' => 'current|available|unknown',
    'compatibility' => 'compatible|blocked|unknown',
    'compatibility_reason' => null,
    'health' => 'healthy|attention|unknown',
    'health_message' => null,
    'actions' => [],
]
```

Generate actions from state and current user capabilities, but do not perform operations. Build same-origin admin URLs with `admin_url(ltrim($adminPath, '/'))`.

- [ ] **Step 9: Implement and test the isolated FCHub updater**

`HubUpdaterTest` loads a harmless fake `FCHub_GitHub_Updater` first, registers `HubUpdater`, and asserts the `update_plugins_fchub.co` filter still returns the top-level `hub` update when the catalogue version is newer.

`HubUpdater::register()` handles only `fchub/fchub.php`, reads the validated top-level `hub` release record through `CatalogueRepository`, and returns WordPress update metadata without declaring or loading the global updater class. Add `HubUpdater::register()` to `Plugin::boot()` only after this test passes.

- [ ] **Step 10: Run focused and full PHP suites**

Run:

```bash
cd plugins/fchub
./vendor/bin/phpunit tests/Unit/CatalogueValidatorTest.php tests/Unit/CatalogueRepositoryTest.php tests/Unit/DescriptorRegistryTest.php tests/Unit/ProductStateResolverTest.php tests/Unit/HubUpdaterTest.php
./vendor/bin/phpunit
```

Expected: all domain and scaffold tests PASS.

**Owner checkpoint:** Report state examples for healthy, update, and incompatible products. Do not commit.

---

### Task 4: Add secure REST reads and product operations

**Files:**

- Create: `plugins/fchub/app/Operations/OperationError.php`
- Create: `plugins/fchub/app/Operations/VerifiedPackageDownloader.php`
- Create: `plugins/fchub/app/Operations/ProductOperationService.php`
- Create: `plugins/fchub/app/Http/ProductController.php`
- Create: `plugins/fchub/app/Http/Routes.php`
- Modify: `plugins/fchub/app/Core/Plugin.php`
- Create: `plugins/fchub/tests/Unit/VerifiedPackageDownloaderTest.php`
- Create: `plugins/fchub/tests/Unit/ProductOperationServiceTest.php`
- Create: `plugins/fchub/tests/Unit/RoutesTest.php`

**Interfaces:**

- Produces: `VerifiedPackageDownloader::download(array $product): string`, returning an owned temporary ZIP path.
- Produces: `ProductOperationService::{install, installAndActivate, activate, update, deactivate}(string $slug): array`.
- Produces: `ProductController` REST callbacks returning refreshed product and summary state.
- Consumes: trusted catalogue and resolved state from Task 3.

- [ ] **Step 1: Write downloader verification tests**

Assert:

- Unknown package hosts fail before any request.
- A matching SHA-256 checksum returns the temporary path.
- A mismatch unlinks the file and throws `package_verification_failed`.
- A `404` checksum allows a trusted package and records `checksum_unavailable`.
- Network and HTTP errors return stable codes without response bodies.

Use an injected downloader callable so unit tests never contact GitHub.

- [ ] **Step 2: Write operation service tests**

For each operation, assert the exact capability and preconditions:

```php
[
    'install' => 'install_plugins',
    'install-and-activate' => ['install_plugins', 'activate_plugins'],
    'activate' => 'activate_plugins',
    'update' => 'update_plugins',
    'deactivate' => 'activate_plugins',
]
```

Test an unknown slug, incompatible PHP version, missing FluentCart, already-active product, failed upgrader, successful install without activation, explicit install-and-activate, update version confirmation, and FCHub-safe deactivation.

- [ ] **Step 3: Write REST route contract tests**

Assert these routes and methods:

```text
GET  /fchub/v1/products
POST /fchub/v1/products/{slug}/install
POST /fchub/v1/products/{slug}/install-and-activate
POST /fchub/v1/products/{slug}/activate
POST /fchub/v1/products/{slug}/update
POST /fchub/v1/products/{slug}/deactivate
POST /fchub/v1/catalogue/refresh
```

Assert permission callbacks use operation-specific capabilities and that public errors contain only `success`, `code`, `message`, and `product`.

- [ ] **Step 4: Confirm REST and operation tests fail**

Run:

```bash
cd plugins/fchub
./vendor/bin/phpunit tests/Unit/VerifiedPackageDownloaderTest.php tests/Unit/ProductOperationServiceTest.php tests/Unit/RoutesTest.php
```

Expected: FAIL because the operation and HTTP classes do not exist.

- [ ] **Step 5: Implement verified temporary downloads**

Use `download_url()` for the trusted package and checksum URLs. Parse a checksum file only when it matches:

```php
if (!preg_match('/\A([a-f0-9]{64})(?:\s+\*?.+)?\z/i', trim($body), $matches)) {
    throw new OperationError('checksum_invalid', 'The release checksum could not be read.');
}
```

Compare with `hash_file('sha256', $temporaryZip)` via `hash_equals()`. Always delete owned temporary files in `finally` blocks after the upgrader finishes.

- [ ] **Step 6: Implement operations through WordPress core**

Load `wp-admin/includes/plugin.php`, `file.php`, and `class-wp-upgrader.php` only inside mutation paths. Use `Plugin_Upgrader` with a non-echoing skin and:

```php
$upgrader->install($temporaryZip, [
    'overwrite_package' => $isUpdate,
    'clear_update_cache' => true,
]);
```

After installation, call `wp_clean_plugins_cache(true)`, confirm the expected plugin file exists, and compare the installed header version to the catalogue. Activation occurs only in `installAndActivate()` or `activate()`.

- [ ] **Step 7: Implement friendly public errors**

Map internal failures to messages such as:

```php
'product_incompatible' => sprintf(
    __('%1$s needs PHP %2$s before it can be activated.', 'fchub'),
    $productName,
    $requiredVersion
),
'package_verification_failed' => __(
    'The package did not pass its safety check, so nothing was changed.',
    'fchub'
),
```

Log internal context through `error_log()` only when `WP_DEBUG` is enabled. Never return filesystem paths, stack traces, credentials, or remote bodies.

- [ ] **Step 8: Implement controllers and refreshed responses**

`GET /products` returns:

```json
{
  "products": [],
  "summary": {
    "active": 0,
    "updates": 0,
    "compatibility_issues": 0
  },
  "catalogue": {
    "source": "remote",
    "last_refresh": "2026-07-24T17:00:00+00:00"
  },
  "capabilities": {
    "install": true,
    "activate": true,
    "update": true
  }
}
```

Every successful mutation returns the same envelope plus `notice`.

After the route tests pass, add:

```php
add_action('rest_api_init', [Routes::class, 'register']);
```

to `Plugin::boot()`.

- [ ] **Step 9: Run security-focused and full PHP suites**

Run:

```bash
cd plugins/fchub
./vendor/bin/phpunit tests/Unit/VerifiedPackageDownloaderTest.php tests/Unit/ProductOperationServiceTest.php tests/Unit/RoutesTest.php
./vendor/bin/phpunit
```

Expected: all tests PASS and no test performs an external request.

**Owner checkpoint:** Report capability coverage and package-verification cases. Do not commit.

---

### Task 5: Build the approved calm FCHub admin interface

**Files:**

- Create: `plugins/fchub/package.json`
- Create: `plugins/fchub/package-lock.json`
- Create: `plugins/fchub/vite.config.js`
- Create: `plugins/fchub/resources/admin/main.js`
- Create: `plugins/fchub/resources/admin/App.vue`
- Create: `plugins/fchub/resources/admin/router/index.js`
- Create: `plugins/fchub/resources/admin/api/client.js`
- Create: `plugins/fchub/resources/admin/stores/products.js`
- Create: `plugins/fchub/resources/admin/pages/OverviewPage.vue`
- Create: `plugins/fchub/resources/admin/pages/ProductsPage.vue`
- Create: `plugins/fchub/resources/admin/pages/SystemPage.vue`
- Create: `plugins/fchub/resources/admin/components/AttentionPanel.vue`
- Create: `plugins/fchub/resources/admin/components/ProductCard.vue`
- Create: `plugins/fchub/resources/admin/components/StatusBadge.vue`
- Create: `plugins/fchub/resources/admin/components/SummaryCard.vue`
- Create: `plugins/fchub/resources/admin/styles/variables.css`
- Create: `plugins/fchub/resources/admin/styles/global.css`
- Copy: `plugins/cartshift/resources/admin/fonts/inter-latin.woff2` to `plugins/fchub/assets/fonts/inter-latin.woff2`
- Create: `plugins/fchub/tests/admin/setup.js`
- Create: `plugins/fchub/tests/admin/ProductCard.test.js`
- Create: `plugins/fchub/tests/admin/ProductsStore.test.js`

**Interfaces:**

- Consumes: `window.fchubAdmin` and the REST envelope from Task 4.
- Produces: routes `/`, `/products`, and `/system`.
- Produces: `useProductsStore()` with `load()`, `refreshCatalogue()`, and `runAction(slug, action)`.

- [ ] **Step 1: Define package scripts and pinned dependencies**

Use:

```json
{
  "scripts": {
    "build": "vite build",
    "test": "vitest run",
    "test:watch": "vitest",
    "test:smoke": "playwright test"
  },
  "dependencies": {
    "@element-plus/icons-vue": "^2.3.2",
    "@lucide/vue": "^1.25.0",
    "element-plus": "^2.13.5",
    "vue": "^3.5.30",
    "vue-router": "^5.0.3"
  },
  "devDependencies": {
    "@playwright/test": "1.55.1",
    "@vitejs/plugin-vue": "6.0.5",
    "@vue/test-utils": "2.4.6",
    "jsdom": "26.1.0",
    "vite": "8.0.16",
    "vitest": "3.2.7"
  }
}
```

Generate the lockfile with `npm install --package-lock-only`.

- [ ] **Step 2: Write failing store tests**

Cover:

```js
expect(store.summary).toEqual({
  active: 5,
  updates: 1,
  compatibility_issues: 0,
})
expect(store.actionPending).toEqual({})
expect(store.error).toBeNull()
```

Assert `runAction('fchub-memberships', 'update')` posts to the exact route, replaces the full response envelope, clears pending state, and retains the friendly server message on failure.

- [ ] **Step 3: Write failing product-card tests**

Mount healthy, inactive, update, incompatible, and not-installed products. Assert:

- Exactly one primary action.
- Documentation and release notes remain secondary links.
- Incompatible actions are disabled with the reason visible to keyboard and pointer users.
- Action completion restores focus to the triggering button.
- No raw `compatibility_reason` object is rendered.

- [ ] **Step 4: Confirm frontend tests fail**

Run:

```bash
cd plugins/fchub
npm ci
npm test
```

Expected: FAIL because the store and components do not exist.

- [ ] **Step 5: Implement the API client and store**

The client sends `X-WP-Nonce`, accepts only JSON, and converts a non-2xx response into:

```js
{
  code: body?.code || 'request_failed',
  message: body?.message || 'WordPress could not complete that action.',
}
```

The store owns the response envelope, pending actions keyed by slug, and one top-level recoverable error. It never guesses product state locally after a mutation; it replaces state with the server response.

- [ ] **Step 6: Implement the three-route application shell**

Use a compact header with the FCHub icon, product name, and pill navigation for Overview, Products, and System. Preserve WordPress admin chrome. Do not suppress unrelated WordPress notices globally; hide only notices inside the FCHub page container when they overlap the SPA.

- [ ] **Step 7: Implement the approved calm Overview**

Render:

- Healthy hero: “Everything is ticking along nicely.”
- Summary cards for active products, useful updates, and compatibility issues.
- Installed products before discovery.
- An attention panel only when update or compatibility counts are non-zero.
- A secondary discovery section for not-installed products.

Do not expose tables, option names, checksums, transients, or transport status on Overview.

- [ ] **Step 8: Implement Products and System**

Products provides all/installed/updates filters and product cards. System provides WordPress, PHP, FluentCart, FCHub version, catalogue source, last refresh, and a manual refresh button. It translates `last_good` to “Using the last saved catalogue” and `bundled` to “Using the catalogue included with FCHub”.

- [ ] **Step 9: Implement exact FCHub tokens and local typography**

`variables.css` defines the approved light and dark values from the specification. Define:

```css
--el-color-primary: var(--fchub-primary);
--fchub-primary: #4D6EF5;
```

Use an `@font-face` pointing to the bundled WOFF2. Scope all resets to `#fchub-app`; explicitly reset WordPress form backgrounds, focus shadows, width constraints, and select arrows without dequeuing WordPress admin styles.

- [ ] **Step 10: Run frontend tests and build**

Run:

```bash
cd plugins/fchub
npm audit --audit-level=high
npm test
npm run build
test -f assets/dist/.vite/manifest.json
find assets/dist -name '*.js' -o -name '*.css'
```

Expected: Vitest PASS; Vite emits a manifest, JavaScript, and CSS; no build output references Google Fonts.

**Owner checkpoint:** Review Overview at desktop and narrow widths using the production build. Do not commit.

---

### Task 6: Add browser states, accessibility checks, and visual regression coverage

**Files:**

- Create: `plugins/fchub/playwright.config.js`
- Create: `plugins/fchub/tests/e2e/admin-ui.spec.js`
- Create: `plugins/fchub/tests/e2e/fixtures/healthy.json`
- Create: `plugins/fchub/tests/e2e/fixtures/update.json`
- Create: `plugins/fchub/tests/e2e/fixtures/incompatible.json`
- Create: `plugins/fchub/tests/e2e/fixtures/offline.json`
- Create: `plugins/fchub/tests/e2e/fixtures/failed-operation.json`
- Create: `plugins/fchub/smoke/index.html`
- Create: `plugins/fchub/smoke/main.js`

**Interfaces:**

- Consumes: the production Vite assets and REST response schema.
- Produces: deterministic UI smoke and screenshot coverage independent of the long-lived playground.

- [ ] **Step 1: Create the production-asset smoke host**

`smoke/index.html` provides `#fchub-app`, while `smoke/main.js` intercepts `/wp-json/fchub/v1/**` and serves the selected fixture from `?fixture=healthy`. It must load the built manifest assets, not Vite development modules.

- [ ] **Step 2: Write the healthy and update smoke tests**

Assert:

```js
await expect(page.getByRole('heading', {
  name: 'Everything is ticking along nicely.',
})).toBeVisible()
await expect(page.getByText('5', { exact: true })).toBeVisible()
await expect(page.getByRole('button', { name: 'Update Multi-Currency' })).toBeVisible()
```

Capture approved screenshots at 1440×1000 and 768×1024.

- [ ] **Step 3: Write incompatible, offline, and failure tests**

Assert useful text, no stack traces, no `undefined`, and no raw JSON. Verify an unavailable catalogue still renders products from fallback fixtures. Verify a failed update returns focus to the Update button and announces the error through an `aria-live` region.

- [ ] **Step 4: Add keyboard and contrast assertions**

Tab through primary navigation, filters, product actions, and refresh. Assert visible focus. Use computed styles to assert primary buttons use `rgb(77, 110, 245)` and the page/card/text/border values match approved tokens.

- [ ] **Step 5: Run the smoke suite**

Run:

```bash
cd plugins/fchub
npm run build
npx playwright install chromium
npm run test:smoke
```

Expected: all five fixture families PASS and snapshots contain only FCHub palette values.

**Owner checkpoint:** Review generated screenshots at full size. Do not commit.

---

### Task 7: Automate clean WordPress installation, update, removal, and independence

**Files:**

- Create: `plugins/fchub/tests/e2e/docker-compose.yml`
- Create: `plugins/fchub/tests/e2e/fixtures/nginx.conf`
- Create: `plugins/fchub/tests/e2e/fchub-lifecycle.spec.js`
- Create: `plugins/fchub/tests/e2e/run-lifecycle.sh`
- Create: `plugins/fchub/tests/e2e/prepare-fixtures.mjs`

**Interfaces:**

- Produces: `bash tests/e2e/run-lifecycle.sh`.
- Consumes: `dist/fchub-1.0.0.zip`, a deterministic fixture product ZIP, and the production FCHub catalogue override.
- Guarantees: no use of `fchub-playground` containers or volumes.

- [ ] **Step 1: Define isolated services**

The Compose project uses:

```yaml
services:
  db:
    image: mariadb:11.4
  wordpress:
    image: wordpress:6.7-php8.1-apache
  wpcli:
    image: wordpress:cli-php8.1
  catalogue:
    image: nginx:1.27-alpine
```

Use project name `fchub-lifecycle-${FCHUB_TEST_RUN_ID}` and newly created named volumes. Bind WordPress to an ephemeral host port and mount only the generated fixture directory into Nginx.

- [ ] **Step 2: Generate deterministic fixture releases**

`prepare-fixtures.mjs` creates allow-listed `fchub-p24` fixture versions 1.0.0 and 1.1.0 with a minimal valid WordPress header, plus a minimal `fluent-cart` fixture that defines `FLUENTCART_VERSION`. It zips P24 under root `fchub-p24/`, calculates SHA-256 files, and writes catalogue versions pointing to `http://catalogue/`.

The harness injects `FCHUB_CATALOGUE_URL`, sets `WP_ENVIRONMENT_TYPE=local`, and installs an MU-plugin filter that allows only host `catalogue` over HTTP. Production defaults remain HTTPS-only and the production allow-list remains unchanged.

- [ ] **Step 3: Write the lifecycle browser test**

The test logs into the disposable WordPress admin and:

1. Opens FCHub Overview.
2. Installs and activates the FluentCart fixture, then installs and activates P24 fixture 1.0.0 through the FCHub button.
3. Confirms WordPress reports the plugin active.
4. Switches the fixture catalogue to 1.1.0.
5. Updates through FCHub and confirms the version.
6. Deactivates and deletes FCHub through WordPress.
7. Confirms the fixture plugin remains active.
8. Reinstalls FCHub and confirms the fixture is rediscovered.

- [ ] **Step 4: Add WP-CLI ownership assertions**

After uninstall, run:

```bash
wp option get fchub_catalogue_last_good
wp transient get fchub_catalogue_fresh
wp plugin is-active fchub-p24
wp plugin get fchub-p24 --field=version
```

Expected: the two FCHub reads report missing values; P24 remains active at 1.1.0.

- [ ] **Step 5: Make cleanup unconditional**

`run-lifecycle.sh` creates a run-specific fixture directory with `mktemp -d`, validates that the Compose project name begins with `fchub-lifecycle-`, and installs an EXIT trap that runs:

```bash
docker compose -p "$project_name" down --volumes --remove-orphans
```

It must never remove a playground volume or use a broad Docker prune.

- [ ] **Step 6: Run the disposable lifecycle**

Run:

```bash
cd plugins/fchub
bash tests/e2e/run-lifecycle.sh
```

Expected: clean install, product install, product update, FCHub removal, independent product survival, FCHub reinstall, and exact cleanup all PASS.

**Owner checkpoint:** Report the Compose project name, assertions, and confirmed volume removal. Do not commit.

---

### Task 8: Replace the general FCHub docs root with product-centre documentation

**Files:**

- Modify: `web-docs/content/docs/fchub/index.mdx`
- Modify: `web-docs/content/docs/fchub/meta.json`
- Create: `web-docs/content/docs/fchub/installation.mdx`
- Create: `web-docs/content/docs/fchub/managing-products.mdx`
- Create: `web-docs/content/docs/fchub/system-status.mdx`
- Create: `web-docs/content/docs/fchub/troubleshooting.mdx`
- Create: `web-docs/content/docs/fchub/changelog.mdx`
- Modify: `web-docs/app/docs/layout.tsx`
- Modify: `web-docs/app/(home)/page.tsx`
- Modify: `web-docs/app/(home)/layout.tsx`
- Create: `scripts/check-fchub-docs.mjs`
- Modify: `.github/workflows/docs-ci.yml`

**Interfaces:**

- Consumes: central `versions["fchub"]`.
- Produces: `/docs/fchub` as the product-centre documentation root.
- Keeps: brief suite overview and links to each stable product.

- [ ] **Step 1: Write the documentation contract**

`scripts/check-fchub-docs.mjs` asserts:

- `meta.json` lists index, installation, managing-products, system-status, troubleshooting, and changelog.
- The index uses `<PluginDownload plugin="fchub" />`.
- No hardcoded FCHub release URL exists.
- No page presents Stream, Thank You, Redsys, CartShift, or WPLove as a hub product.
- The installation page states WordPress 6.4 and PHP 8.1.
- The troubleshooting page explains catalogue fallback and product independence.

- [ ] **Step 2: Confirm the docs contract fails**

Run:

```bash
node scripts/check-fchub-docs.mjs
```

Expected: FAIL because the product-centre pages and metadata do not exist.

- [ ] **Step 3: Rewrite the overview in the approved voice**

Lead with:

> One calm place for every FCHub product on this site.

Explain Overview, Products, and System in customer language. Present the six stable products with short cards. Mention that products work without FCHub and that removing the hub does not remove them. Do not explain internal catalogue tables, filters, or PHP classes.

- [ ] **Step 4: Write the five supporting pages**

- Installation: install ZIP, activate FCHub, minimum requirements, first open.
- Managing products: state badges and exact actions.
- System status: compatibility, catalogue source, and refresh in friendly language.
- Troubleshooting: offline fallback, missing permissions, incompatible products, failed package verification, and safe removal.
- Changelog: FCHub 1.0.0 with a brief, exciting summary of the product centre, safe product actions, compatibility guidance, and offline resilience.

- [ ] **Step 5: Update website discovery**

Use `versions["fchub"].releaseUrl` for the FCHub card. Change the docs sidebar description from `FluentCommunity` to `FCHub suite`. Add FCHub to the main product navigation without moving CartShift into the hub catalogue.

- [ ] **Step 6: Add the docs gate and build**

Add `node scripts/check-fchub-docs.mjs` to `docs-ci.yml`, then run:

```bash
node scripts/check-fchub-docs.mjs
node scripts/check-fumadocs-accordion-structure.mjs
cd web-docs
npm run lint
npm run build
```

Expected: docs contract, Fumadocs structure check, lint, and production build PASS.

**Owner checkpoint:** Review customer-facing copy and links. Do not commit.

---

### Task 9: Integrate build, CI, checksums, and the FCHub 1.0.0 release contract

**Files:**

- Modify: `build.sh`
- Modify: `.github/workflows/ci.yml`
- Modify: `.github/workflows/release.yml`
- Create: `.github/workflows/fchub-ci-contract.test.mjs`
- Create: `.github/workflows/fchub-release-contract.test.mjs`
- Modify: `.github/workflows/docs-ci.yml`

**Interfaces:**

- Produces: `./build.sh fchub`.
- Produces: CI gates for PHP, Vue, browser, catalogue, docs, and disposable lifecycle tests.
- Produces: tag-triggered `fchub/v*` release with ZIP and `.sha256` assets.

- [ ] **Step 1: Write failing workflow contracts**

Assert CI contains:

```text
plugin: fchub
php_version: '8.1'
working-directory: plugins/fchub
npm ci
npm audit --audit-level=high
npm test
npm run test:smoke
bash tests/e2e/run-lifecycle.sh
node --test tests/repository/fchub-catalog.test.mjs
```

Assert release contains `fchub/v*`, FCHub-only PHP and JavaScript gates, catalogue drift validation, lifecycle execution, `sha256sum`, and both ZIP and checksum paths passed to `gh release create`.

- [ ] **Step 2: Confirm workflow contracts fail**

Run:

```bash
node --test .github/workflows/fchub-ci-contract.test.mjs .github/workflows/fchub-release-contract.test.mjs
```

Expected: FAIL because FCHub is not wired into build or workflows.

- [ ] **Step 3: Extend the build script**

Add:

```bash
"fchub|fchub.php"
```

to `ALL_PLUGINS`. Include `fchub` in the Node build branch, run `npm ci && npm run build`, and fail unless `assets/dist/.vite/manifest.json` and at least one JavaScript file exist.

Change the shared-updater copy loop to iterate the already selected `PLUGINS` entries instead of the `plugins/fchub-*` glob, and skip slug `fchub` because it owns a namespaced updater. This guarantees `./build.sh fchub` does not write into any other product, particularly discontinued Stream.

- [ ] **Step 4: Add FCHub CI gates**

Add FCHub PHP 8.1 to the PHPUnit matrix. Add a dedicated FCHub Node job with lockfile caching, audit, Vitest, Playwright smoke, and production build validation. Add a repository-contract job for catalogue and docs. Add the disposable lifecycle job only when `plugins/fchub/`, catalogue sources, build tooling, or FCHub workflow files change.

- [ ] **Step 5: Add the release trigger and pre-artifact gates**

Add:

```yaml
- 'fchub/v*'
```

For `steps.tag.outputs.slug == 'fchub'`, set up PHP 8.1 and Node 20, then run Composer audit, PHPUnit, npm audit, Vitest, Playwright, production build, catalogue check, docs contract, and lifecycle test before `Build ZIP`.

- [ ] **Step 6: Create and upload SHA-256 sidecars**

After building any plugin ZIP, run:

```bash
sha256sum "${zip_path}" > "${zip_path}.sha256"
echo "checksum_path=${zip_path}.sha256" >> "$GITHUB_ENV"
```

Pass both `"${zip_path}"` and `"${checksum_path}"` to `gh release create`. This makes checksums available to FCHub while preserving release compatibility for every product.

- [ ] **Step 7: Run workflow contracts and local build**

Run:

```bash
node --test .github/workflows/fchub-ci-contract.test.mjs .github/workflows/fchub-release-contract.test.mjs
node --test tests/repository/fchub-catalog.test.mjs
./build.sh fchub
unzip -t dist/fchub-1.0.0.zip
unzip -l dist/fchub-1.0.0.zip
```

Expected: contracts PASS; ZIP passes integrity; archive root is `fchub/`; `resources/catalog.json` is present; `resources/admin`, `node_modules`, `vendor`, tests, source maps, Composer files, and package files are absent.

**Owner checkpoint:** Review workflow changes and built archive contents. Do not commit or tag.

---

### Task 10: Run the complete release-candidate gate and prepare the owner handoff

**Files:**

- Verify all files from Tasks 1–9.
- Do not create release notes outside the existing GitHub release flow.

**Interfaces:**

- Produces: verified local `dist/fchub-1.0.0.zip`.
- Produces: an exact owner-only Git sequence after all gates pass.

- [ ] **Step 1: Run PHP gates**

```bash
cd plugins/fchub
composer install --no-interaction --prefer-dist
composer audit --locked --no-interaction
./vendor/bin/phpunit
```

Expected: dependency audit clean and all PHPUnit tests PASS.

- [ ] **Step 2: Run JavaScript and browser gates**

```bash
cd plugins/fchub
npm ci
npm audit --audit-level=high
npm test
npm run build
npm run test:smoke
```

Expected: audit clean, Vitest PASS, production build succeeds, and Playwright smoke PASS.

- [ ] **Step 3: Run catalogue and workflow contracts**

```bash
cd "$(git rev-parse --show-toplevel)"
node scripts/sync-fchub-catalog.mjs --check
node --test tests/repository/fchub-catalog.test.mjs
node --test .github/workflows/fchub-ci-contract.test.mjs .github/workflows/fchub-release-contract.test.mjs
```

Expected: no catalogue drift and all contracts PASS.

- [ ] **Step 4: Run documentation gates**

```bash
cd "$(git rev-parse --show-toplevel)"
node scripts/check-fchub-docs.mjs
node scripts/check-fumadocs-accordion-structure.mjs
cd web-docs
npm run lint
npm run build
```

Expected: docs contracts, lint, and Next.js production build PASS.

- [ ] **Step 5: Run the disposable lifecycle gate**

```bash
cd "$(git rev-parse --show-toplevel)/plugins/fchub"
bash tests/e2e/run-lifecycle.sh
```

Expected: clean install, activation, fixture install, fixture update, FCHub removal, independent product survival, reinstall discovery, and container/volume cleanup PASS.

- [ ] **Step 6: Build and inspect the release candidate**

```bash
cd "$(git rev-parse --show-toplevel)"
./build.sh fchub
unzip -t dist/fchub-1.0.0.zip
unzip -l dist/fchub-1.0.0.zip
shasum -a 256 dist/fchub-1.0.0.zip
git diff --check
git status --short
```

Expected: ZIP integrity PASS, contents match `.distignore`, checksum prints, diff check is clean, and status contains only intentional FCHub work plus any pre-existing owner changes.

- [ ] **Step 7: Perform a final scope audit**

Confirm:

- No file under `plugins/fchub-stream/` changed.
- Existing product main files and menus remain unchanged.
- FCHub can boot with FluentCart absent.
- The catalogue has exactly six products.
- WPLove, CartShift, experimental, and discontinued products do not appear in the FCHub UI or catalogue.
- Uninstall owns only FCHub keys.
- All customer-facing copy avoids unnecessary implementation detail.

- [ ] **Step 8: Hand the verified release to the owner**

Do not run these commands automatically. Once the owner has reviewed and committed the intentional diff, the owner publishes with:

```bash
git tag fchub/v1.0.0
git push origin HEAD
git push origin fchub/v1.0.0
```

Then verify the GitHub Actions release job succeeds and the release contains:

```text
fchub-1.0.0.zip
fchub-1.0.0.zip.sha256
```

Finally, install the published ZIP on a fresh disposable WordPress site and confirm WordPress reports FCHub 1.0.0.
