import { spawnSync } from 'node:child_process'
import { copyFileSync } from 'node:fs'
import { join } from 'node:path'
import { expect, test } from '@playwright/test'

/**
 * FCHub against a real WordPress, from installation to removal.
 *
 * Everything else in this project runs against fakes — a smoke host serving
 * JSON, closures standing in for the upgrader, an interface with no server
 * behind it. Those prove the parts behave. None of them can prove that FCHub
 * installs a plugin WordPress agrees is installed, or that removing FCHub
 * leaves that plugin exactly where it was, which is the whole promise.
 *
 * This one does, on a disposable site tests/e2e/run-lifecycle.sh creates and
 * destroys. Do not run it directly: it needs the containers, the ephemeral port
 * and the generated fixtures that script sets up, and it says so plainly rather
 * than guessing.
 *
 * The nine steps, in the order they have to happen:
 *
 *   1. FCHub opens on a real wp-admin and renders the fixture catalogue.
 *   2. FluentCart goes on, and P24 1.0.0 is installed through the FCHub button.
 *   3. WordPress itself agrees the plugin is installed and active.
 *   4. The fixture catalogue moves to 1.1.0 and FCHub notices.
 *   5. FCHub updates the product, and the running code follows.
 *   6. FCHub is deactivated and deleted through the WordPress Plugins screen.
 *   7. FCHub's four keys are gone; the product and its data are not.
 *   8. FCHub is reinstalled and finds the product exactly as it left it.
 *   9. A release whose checksum does not match is refused, and nothing moves.
 */

const PROJECT = required('FCHUB_LIFECYCLE_PROJECT')
const COMPOSE_FILE = required('FCHUB_LIFECYCLE_COMPOSE_FILE')
const FIXTURE_DIR = required('FCHUB_LIFECYCLE_FIXTURE_DIR')
const HUB_ARCHIVE = required('FCHUB_LIFECYCLE_HUB_ARCHIVE')
const HUB_VERSION = required('FCHUB_LIFECYCLE_HUB_VERSION')
const BASE_URL = required('FCHUB_LIFECYCLE_BASE_URL')

const ADMIN = `${BASE_URL}/wp-admin/`

const HUB_PLUGIN_FILE = 'fchub/fchub.php'
const P24_PLUGIN_FILE = 'fchub-p24/fchub-p24.php'

/** Three options and one transient. The only keys uninstall.php may remove. */
const HUB_OPTIONS = [
  'fchub_catalogue_last_good',
  'fchub_catalogue_etag',
  'fchub_catalogue_last_refresh',
]
const HUB_TRANSIENT = 'fchub_catalogue_fresh'

/** The fixture product's own option, written on activation. FCHub must never see it. */
const PRODUCT_OPTION = 'fchub_p24_fixture_state'
const PRODUCT_OPTION_VALUE = 'set by the lifecycle fixture on activation'

/** The administrator run-lifecycle.sh creates with `wp core install`. */
const ADMIN_USER = 'admin'
const ADMIN_PASS = 'pass'

/** A download, a checksum, an upgrader and a re-read. Not a five-second affair. */
const OPERATION = 120_000

/** Uncaught page errors, collected across the whole run and asserted at the end. */
const pageErrors = []

/**
 * One browser context and one page for the whole file.
 *
 * The nine steps are one session — a login, an install, a removal and a
 * reinstall, in that order — so they share a page rather than each taking a
 * fresh one and logging in again. The context is created and closed explicitly:
 * browser.newPage() would open one implicitly and leave closing it to worker
 * teardown, which is a poor place to discover that something is still holding
 * it open.
 */
let context
let page

function required(name) {
  const value = process.env[name]

  if (!value) {
    throw new Error(
      `${name} is not set. This spec is driven by tests/e2e/run-lifecycle.sh, which owns the ` +
        'containers, the ephemeral port and the fixtures it needs.',
    )
  }

  return value
}

// ── The site, from outside the browser ───────────────────────────────────────

