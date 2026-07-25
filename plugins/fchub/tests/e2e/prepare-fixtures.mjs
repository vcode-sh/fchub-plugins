#!/usr/bin/env node

/**
 * Everything the disposable WordPress is allowed to see, generated fresh into
 * one throwaway directory.
 *
 * Three releases of one product — two with honest SHA-256 sidecars and one with
 * a sidecar that describes a different archive — a minimal FluentCart to
 * satisfy the dependency check, the FCHub archive the site is installed from,
 * and three catalogues describing the same product at those three versions.
 * Nothing is downloaded, and nothing points anywhere but the fixture host.
 *
 * The product is `fchub-p24` on purpose. It is one of the six slugs
 * CatalogueValidator will accept, so the production validator is genuinely
 * exercised rather than handed a test-shaped hole to walk through.
 *
 *   node tests/e2e/prepare-fixtures.mjs <fixture-dir> <path-to-fchub-zip>
 */

import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { copyFileSync, mkdirSync, readFileSync, rmSync, utimesSync, writeFileSync } from 'node:fs'
import { basename, dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))

/** The only host the site may fetch anything from, and the Compose service name. */
const HOST = 'catalogue'
const BASE = `http://${HOST}`

/** The two honest fixture releases, and the versions the catalogue moves between. */
const OLD = '1.0.0'
const NEW = '1.1.0'

/**
 * The third one, published with a sidecar that does not describe it.
 *
 * Serving only good packages proves verification does not false-reject; it
 * cannot tell a working checksum step from one that was quietly deleted. This
 * release exists so the harness can watch the shipped downloader refuse
 * something, and watch the installed product not move.
 */
const CORRUPT = '1.2.0'

/** The FluentCart the dependency check is meant to find. */
const FLUENTCART = '1.2.0'

/**
 * A fixed timestamp on every staged file, so two runs of this script produce
 * byte-identical archives. Not something the harness asserts on — it just means
 * a checksum that changed is a fixture that changed, rather than a clock that
 * ticked.
 */
const STAMP = new Date('2026-01-01T00:00:00Z')

const [, , fixtureDir, hubZip] = process.argv

if (!fixtureDir || !hubZip) {
  fail('usage: prepare-fixtures.mjs <fixture-dir> <path-to-fchub-zip>')
}

const www = join(fixtureDir, 'www')
const packages = join(www, 'packages')
const staging = join(fixtureDir, '.staging')

mkdirSync(packages, { recursive: true })
mkdirSync(join(fixtureDir, 'mu-plugins'), { recursive: true })

// ── The fixture release host's own configuration ─────────────────────────────

copyFileSync(join(HERE, 'fixtures', 'nginx.conf'), join(fixtureDir, 'nginx.conf'))

// ── The MU-plugin that lets the site talk to it ──────────────────────────────

writeFileSync(join(fixtureDir, 'mu-plugins', 'fchub-lifecycle-harness.php'), muPlugin())

// ── The product, twice ───────────────────────────────────────────────────────

for (const version of [OLD, NEW]) {
  publish(
    'fchub-p24',
    version,
    {
      'fchub-p24.php': p24Plugin(version),
      'index.php': "<?php\n// Silence is golden.\n",
    },
    { checksum: 'honest' },
  )
}

// ── The same product, published badly ────────────────────────────────────────

// A perfectly good archive with a sidecar that describes a different one. The
// package host is trusted, the URLs are trusted, the download succeeds — the
// only thing wrong is the digest, which is precisely the failure the checksum
// exists to catch and the only way to prove it is being checked at all.
publish(
  'fchub-p24',
  CORRUPT,
  {
    'fchub-p24.php': p24Plugin(CORRUPT),
    'index.php': "<?php\n// Silence is golden.\n",
  },
  { checksum: 'corrupt' },
)

// ── The platform it depends on ───────────────────────────────────────────────

// No sidecar: FCHub never downloads this one. WP-CLI installs it as setup,
// because FluentCart is not an FCHub product and the interface has no business
// offering to install it.
publish(
  'fluent-cart',
  FLUENTCART,
  {
    'fluent-cart.php': fluentCartPlugin(),
    'index.php': "<?php\n// Silence is golden.\n",
  },
  { checksum: 'none' },
)

// ── FCHub itself ─────────────────────────────────────────────────────────────

// The real distribution archive, straight out of dist/. The site is installed
// from this and reinstalled from it again after the removal step, so the thing
// under test is the thing that would ship.
const hubTarget = join(packages, basename(hubZip))

copyFileSync(hubZip, hubTarget)
writeChecksum(hubTarget)

// ── The catalogues ───────────────────────────────────────────────────────────

writeJson(join(www, `catalogue-${OLD}.json`), catalogue(OLD, basename(hubTarget)))
writeJson(join(www, `catalogue-${NEW}.json`), catalogue(NEW, basename(hubTarget)))
writeJson(join(www, `catalogue-${CORRUPT}.json`), catalogue(CORRUPT, basename(hubTarget)))