/**
 * Runs one Compose command and hands back all three of what it did, whether it
 * succeeded or not.
 *
 * Deliberately not execFileSync-and-catch. WP-CLI does not use the exit code
 * consistently — `wp transient get` reports a missing transient as a warning
 * on stderr and still exits zero — so a helper that only kept stderr from the
 * throwing path would silently drop the one thing worth asserting on.
 *
 * @returns {{status: number, stdout: string, stderr: string}}
 */
function composeTry(args) {
  const result = spawnSync(
    'docker',
    ['compose', '--progress', 'quiet', '-p', PROJECT, '-f', COMPOSE_FILE, ...args],
    { encoding: 'utf8' },
  )

  if (result.error) {
    throw result.error
  }

  return {
    status: result.status ?? 1,
    stdout: (result.stdout || '').trim(),
    stderr: (result.stderr || '').trim(),
  }
}

/** The same, for commands that have no business failing. */
function compose(args) {
  const result = composeTry(args)

  if (result.status !== 0) {
    throw new Error(`docker compose ${args.join(' ')} exited ${result.status}\n${result.stderr}`)
  }

  return result.stdout
}

/**
 * WP-CLI in the disposable site. Setup and assertions only — the install and
 * the update themselves go through the interface, like a customer's would,
 * because that is the thing under test.
 */
function wp(...args) {
  return compose(['run', '--rm', '--no-deps', '-T', 'wpcli', 'wp', ...args])
}

/** WP-CLI where the outcome, not the success, is the assertion. */
function wpTry(...args) {
  return composeTry(['run', '--rm', '--no-deps', '-T', 'wpcli', 'wp', ...args])
}

/** One PHP expression evaluated inside the site. Positive reads, nothing parsed. */
function wpEval(php) {
  return wp('eval', php)
}

/**
 * The web container, not the CLI one. The two run separate PHP builds that
 * agree on 8.1 and need not agree on the patch, so the version the System page
 * prints can only be checked against the container that printed it.
 */
function web(...args) {
  return compose(['exec', '-T', 'wordpress', ...args])
}

/**
 * Publishes one of the generated catalogues, and does not return until the
 * fixture host is actually serving it.
 *
 * The wait is the point. `www/` is a host bind mount, and writing the file then
 * immediately asking WordPress to refresh assumes the container sees the change
 * at once — an assumption about Docker's file sharing, not about anything this
 * harness controls. It held for every run on an idle machine (measured: 0 stale
 * reads in 25 swaps, with nginx `sendfile` on and off alike) and then did not
 * hold on a loaded one: a refresh reported "Catalogue refreshed." while serving
 * the previous version, and the step failed three assertions later looking like
 * a missing button.
 *
 * `copyFileSync` truncates before it writes, so this also closes the narrower
 * window where the file is briefly empty — a truncated body cannot name the
 * package either.
 *
 * Read back through nginx from inside the network rather than off the host, so
 * what is checked is what WordPress would receive.
 */
function serveCatalogue(version) {
  copyFileSync(
    join(FIXTURE_DIR, 'www', `catalogue-${version}.json`),
    join(FIXTURE_DIR, 'www', 'catalogue.json'),
  )

  // The package URL, not the version string: `"version"` also appears in the
  // hub block, which never moves.
  const wanted = `fchub-p24-${version}.zip`
  const deadline = Date.now() + 60_000
  let served = { status: 1, stdout: '', stderr: 'never read' }

  while (Date.now() < deadline) {
    served = composeTry([
      'exec', '-T', 'catalogue',
      'wget', '-q', '-O', '-', 'http://127.0.0.1/catalogue.json',
    ])

    if (served.status === 0 && served.stdout.includes(wanted)) {
      return
    }
  }

  throw new Error(
    `The fixture host never served catalogue ${version}. Last read exited ` +
      `${served.status} with ${served.stdout.length} bytes: ` +
      `${served.stdout.slice(0, 200) || served.stderr}`,
  )
}

// ── The browser ──────────────────────────────────────────────────────────────

/**
 * Opens one FCHub hash route on a freshly loaded document.
 *
 * The blank page first is deliberate. Playwright treats a goto that differs
 * only by fragment as a same-document navigation, and the store loads once on
 * mount — so without it, a route change made after a WP-CLI command would
 * render what the store read before that command ran, and every assertion after
 * it would be about the past.
 */
async function openHub(route = '/') {
  const hash = route === '/' ? '' : `#${route}`

  await page.goto('about:blank')
  await page.goto(`${ADMIN}admin.php?page=fchub${hash}`)

  const main = page.locator('#fchub-app main.fchub-main')

  await expect(main).toBeVisible()
  await expect(main).not.toContainText('Reading the catalogue')

  return main
}

/** The one product card the fixture catalogue describes. */
function p24Card() {
  return page.getByRole('article').filter({ hasText: 'Przelewy24' })
}

/** The polite live region — where every successful operation announces itself. */
function notice() {
  return page.locator('[role="status"][aria-live="polite"]')
}

/** The assertive one. Empty is the only acceptable state in this run. */
function alert() {
  return page.locator('[role="alert"][aria-live="assertive"]')
}

function panel(heading) {
  return page.locator(`section[aria-labelledby="fchub-${heading}-heading"]`)
}

test.describe.configure({ mode: 'serial' })

/**
 * A real logged-in administrator session, through the real login form.
 *
 * The two value assertions are not ceremony. wp-login.php runs
 * wp_attempt_focus() 200ms after load — `d.focus(); d.select()` on the username
 * box — and it can land between Playwright's select-and-focus and the
 * Input.insertText that follows, sending the password into the selected
 * username field instead. One run submitted `pass` as the username with an
 * empty password and died here, before any of the nine steps.
 *
 * The MU-plugin now switches that autofocus off through WordPress's own
 * `enable_login_autofocus` filter, so the race is gone at its source. These
 * assertions stay because anything else that ever moves focus on this page
 * should fail as "the login form did not keep the username" rather than as a
 * wrong password three steps later, wearing FCHub's name.
 */
async function logIn() {
  const username = page.locator('#user_login')
  const password = page.locator('#user_pass')

  await page.goto(`${BASE_URL}/wp-login.php`)

  await username.fill(ADMIN_USER)
  await password.fill(ADMIN_PASS)

  await expect(username, 'the login form should have kept the username').toHaveValue(ADMIN_USER)
  await expect(password, 'the login form should have kept the password').toHaveValue(ADMIN_PASS)

  await page.locator('#wp-submit').click()

  // The admin bar is the proof: it renders only for an authenticated user, and
  // wp-login.php re-renders itself without one on a rejected password.
  await expect(page.locator('#wpadminbar'), 'the admin bar after logging in').toBeVisible()
  await expect(page.locator('#loginform'), 'the login form should be behind us').toHaveCount(0)
}

test.beforeAll(async ({ browser }) => {
  context = await browser.newContext()
  page = await context.newPage()

  page.on('pageerror', (error) => pageErrors.push(`${page.url()} — ${error.message}`))

  await logIn()
})

test.afterAll(async () => {
  await context?.close()
})

// ── 1. FCHub, on a real wp-admin ─────────────────────────────────────────────

test('opens on a real wp-admin and renders the fixture catalogue', async () => {
  await openHub()

  await expect(page.getByRole('heading', { name: 'FCHub', exact: true })).toBeVisible()

  // FluentCart is installed but switched off, so the one product in the
  // catalogue cannot run here — and the Overview says exactly that rather than
  // quietly rendering a button that would be refused.
  await expect(page.getByRole('heading', { name: 'One thing could use a look.' })).toBeVisible()
  await expect(page.getByText('On this site, one product is not ready to run here.')).toBeVisible()

  await openHub('/system')

  // `remote` is the only source that means the whole HTTP path worked: the
  // MU-plugin's one-host allow-list, plain HTTP, and the production validator
  // accepting what nginx served. The bundled copy would say something else.
  await expect(panel('catalogue').getByText('Straight from fchub.co')).toBeVisible()
  await expect(panel('hub').getByText(HUB_VERSION, { exact: true })).toBeVisible()

  // The site as the site reports it, read from the container that renders the
  // page rather than from a fixture that claims to speak for it.
  const site = panel('site')

  await expect(site.getByText(web('php', '-r', 'echo PHP_VERSION;'), { exact: true })).toBeVisible()
  await expect(site.getByText(wpEval('echo get_bloginfo("version");'), { exact: true })).toBeVisible()
  await expect(site.getByText('Not running on this site')).toBeVisible()

  await openHub('/products')

  const card = p24Card()

  await expect(card.getByText('Not installed', { exact: true })).toBeVisible()
  await expect(card.getByText('Cannot run here', { exact: true })).toBeVisible()

  const blocked = card.getByRole('button', { name: 'Install and activate' })

  await expect(blocked).toHaveAttribute('aria-disabled', 'true')

  // The explanation is wired to the button rather than merely printed near it.
  const describedBy = await blocked.getAttribute('aria-describedby')

  expect(describedBy).toBeTruthy()
  await expect(page.locator(`[id="${describedBy}"]`)).toHaveText(
    'Przelewy24 needs FluentCart installed and active first.',
  )
})