// The one nginx actually serves. The harness swaps it for the 1.1.0 copy
// mid-run, which is how the update becomes available.
copyFileSync(join(www, `catalogue-${OLD}.json`), join(www, 'catalogue.json'))

rmSync(staging, { recursive: true, force: true })

process.stdout.write(`fixtures ready in ${fixtureDir}\n`)

// ── Builders ─────────────────────────────────────────────────────────────────

/**
 * Stages a plugin directory, zips it with the slug as the archive root — the
 * layout WordPress's own upgrader expects — and writes whichever sidecar the
 * caller asked for.
 *
 * @param {string} slug
 * @param {string} version
 * @param {Record<string, string>} files Path inside the plugin directory, to contents.
 * @param {{checksum: 'honest'|'corrupt'|'none'}} options
 */
function publish(slug, version, files, { checksum }) {
  const root = join(staging, `${slug}-${version}`)
  const pluginDir = join(root, slug)

  rmSync(root, { recursive: true, force: true })
  mkdirSync(pluginDir, { recursive: true })

  for (const [name, contents] of Object.entries(files)) {
    const path = join(pluginDir, name)

    writeFileSync(path, contents)
    utimesSync(path, STAMP, STAMP)
  }

  utimesSync(pluginDir, STAMP, STAMP)

  const archive = join(packages, `${slug}-${version}.zip`)

  rmSync(archive, { force: true })

  // Entries are fed in sorted, one per line, rather than left to `zip -r`.
  // Recursion walks the directory in readdir order, which is not guaranteed
  // stable across filesystems — so `-X` and the fixed mtimes would remove two
  // sources of variance and leave a third. With this, byte-identical archives
  // are enforced rather than hoped for.
  //
  // No directory entry is written, which WordPress's unzip_file() does not
  // need: it builds the directories it wants from the file paths.
  const entries = Object.keys(files)
    .sort()
    .map((name) => `${slug}/${name}`)

  run('zip', ['-qX', archive, '-@'], { cwd: root, input: `${entries.join('\n')}\n` })

  if (checksum !== 'none') {
    writeChecksum(archive, checksum === 'corrupt')
  }
}

/**
 * A sha256sum-shaped sidecar: the digest, two spaces, the file it describes.
 *
 * `corrupt` flips one nibble of the real digest. It stays a well-formed 64-hex
 * digest, so it gets past VerifiedPackageDownloader's parse and is refused by
 * the comparison — which is the branch worth proving, rather than the one that
 * rejects obvious rubbish.
 */
function writeChecksum(archive, corrupt = false) {
  const digest = createHash('sha256').update(readFileSync(archive)).digest('hex')
  const published = corrupt
    ? (digest[0] === '0' ? '1' : '0') + digest.slice(1)
    : digest

  writeFileSync(`${archive}.sha256`, `${published}  ${basename(archive)}\n`)
}

function writeJson(path, value) {
  writeFileSync(path, `${JSON.stringify(value, null, 2)}\n`)
}

function run(command, args, { cwd, input }) {
  try {
    execFileSync(command, args, { cwd, input, stdio: ['pipe', 'ignore', 'pipe'] })
  } catch (error) {
    fail(`${command} ${args.join(' ')} failed: ${error.stderr?.toString().trim() || error.message}`)
  }
}

function fail(message) {
  process.stderr.write(`prepare-fixtures: ${message}\n`)
  process.exit(1)
}

// ── Contents ─────────────────────────────────────────────────────────────────

/**
 * The catalogue, in exactly the shape CatalogueValidator accepts — one product,
 * because one product is all it takes to prove a lifecycle and six would only
 * add five ways for the assertions to drift.
 *
 * `docs_url` stays on fchub.co because the validator's docs host list has no
 * filter behind it. It is rendered as a link and never fetched; with
 * WP_HTTP_BLOCK_EXTERNAL on, the site could not fetch it if it tried.
 */
function catalogue(version, hubArchive) {
  return {
    schema_version: 1,
    hub: {
      // Deliberately the version the site already has. An update to FCHub
      // itself is a different test, and one that fired here would move the
      // ground under the removal step.
      version: '1.0.0',
      plugin_file: 'fchub/fchub.php',
      release_url: `${BASE}/releases/fchub-1.0.0`,
      package_url: `${BASE}/packages/${hubArchive}`,
      checksum_url: `${BASE}/packages/${hubArchive}.sha256`,
    },
    products: {
      'fchub-p24': {
        name: 'Przelewy24',
        description:
          'The lifecycle fixture standing in for the Przelewy24 gateway. It has a header, an ' +
          'option and no opinions whatsoever about payments.',
        status: 'stable',
        plugin_file: 'fchub-p24/fchub-p24.php',
        requires_wp: '6.4',
        requires_php: '8.1',
        dependencies: ['fluentcart'],
        admin_path: 'admin.php?page=fluent-cart',
        version,
        docs_url: 'https://fchub.co/docs/fchub-p24',
        release_url: `${BASE}/releases/fchub-p24-${version}`,
        package_url: `${BASE}/packages/fchub-p24-${version}.zip`,
        checksum_url: `${BASE}/packages/fchub-p24-${version}.zip.sha256`,
      },
    },
  }
}