// ── 2. FluentCart on, then P24 in, through the button ────────────────────────

test('installs and activates the product through the FCHub button', async () => {
  // Setup, not the subject. FluentCart is not an FCHub product and the
  // interface has no business offering to install it.
  wp('plugin', 'activate', 'fluent-cart')

  await openHub('/system')

  // FCHub has noticed, and names the version it found. That is the site
  // speaking, not the catalogue.
  await expect(panel('site').getByText('Version 1.2.0', { exact: true })).toBeVisible()
  await expect(panel('compatibility').getByText('Nothing is waiting on FluentCart.')).toBeVisible()

  await openHub()

  await expect(
    page.getByRole('heading', { name: 'Everything is ticking along nicely.' }),
  ).toBeVisible()

  const card = p24Card()
  const install = card.getByRole('button', { name: 'Install and activate' })

  // Checked before the click, so a click that silently did nothing could not be
  // mistaken for a click that was properly refused.
  await expect(install).not.toHaveAttribute('aria-disabled', 'true')
  await expect(card.getByText('Latest release 1.0.0', { exact: true })).toBeVisible()

  await install.click()

  // Fetched from the fixture host, checked against its SHA-256 sidecar, handed
  // to WordPress's own upgrader, and switched on.
  await expect(notice()).toContainText('Przelewy24 is installed and switched on.', {
    timeout: OPERATION,
  })
  await expect(alert()).toBeEmpty()

  const installed = p24Card()

  await expect(installed.getByText('Active', { exact: true })).toBeVisible()
  await expect(installed.getByText('Version 1.0.0', { exact: true })).toBeVisible()

  // Its primary action is now the product's own settings screen, at the address
  // the catalogue's admin_path describes.
  await expect(installed.getByRole('link', { name: 'Open settings' })).toHaveAttribute(
    'href',
    `${ADMIN}admin.php?page=fluent-cart`,
  )

  await expect(
    page.locator('section[aria-labelledby="fchub-installed-heading"]').getByRole('article'),
  ).toHaveCount(1)
})

// ── 3. WordPress agrees ──────────────────────────────────────────────────────

test('is a plugin WordPress itself reports as installed and active', async () => {
  expect(wp('plugin', 'get', 'fchub-p24', '--field=version')).toBe('1.0.0')
  expect(wp('plugin', 'get', 'fchub-p24', '--field=status')).toBe('active')

  // Not merely a directory on disk: it is in WordPress's own active list.
  expect(
    wpEval(`echo in_array("${P24_PLUGIN_FILE}", (array) get_option("active_plugins"), true) ? "yes" : "no";`),
  ).toBe('yes')

  // And it ran, which is the difference between installed and working.
  expect(wpEval(`echo get_option("${PRODUCT_OPTION}", "MISSING");`)).toBe(PRODUCT_OPTION_VALUE)

  // The Plugins screen, because that is where a customer would look.
  await page.goto(`${ADMIN}plugins.php`)

  const row = page.locator(`tr[data-plugin="${P24_PLUGIN_FILE}"]`)

  await expect(row).toHaveCount(1)
  await expect(row).toHaveClass(/\bactive\b/)
})

// ── 4. The catalogue moves ───────────────────────────────────────────────────

test('sees a newer release once the catalogue offers one', async () => {
  serveCatalogue('1.1.0')

  // Nothing changes on screen yet, and this is the assertion that says so: the
  // catalogue was read successfully less than six hours ago, so FCHub is inside
  // its freshness window and has not asked again. Without this, the refresh
  // below could be doing nothing and the test would never notice.
  await openHub()

  await expect(
    page.getByRole('heading', { name: 'Everything is ticking along nicely.' }),
  ).toBeVisible()
  await expect(p24Card().getByRole('button', { name: /^Update to/ })).toHaveCount(0)

  await openHub('/system')

  // Pressing the button is what "try again" is for.
  await page.getByRole('button', { name: 'Check for updates' }).click()

  const banner = notice().locator('.fchub-banner')

  await expect(banner).toContainText('Catalogue refreshed.', { timeout: OPERATION })

  // Green, because it reached the endpoint. Amber would mean it fell back to a
  // stored copy and the switch never took.
  await expect(banner).not.toHaveClass(/fchub-banner--warning/)

  await openHub()

  await expect(page.getByRole('heading', { name: 'One thing could use a look.' })).toBeVisible()
  await expect(page.getByText('On this site, one update is waiting.')).toBeVisible()

  const card = p24Card()

  await expect(card.getByText(/^1\.0\.0 installed .+ 1\.1\.0 ready$/)).toBeVisible()
  await expect(card.getByText('Update ready', { exact: true })).toBeVisible()
  await expect(card.getByRole('button', { name: 'Update to 1.1.0' })).toBeVisible()

  // Offering an update is not applying one. The site is still on 1.0.0.
  expect(wp('plugin', 'get', 'fchub-p24', '--field=version')).toBe('1.0.0')
})

// ── 5. The update, through the interface ─────────────────────────────────────

test('updates the product, and the running code follows', async () => {
  const update = p24Card().getByRole('button', { name: 'Update to 1.1.0' })

  // Same guard as the install click. Playwright would refuse to click an
  // aria-disabled control anyway, but asserting it first turns "the button was
  // inert" into a named failure rather than a timeout.
  await expect(update).not.toHaveAttribute('aria-disabled', 'true')
  await update.click()

  await expect(notice()).toContainText('Przelewy24 is now on 1.1.0.', { timeout: OPERATION })
  await expect(alert()).toBeEmpty()

  const card = p24Card()

  await expect(card.getByText('Version 1.1.0', { exact: true })).toBeVisible()
  await expect(card.getByRole('button', { name: /^Update to/ })).toHaveCount(0)
  await expect(
    page.getByRole('heading', { name: 'Everything is ticking along nicely.' }),
  ).toBeVisible()

  // The site agrees, and the product is still switched on. An update is not a
  // reinstall, and one that came back deactivated would be a bug wearing a
  // green tick.
  expect(wp('plugin', 'get', 'fchub-p24', '--field=version')).toBe('1.1.0')
  expect(wp('plugin', 'get', 'fchub-p24', '--field=status')).toBe('active')

  // A constant the fixture defines at load time, read out of a live request:
  // the new files are the ones running, not merely the ones on disk.
  expect(
    wpEval('echo defined("FCHUB_P24_FIXTURE_VERSION") ? FCHUB_P24_FIXTURE_VERSION : "undefined";'),
  ).toBe('1.1.0')

  // And what the product wrote on activation survived being overwritten.
  expect(wpEval(`echo get_option("${PRODUCT_OPTION}", "MISSING");`)).toBe(PRODUCT_OPTION_VALUE)
})

// ── 6. Removing FCHub, through WordPress ─────────────────────────────────────