/**
 * The product FCHub installs, updates, and then has to leave standing.
 *
 * The option written on activation is the canary: FCHub's uninstall.php claims
 * to delete four keys and nothing else, and after the removal step this one is
 * read back to see whether that was true.
 */
function p24Plugin(version) {
  return `<?php
/**
 * Plugin Name: Przelewy24
 * Description: Lifecycle fixture. Enough plugin to be installed, activated and updated; no more.
 * Version: ${version}
 * Author: Vibe Code
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: fchub-p24
 */

defined('ABSPATH') || exit;

define('FCHUB_P24_FIXTURE_VERSION', '${version}');

// Written once, on activation, and never again — including through an update,
// which is the point. If FCHub's uninstall takes this with it, FCHub is not the
// control plane it says it is.
register_activation_hook(__FILE__, static function (): void {
    add_option('fchub_p24_fixture_state', 'set by the lifecycle fixture on activation');
});
`
}

/**
 * The platform ProductStateResolver looks for: the constant it prefers, and a
 * settings screen so the catalogue's admin_path leads somewhere real rather
 * than to a permissions error in a screenshot.
 */
function fluentCartPlugin() {
  return `<?php
/**
 * Plugin Name: FluentCart
 * Description: Lifecycle fixture. Defines the one constant FCHub looks for, and hosts one empty settings screen.
 * Version: ${FLUENTCART}
 * Author: Vibe Code
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: fluent-cart
 */

defined('ABSPATH') || exit;

define('FLUENTCART_VERSION', '${FLUENTCART}');

add_action('admin_menu', static function (): void {
    add_menu_page(
        'FluentCart',
        'FluentCart',
        'manage_options',
        'fluent-cart',
        static function (): void {
            echo '<div class="wrap"><h1>FluentCart</h1><p>Lifecycle fixture settings screen.</p></div>';
        },
        'dashicons-cart',
        56
    );
});
`
}

/**
 * The only thing standing between production defaults and a plain-HTTP fixture
 * host, and it lives for exactly as long as the container does.
 *
 * Both FCHub filters are used exactly as shipped, and both are narrowed rather
 * than widened: the package allow-list becomes one host, and HTTP is permitted
 * for that host alone. Nothing here touches the constants CatalogueValidator
 * and VerifiedPackageDownloader ship with, so production stays HTTPS-only
 * against GitHub whatever this file says.
 *
 * The third filter is WordPress's own. wp_safe_remote_get() refuses to dial a
 * private address, and every container on a Compose network has one, so without
 * it the fixture host would be unreachable no matter what FCHub allowed.
 */
function muPlugin() {
  return `<?php
/**
 * Plugin Name: FCHub lifecycle harness
 * Description: Points one disposable site at the fixture release host. Generated per run, never shipped, never near production.
 * Version: 1.0.0
 * Author: Vibe Code
 */

defined('ABSPATH') || exit;

const FCHUB_LIFECYCLE_HOST = '${HOST}';

function fchub_lifecycle_is_fixture_host($url): bool
{
    $host = wp_parse_url((string) $url, PHP_URL_HOST);

    return is_string($host) && strtolower($host) === FCHUB_LIFECYCLE_HOST;
}

// One host, and it is not github.com. A run that somehow reached a real release
// host would fail here rather than quietly pass on somebody else's bytes.
add_filter(
    'fchub/catalogue/allowed_package_hosts',
    static fn (): array => [FCHUB_LIFECYCLE_HOST]
);

// Plain HTTP for the fixture host and nothing else. Every other URL still has
// to be HTTPS, exactly as it would on a real site.
add_filter(
    'fchub/catalogue/allow_http',
    static fn ($allow, $url): bool => fchub_lifecycle_is_fixture_host($url),
    10,
    2
);

// Scoped to the same host, so the rest of wp_safe_remote_get()'s private-address
// protection is left precisely where it was.
add_filter(
    'http_request_host_is_external',
    static fn ($external, $host) => strtolower((string) $host) === FCHUB_LIFECYCLE_HOST
        ? true
        : $external,
    10,
    2
);

// WordPress's login screen calls wp_attempt_focus(), which 200ms after load
// runs d.focus(); d.select() on the username box (wp-login.php, ~line 1594).
// Landing between Playwright's select-and-focus and the Input.insertText that
// follows it, that redirects the password straight into the selected username
// field — which is how one run submitted "pass" as the username with an empty
// password and failed before any of the nine steps ran.
//
// This is WordPress's own filter, added in 4.8 for exactly this sort of thing.
// Turning the autofocus off removes the race at its source rather than timing
// around it, and it changes nothing about how the form authenticates.
add_filter('enable_login_autofocus', '__return_false');
`
}