test('is deactivated and deleted through the WordPress Plugins screen', async () => {
  // Read first, so "gone afterwards" is measuring a change rather than
  // describing something that was never there.
  for (const option of HUB_OPTIONS) {
    expect(wpTry('option', 'get', option).status, `${option} should exist before uninstall`).toBe(0)
  }

  // `wp transient get` reports a missing transient as a *warning* and still
  // exits zero, so its value is the only honest evidence that it is there. An
  // exit-code check would pass whether or not the transient existed, which is
  // the definition of a test that proves nothing.
  const transient = wpTry('transient', 'get', HUB_TRANSIENT)

  expect(transient.status).toBe(0)
  expect(transient.stdout, 'the transient should have a value before uninstall').not.toBe('')

  await page.goto(`${ADMIN}plugins.php`)

  // The top-level menu item by its own id, not by an href substring: FCHub adds
  // three submenu entries and a plugin-row action link, all of which carry
  // page=fchub, so a substring count would be measuring the wrong thing.
  const menuItem = page.locator('#adminmenu #toplevel_page_fchub')

  await expect(menuItem).toHaveCount(1)

  const row = page.locator(`tr[data-plugin="${HUB_PLUGIN_FILE}"]`)

  await expect(row).toHaveClass(/\bactive\b/)
  await row.getByRole('link', { name: /^Deactivate/ }).click()

  await expect(page.locator(`tr[data-plugin="${HUB_PLUGIN_FILE}"]`)).toHaveClass(/\binactive\b/)

  // WordPress deletes from this screen over AJAX, behind a window.confirm.
  // Accepting it is the customer clicking OK, and is the only route by which
  // uninstall.php ever runs.
  const deleted = page.waitForResponse(
    (response) =>
      response.url().includes('admin-ajax.php') &&
      (response.request().postData() || '').includes('action=delete-plugin'),
    { timeout: OPERATION },
  )

  page.once('dialog', (dialog) => dialog.accept())

  await page
    .locator(`tr[data-plugin="${HUB_PLUGIN_FILE}"]`)
    .getByRole('link', { name: /^Delete/ })
    .click()

  // WordPress's own answer, not whatever the page's JavaScript did to the row.
  expect((await (await deleted).json()).success, 'WordPress should report the delete succeeded').toBe(
    true,
  )

  await page.reload()

  await expect(page.locator(`tr[data-plugin="${HUB_PLUGIN_FILE}"]`)).toHaveCount(0)

  // The whole promise, on the one screen where a customer would see it: the
  // product is still there, and still switched on.
  const product = page.locator(`tr[data-plugin="${P24_PLUGIN_FILE}"]`)

  await expect(product).toHaveCount(1)
  await expect(product).toHaveClass(/\bactive\b/)

  // FCHub took its menu entry with it rather than leaving a dead one behind.
  await expect(page.locator('#adminmenu')).toBeAttached()
  await expect(page.locator('#adminmenu #toplevel_page_fchub')).toHaveCount(0)
})

// ── 7. What FCHub owned, and what it did not ─────────────────────────────────

test('deletes its own four keys and nothing else', async () => {
  // The four commands the task specifies, run exactly as written.
  const lastGood = wpTry('option', 'get', 'fchub_catalogue_last_good')
  const fresh = wpTry('transient', 'get', HUB_TRANSIENT)

  expect(lastGood.status, 'fchub_catalogue_last_good should be gone').not.toBe(0)
  expect(lastGood.stderr).toMatch(/Could not get/i)

  // The transient's absence is read off the value and the message, not the exit
  // code: WP-CLI treats a missing transient as a warning and exits zero either
  // way. Asserting a non-zero status here would be asserting a bug in WP-CLI.
  expect(fresh.stdout, 'a deleted transient has no value to print').toBe('')
  expect(fresh.stderr, 'fchub_catalogue_fresh should be gone').toMatch(/is not set/i)

  expect(wpTry('plugin', 'is-active', 'fchub-p24').status, 'fchub-p24 should still be active').toBe(0)
  expect(wp('plugin', 'get', 'fchub-p24', '--field=version')).toBe('1.1.0')

  // The same reads again as values rather than exit codes, plus the two rows a
  // transient actually occupies. A command that failed for some unrelated
  // reason would satisfy the assertions above and not these.
  const gone = [
    ...HUB_OPTIONS,
    `_transient_${HUB_TRANSIENT}`,
    `_transient_timeout_${HUB_TRANSIENT}`,
  ]

  for (const option of gone) {
    expect(wpEval(`echo get_option("${option}", "MISSING");`), `${option} after uninstall`).toBe(
      'MISSING',
    )
  }

  // Everything FCHub does not own, exactly where it was.
  expect(wpEval(`echo get_option("${PRODUCT_OPTION}", "MISSING");`)).toBe(PRODUCT_OPTION_VALUE)
  expect(
    wpEval('echo defined("FCHUB_P24_FIXTURE_VERSION") ? FCHUB_P24_FIXTURE_VERSION : "undefined";'),
  ).toBe('1.1.0')
  expect(wpEval('echo defined("FLUENTCART_VERSION") ? FLUENTCART_VERSION : "undefined";')).toBe(
    '1.2.0',
  )

  // And nothing of FCHub's is left on disk.
  expect(
    wpEval(
      'require_once ABSPATH . "wp-admin/includes/plugin.php"; ' +
        `echo array_key_exists("${HUB_PLUGIN_FILE}", get_plugins()) ? "present" : "gone";`,
    ),
  ).toBe('gone')
})

// ── 8. Putting it back ───────────────────────────────────────────────────────

test('is reinstalled, and finds the product exactly as it left it', async () => {
  wp('plugin', 'install', `/fchub-fixtures/www/packages/${HUB_ARCHIVE}`, '--activate')

  await openHub('/system')

  // Its cached catalogue went with it, so this is a fresh read over HTTP — and
  // the last proof that the four keys were genuinely deleted rather than merely
  // unreadable by WP-CLI.
  await expect(panel('catalogue').getByText('Straight from fchub.co')).toBeVisible()
  await expect(panel('hub').getByText(HUB_VERSION, { exact: true })).toBeVisible()

  await openHub()

  await expect(
    page.getByRole('heading', { name: 'Everything is ticking along nicely.' }),
  ).toBeVisible()

  const card = p24Card()

  await expect(card.getByText('Active', { exact: true })).toBeVisible()
  await expect(card.getByText('Version 1.1.0', { exact: true })).toBeVisible()
  await expect(card.getByRole('button', { name: /^Update to/ })).toHaveCount(0)

  await expect(
    page.locator('.fchub-summary', { hasText: 'Active products' }).getByText('1', { exact: true }),
  ).toBeVisible()
})

// ── 9. The checksum, proved by rejection ─────────────────────────────────────

test('refuses a release whose checksum does not match, and leaves the product alone', async () => {
  // Everything up to here proves verification does not *false-reject*: real
  // sidecars, real archives, two clean installs. None of it can tell a working
  // checksum step from one that was deleted — remove the comparison and all of
  // it still passes. This step is the one that can.
  //
  // 1.2.0 is a perfectly good archive on the trusted host with a sidecar
  // describing a different one. Every other guard on the path is satisfied, so
  // the only thing standing between FCHub and installing it is the digest.
  serveCatalogue('1.2.0')

  await openHub('/system')
  await page.getByRole('button', { name: 'Check for updates' }).click()

  await expect(notice().locator('.fchub-banner')).toContainText('Catalogue refreshed.', {
    timeout: OPERATION,
  })

  await openHub()

  const update = p24Card().getByRole('button', { name: 'Update to 1.2.0' })

  await expect(update).not.toHaveAttribute('aria-disabled', 'true')
  await update.click()

  // The assertive region, because this one did go wrong — and the sentence
  // OperationError gives `package_verification_failed`, so a different refusal
  // (an unreachable host, an unparseable sidecar) would not satisfy it.
  await expect(alert()).toContainText(
    'The package did not pass its safety check, so nothing was changed.',
    { timeout: OPERATION },
  )

  // "so nothing was changed" has to be true. The card still describes the old
  // state, and the site still runs the old code.
  const card = p24Card()

  await expect(card.getByText(/^1\.1\.0 installed .+ 1\.2\.0 ready$/)).toBeVisible()

  expect(wp('plugin', 'get', 'fchub-p24', '--field=version')).toBe('1.1.0')
  expect(wp('plugin', 'get', 'fchub-p24', '--field=status')).toBe('active')
  expect(
    wpEval('echo defined("FCHUB_P24_FIXTURE_VERSION") ? FCHUB_P24_FIXTURE_VERSION : "undefined";'),
  ).toBe('1.1.0')
  expect(wpEval(`echo get_option("${PRODUCT_OPTION}", "MISSING");`)).toBe(PRODUCT_OPTION_VALUE)

  // Every uncaught browser error across all nine steps. A refused install is an
  // expected 502, handled by the store; it is not something that throws.
  expect(pageErrors, 'nothing should have thrown in the browser').toEqual([])
})
