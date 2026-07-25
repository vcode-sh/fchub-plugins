import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'

/**
 * The FCHub admin interface, in a browser, against the production bundle.
 *
 * Six fixture families, one per state the backend can actually produce: calm,
 * an update waiting, a product reporting its own problem while everything else
 * is fine, a site that cannot run half the suite, a catalogue that could not be
 * reached, and a mutation that failed. The awkward four are the point — anybody
 * can screenshot a happy path.
 *
 * Every response comes from tests/e2e/fixtures. Nothing here touches the
 * network, a WordPress install, or Docker.
 */

const ROOT = fileURLToPath(new URL('../../', import.meta.url))
const FIXTURE_DIR = fileURLToPath(new URL('./fixtures/', import.meta.url))

const FAMILIES = [
  'healthy',
  'update',
  'attention',
  'incompatible',
  'offline',
  'failed-operation',
]

function fixture(name) {
  return JSON.parse(readFileSync(`${FIXTURE_DIR}${name}.json`, 'utf8'))
}

/**
 * The approved palette, as Chromium computes it. Every colour any element
 * inside #fchub-app is allowed to paint at rest.
 *
 * Both blues are here, which is the whole reason the sweep is not enough on its
 * own: `paints only FCHub colours` proves nothing about *which* blue went
 * where, so the two-blues guard below checks that separately.
 */
const PALETTE = new Map([
  ['rgba(0, 0, 0, 0)', 'transparent'],
  ['rgb(255, 255, 255)', '--fchub-card-bg / white on the primary button'],
  ['rgb(243, 245, 250)', '--fchub-page-bg, --fchub-neutral-bg'],
  ['rgb(77, 110, 245)', '--fchub-primary'],
  ['rgb(58, 91, 224)', '--fchub-primary-strong'],
  ['rgb(21, 29, 38)', '--fchub-text-primary'],
  ['rgb(86, 88, 101)', '--fchub-text-secondary'],
  ['rgb(234, 236, 240)', '--fchub-border-color'],
  ['rgb(18, 35, 104)', '--fchub-stat-blue'],
  ['rgb(235, 241, 255)', '--fchub-stat-blue-bg'],
  ['rgb(113, 51, 10)', '--fchub-stat-orange'],
  ['rgb(255, 243, 235)', '--fchub-stat-orange-bg'],
  ['rgb(104, 18, 61)', '--fchub-stat-pink'],
  ['rgb(255, 235, 244)', '--fchub-stat-pink-bg'],
  ['rgb(53, 26, 117)', '--fchub-stat-purple'],
  ['rgb(239, 235, 255)', '--fchub-stat-purple-bg'],
  ['rgb(30, 91, 52)', '--fchub-positive'],
  ['rgb(234, 247, 239)', '--fchub-positive-bg'],
  ['rgba(28, 39, 50, 0.06)', '--fchub-shadow-card'],
])

/**
 * Things that must never reach a customer's screen. Server internals, error
 * codes meant for branching, and the two classic renderings of a value nobody
 * checked.
 */
const LEAKS = [
  ['the word "undefined"', /\bundefined\b/],
  ['the word "null"', /\bnull\b/],
  ['an object rendered as a string', /\[object Object\]/],
  ['raw JSON', /[{[]\s*"/],
  ['a machine-readable error code', /\b(?:rest|smoke)_[a-z_]+\b/],
  ['a filesystem path', /(?:\/(?:var|home|Users|srv)\/|\.php\b)/],
  ['a stack trace', /\bStack trace\b|\bFatal error\b|^#\d+ /m],
]

/** console/pageerror output per page, asserted empty after every test. */
const noise = new Map()

test.beforeEach(({ page }) => {
  const found = []

  noise.set(page, found)

  page.on('console', (message) => {
    if (message.type() === 'error' || message.type() === 'warning') {
      found.push(`console.${message.type()}: ${message.text()}`)
    }
  })

  page.on('pageerror', (error) => found.push(`pageerror: ${error.message}`))
  page.on('requestfailed', (request) =>
    found.push(`requestfailed: ${request.url()} — ${request.failure()?.errorText}`),
  )
})

test.afterEach(async ({ page }) => {
  // A fixture that does not describe an operation answers with a visible
  // failure rather than a cheerful envelope, so an entry here means a test
  // exercised something nobody wrote down.
  const unscripted = await page
    .evaluate(() => window.__fchubSmoke?.unscripted ?? [])
    .catch(() => [])

  expect(unscripted, 'the fixture should describe every request the test made').toEqual([])

  expect(noise.get(page) ?? [], 'the browser should have nothing to complain about').toEqual([])

  noise.delete(page)
})

/**
 * Loads the built interface with one fixture behind it, at one hash route, and
 * waits until it has something to say.
 */
async function open(page, name, route = '/') {
  const hash = route === '/' ? '' : `#${route}`

  await page.goto(`/smoke/index.html?fixture=${name}${hash}`)

  const main = page.locator('#fchub-app main.fchub-main')

  await expect(main).toBeVisible()
  await expect(main).not.toContainText('Reading the catalogue')

  // Inter ships in the bundle; screenshotting before it swaps in captures the
  // system fallback and a diff that means nothing.
  await page.evaluate(() => document.fonts.ready)

  return main
}

/** The rendered text of the whole interface, for the leak checks. */
async function screenText(page) {
  return page.locator('#fchub-app').innerText()
}

async function expectNoLeaks(page, where) {
  const text = await screenText(page)

  for (const [label, pattern] of LEAKS) {
    expect(text.match(pattern)?.[0] ?? null, `${where} must not show ${label}`).toBeNull()
  }
}

/** Computed styles for one locator, as Chromium resolves them. */
function styles(locator, properties) {
  return locator.evaluate(
    (element, names) =>
      Object.fromEntries(names.map((name) => [name, getComputedStyle(element)[name]])),
    properties,
  )
}

/**
 * Tabs until the locator has focus, and says how many presses it took.
 *
 * Deliberately not locator.focus(): Chromium only matches :focus-visible when
 * the keyboard put focus there, so a programmatically focused control has no
 * ring to assert and the test would be checking nothing.
 */
async function tabTo(page, locator, limit = 60) {
  for (let press = 1; press <= limit; press += 1) {
    await page.keyboard.press('Tab')

    if (await locator.evaluate((element) => element === document.activeElement)) {
      return press
    }
  }

  throw new Error(`Tab never reached ${locator} in ${limit} presses.`)
}

// ── The fixtures themselves ──────────────────────────────────────────────────

/**
 * ProductStateResolver::actions(), in JavaScript.
 *
 * Two steps, kept apart the way the resolver keeps them apart: what this
 * product's state asks for, and then what the account is allowed to run.
 * `deactivate` has no capability of its own — it shares `activate_plugins`, so
 * the envelope derives it from `activate`.
 *
 * A HAND-WRITTEN MIRROR, with nothing holding the two halves together. Change
 * the rule in ProductStateResolver::actions() and ProductStateResolverTest.php
 * goes red while this stays green — at which point the fixtures describe a
 * state the server can no longer produce and this suite cheerfully confirms
 * they are consistent with each other. If you touch the PHP, touch this too.
 */
function permittedActions(product, capabilities) {
  const { lifecycle, update } = product
  const safe = product.compatibility === 'compatible'
  const NEEDS = {
    install: ['install'],
    'install-and-activate': ['install', 'activate'],
    activate: ['activate'],
    update: ['update'],
    deactivate: ['activate'],
  }

  const candidates = []

  if (lifecycle === 'not_installed' && safe) {
    candidates.push('install', 'install-and-activate')
  }

  if (lifecycle === 'inactive' && safe) {
    candidates.push('activate')
  }

  if (lifecycle !== 'not_installed' && update === 'available' && safe) {
    candidates.push('update')
  }

  // Always offered to anyone who could have switched it on, compatible or not.
  // Trapping an administrator with a plugin they cannot disable would be a bold
  // interpretation of calm.
  if (lifecycle === 'active') {
    candidates.push('deactivate')
  }

  return candidates.filter((action) => NEEDS[action].every((needed) => capabilities[needed]))
}

test.describe('the fixtures', () => {
  const PRODUCT_KEYS = [
    'slug',
    'name',
    'description',
    'version',
    'requires_wp',
    'requires_php',
    'dependencies',
    'docs_url',
    'release_url',
    'lifecycle',
    'update',
    'compatibility',
    'compatibility_reason',
    'health',
    'health_message',
    'installed_version',
    'admin_url',
    'actions',
  ]

  /**
   * One envelope, held to everything ProductController::payload() guarantees.
   *
   * Applied to the opening snapshot, to every operation that answers with a
   * full envelope, and to the `state` a failed-but-changed operation carries —
   * because a fixture whose failure payload describes an impossible site is
   * exactly as misleading as a snapshot that does.
   */
  function expectEnvelope(payload, where) {
    expect(Object.keys(payload).slice(0, 5), `${where} key order`).toEqual([
      'products',
      'summary',
      'catalogue',
      'site',
      'capabilities',
    ])

    expect(Object.keys(payload.site)).toEqual(['wp', 'php', 'fluentcart'])
    expect(Object.keys(payload.capabilities)).toEqual(['install', 'activate', 'update'])
    expect(['remote', 'last_good', 'bundled']).toContain(payload.catalogue.source)

    for (const product of payload.products) {
      expect(Object.keys(product), `${where} ${product.slug} key order`).toEqual(PRODUCT_KEYS)
      expect(['not_installed', 'inactive', 'active']).toContain(product.lifecycle)
      expect(['current', 'available', 'unknown']).toContain(product.update)
      expect(['compatible', 'blocked', 'unknown']).toContain(product.compatibility)
      expect(['healthy', 'attention', 'unknown']).toContain(product.health)

      // The resolver cannot report a version for something that is not there,
      // and cannot fail to report one for something that is.
      expect(product.installed_version === null).toBe(product.lifecycle === 'not_installed')

      // Only an active product has a settings screen to link to.
      expect(product.admin_url === null).toBe(product.lifecycle !== 'active')

      // `actions`, re-derived exactly the way ProductStateResolver::actions()
      // derives it: what the product's state asks for, filtered by what this
      // account may actually run. Without this the offline fixture is just a
      // list of empty arrays somebody typed — and it is the one fixture whose
      // whole point is a capability being withheld.
      expect(product.actions, `${where} ${product.slug} actions`).toEqual(
        permittedActions(product, payload.capabilities),
      )

      if (product.compatibility === 'compatible') {
        expect(product.compatibility_reason).toBeNull()
      } else {
        expect(Object.keys(product.compatibility_reason)).toEqual([
          'requirement',
          'required',
          'current',
        ])
      }
    }

    // ProductController::envelope() derives all three. `compatibility_issues`
    // counts `unknown` as well as `blocked`, because both withhold buttons.
    expect(payload.summary, `${where} summary`).toEqual({
      active: payload.products.filter((p) => p.lifecycle === 'active').length,
      updates: payload.products.filter((p) => p.update === 'available').length,
      compatibility_issues: payload.products.filter((p) => p.compatibility !== 'compatible').length,
    })
  }

  for (const name of FAMILIES) {
    test(`${name} describes a state the server could actually produce`, () => {
      const { snapshot, operations } = fixture(name)

      expectEnvelope(snapshot, `${name} snapshot`)

      for (const [path, response] of Object.entries(operations)) {
        if (Array.isArray(response.body.products)) {
          expectEnvelope(response.body, `${name} → ${path}`)
        }

        if (response.body.state) {
          expectEnvelope(response.body.state, `${name} → ${path} state`)
        }
      }
    })
  }

  test('every scripted response is one of the two shapes on this wire', () => {
    for (const name of FAMILIES) {
      for (const [path, response] of Object.entries(fixture(name).operations)) {
        const body = response.body
        const where = `${name} → ${path}`

        if (Array.isArray(body.products)) {
          expect(typeof body.notice, `${where} is an envelope, so it carries a notice`).toBe(
            'string',
          )

          continue
        }

        // Otherwise it is a failure: FCHub's own four-key shape, or WordPress
        // core's {code, message, data:{status}}. Both carry code and message,
        // which is the only part the interface reads.
        expect(typeof body.code, `${where} code`).toBe('string')
        expect(typeof body.message, `${where} message`).toBe('string')

        if (!('state' in body)) {
          continue
        }

        // The fifth key, and only on a failure thrown after the site already
        // changed. It is the success envelope minus its notice — five keys,
        // same order — so it can go through the store's ordinary apply path
        // without anything having to special-case it.
        expect(body.success, `${where} carries state, so the operation did not finish`).toBe(false)
        expect(Object.keys(body.state), `${where} state keys`).toEqual([
          'products',
          'summary',
          'catalogue',
          'site',
          'capabilities',
        ])
      }
    }
  })
})

// ── Healthy ──────────────────────────────────────────────────────────────────

test.describe('a calm site', () => {
  test('says so, and counts what is running', async ({ page }) => {
    await open(page, 'healthy')

    await expect(
      page.getByRole('heading', { name: 'Everything is ticking along nicely.' }),
    ).toBeVisible()

    // Scoped to the stat it is about. Bare, the 5 asserts only that some
    // element on the page reads "5" — which would still pass if the count
    // landed under "Compatibility issues" instead.
    const activeProducts = page.locator('.fchub-summary', { hasText: 'Active products' })

    await expect(activeProducts.getByText('5', { exact: true })).toBeVisible()
    await expect(page.getByText('of 6 in the suite')).toBeVisible()
    await expect(page.getByText('Everything installed is on its latest release.')).toBeVisible()

    // Nothing to worry about means no panel of worries.
    await expect(page.getByRole('heading', { name: 'Worth a look' })).toHaveCount(0)

    await expect(
      page.locator('section[aria-labelledby="fchub-installed-heading"]').getByRole('article'),
    ).toHaveCount(5)
    await expect(page.getByRole('heading', { name: 'The rest of the suite' })).toBeVisible()

    await expectNoLeaks(page, 'the calm Overview')
    await expect(page.locator('#fchub-app')).toHaveScreenshot('overview-healthy-desktop.png')
  })

  test('lists every product on the Products screen, with honest filter counts', async ({
    page,
  }) => {
    await open(page, 'healthy', '/products')

    await expect(page.getByRole('button', { name: 'All 6' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Installed 5' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Updates 0' })).toBeVisible()
    await expect(page.getByRole('article')).toHaveCount(6)

    await page.getByRole('button', { name: 'Updates 0' }).click()

    await expect(
      page.getByText('No updates waiting. Everything installed is on its latest release.'),
    ).toBeVisible()
    await expect(page.getByRole('article')).toHaveCount(0)

    await expectNoLeaks(page, 'the Products screen')

    await page.getByRole('button', { name: 'All 6' }).click()

    await expect(page.locator('#fchub-app')).toHaveScreenshot('products-healthy-desktop.png')
  })

  test('says what the site runs and where the catalogue came from', async ({ page }) => {
    await open(page, 'healthy', '/system')

    const { site, catalogue } = fixture('healthy').snapshot

    await expect(page.getByText(site.wp, { exact: true })).toBeVisible()
    await expect(page.getByText(site.php, { exact: true })).toBeVisible()
    await expect(page.getByText(`Version ${site.fluentcart}`)).toBeVisible()
    await expect(page.getByText('Straight from fchub.co')).toBeVisible()

    // 2026-07-24T09:15:00+00:00, through Intl at en-US in UTC. The separator
    // before AM is a narrow no-break space in current ICU, so it is matched
    // rather than typed.
    expect(catalogue.last_refresh).toBe('2026-07-24T09:15:00+00:00')
    await expect(page.getByText(/Jul 24, 2026, 9:15\sAM/)).toBeVisible()

    // Full capabilities, so the interface says nothing about them at all.
    await expect(page.getByText('Your account cannot')).toHaveCount(0)

    await expectNoLeaks(page, 'the System screen')
    await expect(page.locator('#fchub-app')).toHaveScreenshot('system-healthy-desktop.png')
  })

  test('checks for updates, and says when it last managed to', async ({ page }) => {
    await open(page, 'healthy', '/system')

    await expect(page.getByText(/Jul 24, 2026, 9:15\sAM/)).toBeVisible()

    await page.getByRole('button', { name: 'Check for updates' }).click()

    const banner = page.locator('[role="status"][aria-live="polite"] .fchub-banner')

    await expect(banner).toContainText('Catalogue refreshed.')

    // A refresh that reached fchub.co is good news, so this one stays green
    // even though it is the same route that goes amber on the offline fixture.
    await expect(banner).not.toHaveClass(/fchub-banner--warning/)

    // The envelope that came back is a different one, and "Last checked" is
    // where that shows. Without this, ~170 lines of healthy.json describe a
    // response nothing ever asks for.
    await expect(page.getByText(/Jul 24, 2026, 10:30\sAM/)).toBeVisible()
    await expect(page.getByText('Straight from fchub.co')).toBeVisible()

    await expectNoLeaks(page, 'the System screen after a successful refresh')
  })

  test('installs a product, moves it, and does not strand the keyboard', async ({ page }) => {
    await open(page, 'healthy')

    const wishlist = page.getByRole('article').filter({ hasText: 'Wishlist' })

    await expect(wishlist.getByText('Not installed')).toBeVisible()
    await wishlist.getByRole('button', { name: 'Install and activate' }).focus()
    await wishlist.getByRole('button', { name: 'Install and activate' }).click()

    // role="status" — the polite region, because nothing went wrong.
    const polite = page.locator('[role="status"][aria-live="polite"]')

    await expect(polite).toContainText('Wishlist is installed and switched on.')

    await expect(
      page.locator('.fchub-summary', { hasText: 'Active products' }).getByText('6', { exact: true }),
    ).toBeVisible()
    await expect(
      page.locator('section[aria-labelledby="fchub-installed-heading"]').getByRole('article'),
    ).toHaveCount(6)
    await expect(page.getByRole('heading', { name: 'The rest of the suite' })).toHaveCount(0)

    // The card that held the button was unmounted by its own success, so the
    // shell's safety net has to catch focus rather than let it fall to <body>.
    await expect(page.locator('#fchub-app main.fchub-main')).toBeFocused()

    await expectNoLeaks(page, 'the Overview after an install')
  })
})

// ── Update waiting ───────────────────────────────────────────────────────────

test.describe('an update waiting', () => {
  test('names the product, the versions, and the one thing to do', async ({ page }) => {
    await open(page, 'update')

    await expect(page.getByRole('heading', { name: 'One thing could use a look.' })).toBeVisible()
    await expect(page.getByText('On this site, one update is waiting.')).toBeVisible()

    await expect(page.getByRole('heading', { name: 'Worth a look' })).toBeVisible()
    await expect(
      page.getByText('Multi-Currency has 1.4.0 ready. This site is on 1.3.0.'),
    ).toBeVisible()

    const card = page.getByRole('article').filter({ hasText: 'Multi-Currency' })

    await expect(card.getByText('1.3.0 installed · 1.4.0 ready')).toBeVisible()
    await expect(card.getByText('Update ready')).toBeVisible()

    // The button says which release it is fetching. The plan's draft called this
    // "Update Multi-Currency"; the interface names the version instead, which is
    // the more useful half of the sentence.
    await expect(card.getByRole('button', { name: 'Update to 1.4.0' })).toBeVisible()

    await expectNoLeaks(page, 'the Overview with an update waiting')
    await expect(page.locator('#fchub-app')).toHaveScreenshot('overview-update-desktop.png')
  })

  test('applies the update and goes quiet again', async ({ page }) => {
    await open(page, 'update')

    const card = page.getByRole('article').filter({ hasText: 'Multi-Currency' })

    await card.getByRole('button', { name: 'Update to 1.4.0' }).click()

    await expect(page.locator('[role="status"][aria-live="polite"]')).toContainText(
      'Multi-Currency is now on 1.4.0.',
    )

    await expect(card.getByText('Version 1.4.0')).toBeVisible()
    await expect(card.getByRole('button', { name: /^Update to/ })).toHaveCount(0)
    await expect(
      page.getByRole('heading', { name: 'Everything is ticking along nicely.' }),
    ).toBeVisible()

    await expectNoLeaks(page, 'the Overview after a successful update')
  })
})

// ── A product reporting its own problem ──────────────────────────────────────

test.describe('a product that says it is unhappy', () => {
  test('is not drowned out by a hero saying everything is fine', async ({ page }) => {
    await open(page, 'attention')

    // Nothing to update, nothing blocked — both counts the envelope carries
    // read zero. `health` comes from the product's own descriptor and has no
    // count of its own, which is exactly how this state used to render as
    // "Everything is ticking along nicely" above a Needs attention badge.
    await expect(
      page.getByRole('heading', { name: 'Everything is ticking along nicely.' }),
    ).toHaveCount(0)
    await expect(page.getByRole('heading', { name: 'One thing could use a look.' })).toBeVisible()
    await expect(page.getByText('On this site, one product is reporting a problem.')).toBeVisible()

    const sentence =
      'Two membership plans have no content rules, so nobody is being granted anything.'

    await expect(page.getByRole('heading', { name: 'Worth a look' })).toBeVisible()
    await expect(page.locator('.fchub-attention').getByText(sentence)).toBeVisible()

    const memberships = page.getByRole('article').filter({ hasText: 'Memberships' })

    await expect(memberships.getByText('Needs attention')).toBeVisible()
    await expect(memberships.locator('[data-health]')).toHaveText(sentence)

    // Nothing is blocked, so the counts stay honest about themselves.
    await expect(
      page.locator('.fchub-summary', { hasText: 'Compatibility issues' }).getByText('0', { exact: true }),
    ).toBeVisible()
    await expect(page.getByText('Nothing is being held back by this site.')).toBeVisible()

    await expectNoLeaks(page, 'the Overview with a product reporting a problem')
    await expect(page.locator('#fchub-app')).toHaveScreenshot('overview-attention-desktop.png')
  })
})

// ── Incompatible ─────────────────────────────────────────────────────────────

test.describe('a site that cannot run half the suite', () => {
  test('explains every blocked product in a sentence', async ({ page }) => {
    await open(page, 'incompatible')

    await expect(
      page.getByRole('heading', { name: 'A few things could use a look.' }),
    ).toBeVisible()
    // "cannot run here yet" was a claim the same screen contradicted three
    // lines below: `compatibility_issues` counts `unknown` alongside `blocked`,
    // and Portal Extender's own sentence admits FCHub has no idea. "Not ready
    // to run here" covers both without overstating either.
    await expect(
      page.getByText('On this site, 6 products are not ready to run here and one product'),
    ).toBeVisible()

    // One sentence per reason branch the resolver can produce.
    await expect(
      page.getByText('Memberships needs PHP 8.3. This site runs 8.1.29.').first(),
    ).toBeVisible()
    await expect(
      page.getByText('Fakturownia needs FluentCart installed and active first.').first(),
    ).toBeVisible()
    await expect(
      page.getByText('Portal Extender needs fluentcommunity, which FCHub cannot check here.').first(),
    ).toBeVisible()

    // An active product whose platform went away still says what that means —
    // on its own card, and in the panel, which is where the Overview is
    // supposed to list everything worth a look.
    const p24 = page.getByRole('article').filter({ hasText: 'Przelewy24' })

    await expect(p24.getByText('Needs attention')).toBeVisible()
    await expect(
      p24.getByText('Przelewy24 is switched on but FluentCart is not, so it is not taking any payments.'),
    ).toBeVisible()
    await expect(
      page
        .locator('.fchub-attention')
        .getByText('Przelewy24 is switched on but FluentCart is not, so it is not taking any payments.'),
    ).toBeVisible()

    await expect(page.getByText('Cannot run here').first()).toBeVisible()
    await expect(page.getByText('Cannot be checked')).toBeVisible()

    await expectNoLeaks(page, 'the Overview on an incompatible site')
    await expect(page.locator('#fchub-app')).toHaveScreenshot('overview-incompatible-desktop.png')
  })

  test('keeps blocked buttons visible, inert, and explained', async ({ page }) => {
    await open(page, 'incompatible', '/products')

    const memberships = page.getByRole('article').filter({ hasText: 'Memberships' })
    const button = memberships.getByRole('button', { name: 'Switch on' })

    await expect(button).toHaveAttribute('aria-disabled', 'true')

    // aria-disabled rather than the disabled attribute: the button keeps its
    // place in the tab order, and takes its explanation with it.
    const describedBy = await button.getAttribute('aria-describedby')

    expect(describedBy).toBeTruthy()
    await expect(page.locator(`[id="${describedBy}"]`)).toHaveText(
      'Memberships needs PHP 8.3. This site runs 8.1.29.',
    )

    // force, because Playwright's own actionability check reads aria-disabled
    // and refuses to click — which is the tooling agreeing with the markup.
    // The click still has to be dispatched to prove the handler declines it.
    await button.click({ force: true })

    // Nothing was attempted, so nothing was announced.
    await expect(page.locator('[role="alert"][aria-live="assertive"]')).toBeEmpty()
    await expect(page.locator('[role="status"][aria-live="polite"]')).toBeEmpty()

    await expectNoLeaks(page, 'the Products screen on an incompatible site')
    await expect(page.locator('#fchub-app')).toHaveScreenshot('products-incompatible-desktop.png')
  })

  test('says FluentCart is absent rather than reporting a version of nothing', async ({ page }) => {
    await open(page, 'incompatible', '/system')

    expect(fixture('incompatible').snapshot.site.fluentcart).toBeNull()

    await expect(page.getByText('Not running on this site')).toBeVisible()
    await expect(page.getByText('6.5.2', { exact: true })).toBeVisible()
    await expect(page.getByText('8.1.29', { exact: true })).toBeVisible()
    await expect(page.getByText('Nothing is waiting on a newer WordPress.')).toBeVisible()

    // Rows are keyed on the requirement a product actually waits for, so the
    // FluentCart row claims only FluentCart and a platform FCHub has never
    // heard of falls through to "Other requirements" — rather than being filed
    // under a heading that names the wrong product.
    const compatibility = page.locator('section[aria-labelledby="fchub-compatibility-heading"]')
    const row = (label) =>
      compatibility
        .locator('.fchub-facts__row')
        .filter({ has: page.locator('dt', { hasText: label }) })

    await expect(row('FluentCart')).toContainText('Przelewy24 needs FluentCart')
    await expect(row('FluentCart')).not.toContainText('fluentcommunity')
    await expect(row('Other requirements')).toHaveText(
      /Portal Extender needs fluentcommunity, which FCHub cannot check here\./,
    )

    await expectNoLeaks(page, 'the System screen on an incompatible site')
    await expect(page.locator('#fchub-app')).toHaveScreenshot('system-incompatible-desktop.png')
  })
})

// ── Offline catalogue ────────────────────────────────────────────────────────

test.describe('a catalogue that could not be reached', () => {
  test('still renders every product from the bundled copy', async ({ page }) => {
    await open(page, 'offline')

    expect(fixture('offline').snapshot.catalogue).toEqual({ source: 'bundled', last_refresh: null })

    // The fallback is the whole point: an unreachable fchub.co must not empty
    // the screen.
    await expect(page.getByRole('article')).toHaveCount(6)
    await expect(
      page.getByRole('heading', { name: 'Everything is ticking along nicely.' }),
    ).toBeVisible()

    await expectNoLeaks(page, 'the Overview on a bundled catalogue')
    await expect(page.locator('#fchub-app')).toHaveScreenshot('overview-offline-desktop.png')
  })

  test('names the fallback and the missing capability without apologising', async ({ page }) => {
    await open(page, 'offline', '/system')

    await expect(page.getByText('Using the catalogue included with FCHub')).toBeVisible()
    await expect(page.getByText('FCHub has not managed a successful check yet.')).toBeVisible()
    await expect(
      page.getByText('Your account cannot install products on this site.'),
    ).toBeVisible()

    await page.getByRole('button', { name: 'Check for updates' }).click()

    const banner = page.locator('[role="status"][aria-live="polite"] .fchub-banner')

    await expect(banner).toContainText(
      'The catalogue could not be reached, so this is the copy that shipped with FCHub.',
    )

    // Green is a claim. A refresh that could not reach fchub.co is not good
    // news, so the banner is amber — --fchub-stat-orange on its background.
    await expect(banner).toHaveClass(/fchub-banner--warning/)
    expect(await styles(banner, ['backgroundColor', 'color'])).toEqual({
      backgroundColor: 'rgb(255, 243, 235)',
      color: 'rgb(113, 51, 10)',
    })

    await expectNoLeaks(page, 'the System screen on a bundled catalogue')
    await expect(page.locator('#fchub-app')).toHaveScreenshot('system-offline-desktop.png')
  })

  test('still calls a successful action good news, offline or not', async ({ page }) => {
    await open(page, 'offline')

    await page
      .getByRole('article')
      .filter({ hasText: 'Wishlist' })
      .getByRole('button', { name: 'Switch on' })
      .click()

    const banner = page.locator('[role="status"][aria-live="polite"] .fchub-banner')

    await expect(banner).toContainText('Wishlist is switched on.')

    // The other half of the tone rule, and the half that is easy to break while
    // fixing the first: this notice describes the mutation, not the catalogue.
    // An offline site runs permanently on the bundled copy, and painting every
    // successful action amber there would make the whole screen look broken.
    await expect(banner).not.toHaveClass(/fchub-banner--warning/)
    expect(await styles(banner, ['backgroundColor', 'color'])).toEqual({
      backgroundColor: 'rgb(234, 247, 239)',
      color: 'rgb(30, 91, 52)',
    })

    await expectNoLeaks(page, 'the Overview after an action on a bundled catalogue')
  })

  test('withholds the install button and says whose fault that is', async ({ page }) => {
    await open(page, 'offline', '/products')

    const card = page.getByRole('article').filter({ hasText: 'Multi-Currency' })
    const button = card.getByRole('button', { name: 'Install and activate' })

    await expect(button).toHaveAttribute('aria-disabled', 'true')
    await expect(card.getByText('Your account cannot make that change on this site.')).toBeVisible()

    // Switching a product on is a different capability, and this account has it.
    const wishlist = page.getByRole('article').filter({ hasText: 'Wishlist' })

    await expect(wishlist.getByRole('button', { name: 'Switch on' })).not.toHaveAttribute(
      'aria-disabled',
      'true',
    )

    await expectNoLeaks(page, 'the Products screen with a capability withheld')
  })
})

// ── Failed operations ────────────────────────────────────────────────────────

test.describe('a mutation that failed', () => {
  test('announces the failure and hands the button back', async ({ page }) => {
    await open(page, 'failed-operation')

    const card = page.getByRole('article').filter({ hasText: 'Multi-Currency' })
    const button = card.getByRole('button', { name: 'Update to 1.4.0' })

    await button.focus()
    await button.click()

    const alert = page.locator('[role="alert"][aria-live="assertive"]')

    await expect(alert).toContainText(
      'The download could not be reached, so nothing was changed. Worth another go in a minute.',
    )

    // The operation failed, so the interface must still describe the old state.
    await expect(card.getByText('1.3.0 installed · 1.4.0 ready')).toBeVisible()

    // The button survived, so focus belongs on it and nowhere else.
    await expect(button).toBeFocused()

    await expectNoLeaks(page, 'the Overview after a failed update')
    await expect(page.locator('#fchub-app')).toHaveScreenshot(
      'overview-failed-operation-desktop.png',
    )
  })

  test('reads WordPress core’s error shape as fluently as its own', async ({ page }) => {
    await open(page, 'failed-operation')

    const p24 = page.getByRole('article').filter({ hasText: 'Przelewy24' })

    await p24.getByRole('button', { name: 'Switch off' }).click()

    // {code, message, data:{status}} — no `success`, no `product`. The code
    // stays off screen, and so does core's own four-word summary of it:
    // "Cookie check failed" is accurate, means nothing to anybody outside
    // WordPress, and is the one failure with no way out on the page, because
    // the nonce was baked in when WordPress rendered it. Asserting the
    // replacement pins the branch — the fixture sends core's wording, so
    // seeing this sentence can only mean the special case fired.
    await expect(page.locator('[role="alert"][aria-live="assertive"]')).toContainText(
      'Your WordPress login session expired before the request arrived, so nothing was changed. ' +
        'Reload the page and try again.',
    )
    await expect(page.locator('#fchub-app')).not.toContainText('Cookie check failed')

    await expectNoLeaks(page, 'the Overview after a core-shaped failure')
  })

  test('stops calling a product not installed once the failure put it on disk', async ({ page }) => {
    await open(page, 'failed-operation')

    const wishlist = page.getByRole('article').filter({ hasText: 'Wishlist' })

    await expect(wishlist.getByText('Not installed')).toBeVisible()
    await wishlist.getByRole('button', { name: 'Install only' }).click()

    // version_mismatch: thrown after the files landed, so it answers with
    // `state` — the refreshed picture, minus the notice a success would carry.
    await expect(page.locator('[role="alert"][aria-live="assertive"]')).toContainText(
      'Wishlist installed, but the files are not the 1.0.1 release we expected.',
    )

    // The banner says it failed. The card stops insisting nothing happened to
    // something that is now sitting in wp-content/plugins at the wrong version,
    // which is what the next click would have been refused for.
    await expect(
      page
        .locator('section[aria-labelledby="fchub-installed-heading"]')
        .getByRole('article')
        .filter({ hasText: 'Wishlist' }),
    ).toBeVisible()
    await expect(wishlist.getByText('Switched off')).toBeVisible()
    await expect(wishlist.getByText('1.0.0 installed · 1.0.1 ready')).toBeVisible()
    await expect(wishlist.getByRole('button', { name: 'Switch on' })).toBeVisible()

    await expectNoLeaks(page, 'the Overview after a failure that changed the site')
  })

  test('does not claim nothing happened when something did', async ({ page }) => {
    await open(page, 'failed-operation')

    const wishlist = page.getByRole('article').filter({ hasText: 'Wishlist' })

    await wishlist.getByRole('button', { name: 'Install and activate' }).click()

    // A 200 carrying only a code: the install landed, the re-read did not. That
    // is a warning in the polite region, not a failure in the assertive one.
    await expect(page.locator('[role="alert"][aria-live="assertive"]')).toBeEmpty()
    await expect(page.locator('[role="status"][aria-live="polite"]')).toContainText(
      'That worked, but FCHub could not read its catalogue afterwards.',
    )

    await expectNoLeaks(page, 'the Overview after a half-successful install')
  })

  test('lets the banner be dismissed without losing the keyboard', async ({ page }) => {
    await open(page, 'failed-operation')

    await page
      .getByRole('article')
      .filter({ hasText: 'Multi-Currency' })
      .getByRole('button', { name: 'Update to 1.4.0' })
      .click()

    const alert = page.locator('[role="alert"][aria-live="assertive"]')

    await alert.getByRole('button', { name: 'Dismiss' }).click()

    await expect(alert).toBeEmpty()
    await expect(page.locator('#fchub-app main.fchub-main')).toBeFocused()

    await expectNoLeaks(page, 'the Overview after dismissing a failure')
  })
})

// ── Keyboard ─────────────────────────────────────────────────────────────────

test.describe('a keyboard', () => {
  /** The ring every focusable control in FCHub wears. */
  const RING = {
    outlineStyle: 'solid',
    outlineWidth: '2px',
    outlineColor: 'rgb(77, 110, 245)',
  }

  const RING_KEYS = Object.keys(RING)

  test('walks the header nav, and can see where it is', async ({ page }) => {
    await open(page, 'healthy')

    for (const label of ['Overview', 'Products', 'System']) {
      await page.keyboard.press('Tab')

      const link = page.getByRole('link', { name: label })

      await expect(link).toBeFocused()
      expect(await styles(link, RING_KEYS), `${label} focus ring`).toEqual(RING)
    }

    // Straight into the first card's primary action, because the section
    // headings and the card heading are not in the tab order.
    await page.keyboard.press('Tab')

    const first = page.getByRole('article').first().getByRole('link', { name: 'Open settings' })

    await expect(first).toBeFocused()
    expect(await styles(first, RING_KEYS)).toEqual(RING)
  })

  test('reaches the product filters and drives them without a mouse', async ({ page }) => {
    await open(page, 'healthy', '/products')

    await page.keyboard.press('Tab')
    await page.keyboard.press('Tab')
    await page.keyboard.press('Tab')
    await page.keyboard.press('Tab')

    const all = page.getByRole('button', { name: 'All 6' })
    const installed = page.getByRole('button', { name: 'Installed 5' })

    await expect(all).toBeFocused()
    expect(await styles(all, RING_KEYS), 'filter focus ring').toEqual(RING)
    await expect(all).toHaveAttribute('aria-pressed', 'true')

    await page.keyboard.press('Tab')
    await expect(installed).toBeFocused()
    await page.keyboard.press('Enter')

    await expect(installed).toHaveAttribute('aria-pressed', 'true')
    await expect(all).toHaveAttribute('aria-pressed', 'false')
    await expect(page.getByRole('article')).toHaveCount(5)
  })

  test('reaches the refresh button and can see it', async ({ page }) => {
    await open(page, 'healthy', '/system')

    await page.keyboard.press('Tab')
    await page.keyboard.press('Tab')
    await page.keyboard.press('Tab')
    await page.keyboard.press('Tab')

    const refresh = page.getByRole('button', { name: 'Check for updates' })

    await expect(refresh).toBeFocused()
    expect(await styles(refresh, RING_KEYS), 'refresh focus ring').toEqual(RING)
  })

  test('reaches a product action, and never traps focus behind a blocked one', async ({ page }) => {
    await open(page, 'incompatible', '/products')

    const blocked = page
      .getByRole('article')
      .filter({ hasText: 'Fakturownia' })
      .getByRole('button', { name: 'Install and activate' })

    await tabTo(page, blocked)

    expect(await styles(blocked, RING_KEYS), 'blocked primary focus ring').toEqual(RING)

    // aria-disabled, not disabled — so Tab moves on rather than skipping the
    // control and its explanation together.
    //
    // Asserting the next control by name, not merely that the button lost
    // focus: `not.toBeFocused()` is also satisfied by focus falling out of the
    // interface onto <body>, which is the failure this test is supposed to
    // catch rather than tolerate. Fakturownia is blocked and not installed, so
    // it has no secondary buttons and no settings link — the next stop is its
    // Documentation link.
    await page.keyboard.press('Tab')

    await expect(
      page
        .getByRole('article')
        .filter({ hasText: 'Fakturownia' })
        .getByRole('link', { name: 'Documentation' }),
    ).toBeFocused()
  })
})

// ── Colour ───────────────────────────────────────────────────────────────────

test.describe('the palette', () => {
  test('paints the page, cards, text and borders with the approved tokens', async ({ page }) => {
    await open(page, 'healthy')

    expect(
      await styles(page.locator('#fchub-app'), ['backgroundColor', 'color', 'fontSize']),
      '#fchub-app — --fchub-page-bg on --fchub-text-primary',
    ).toEqual({
      backgroundColor: 'rgb(243, 245, 250)',
      color: 'rgb(21, 29, 38)',
      fontSize: '14px',
    })

    const card = page.getByRole('article').first()

    expect(
      await styles(card, ['backgroundColor', 'borderTopColor']),
      '.fchub-card — --fchub-card-bg inside --fchub-border-color',
    ).toEqual({
      backgroundColor: 'rgb(255, 255, 255)',
      borderTopColor: 'rgb(234, 236, 240)',
    })

    expect(
      await styles(card.locator('.fchub-card__title'), ['color']),
      '.fchub-card__title — --fchub-text-primary',
    ).toEqual({ color: 'rgb(21, 29, 38)' })

    expect(
      await styles(card.locator('.fchub-card__description'), ['color']),
      '.fchub-card__description — --fchub-text-secondary',
    ).toEqual({ color: 'rgb(86, 88, 101)' })

    // The brand mark is the one place --fchub-primary paints something, and
    // WCAG exempts a logo from contrast anyway.
    expect(
      await styles(page.locator('.fchub-header__mark'), ['color']),
      '.fchub-header__mark — --fchub-primary',
    ).toEqual({ color: 'rgb(77, 110, 245)' })
  })

  test('fills primary buttons with the darker blue, because white sits on them', async ({
    page,
  }) => {
    await open(page, 'healthy')

    const install = page
      .getByRole('article')
      .filter({ hasText: 'Wishlist' })
      .getByRole('button', { name: 'Install and activate' })

    expect(
      await styles(install, ['backgroundColor', 'color', 'borderTopColor', 'fontSize']),
      'primary button — --fchub-primary-strong (#3A5BE0). White on #4D6EF5 is 4.32:1 ' +
        'and this text is 13px, so --fchub-primary here is a contrast regression.',
    ).toEqual({
      backgroundColor: 'rgb(58, 91, 224)',
      color: 'rgb(255, 255, 255)',
      borderTopColor: 'rgb(58, 91, 224)',
      fontSize: '13px',
    })
  })

  test('never uses the lighter blue as a fill, on any screen', async ({ page }) => {
    // Both families, because `incompatible` has no *enabled* primary button —
    // every one of its cards is aria-disabled and painted through the
    // disabled-hover rule. An enabled primary going back to #4D6EF5 is exactly
    // the regression this guard is named after, and `healthy` is where one
    // exists to be checked.
    for (const name of ['healthy', 'incompatible']) {
      for (const route of ['/', '/products', '/system']) {
        await open(page, name, route)

        const offenders = await page.evaluate(() => {
          const LIGHT = 'rgb(77, 110, 245)'
          const found = []

          for (const element of document.querySelectorAll('#fchub-app, #fchub-app *')) {
            const computed = getComputedStyle(element)
            const at = `${element.tagName.toLowerCase()}.${element.getAttribute('class') || ''}`

            if (computed.backgroundColor === LIGHT) {
              found.push(`background: ${at}`)
            }

            // The brand mark colours its SVG through currentColor, and is the
            // single documented exception.
            if (computed.color === LIGHT && !element.closest('.fchub-header__mark')) {
              found.push(`color: ${at}`)
            }
          }

          return found
        })

        expect(
          offenders,
          `${name} ${route} — --fchub-primary is for borders and focus rings`,
        ).toEqual([])
      }
    }
  })

  test('paints each card note the severity its badge is claiming', async ({ page }) => {
    // Computed colours rather than pixels. The screenshot comparison carries
    // Playwright's default perceptual threshold, and these three fills are pale
    // enough to slide underneath it — a note going from pink to amber is a
    // change to what the interface is telling somebody, and it passed sixteen
    // zero-tolerance screenshots without one of them blinking.
    const AMBER = { backgroundColor: 'rgb(255, 243, 235)', color: 'rgb(113, 51, 10)' }
    const PINK = { backgroundColor: 'rgb(255, 235, 244)', color: 'rgb(104, 18, 61)' }
    const NEUTRAL = { backgroundColor: 'rgb(243, 245, 250)', color: 'rgb(86, 88, 101)' }

    const note = (card) => card.locator('.fchub-card__note').last()
    const card = (name) => page.getByRole('article').filter({ hasText: name })

    await open(page, 'incompatible', '/products')

    expect(
      await styles(note(card('Memberships')), ['backgroundColor', 'color']),
      'a hard stop — the badge says "Cannot run here", so the note is the loud one',
    ).toEqual(PINK)

    expect(
      await styles(note(card('Portal Extender')), ['backgroundColor', 'color']),
      'unverifiable — the badge says "Cannot be checked" in amber, and pink underneath ' +
        'it was the card disagreeing with itself about how bad this is',
    ).toEqual(AMBER)

    // A product's own health message, which is a warning rather than a stop.
    expect(
      await styles(card('Przelewy24').locator('[data-health]'), ['backgroundColor', 'color']),
    ).toEqual(AMBER)

    await open(page, 'offline', '/products')

    expect(
      await styles(note(card('Multi-Currency')), ['backgroundColor', 'color']),
      'a permissions fact is the site working as configured, not something going wrong',
    ).toEqual(NEUTRAL)
  })

  test('paints nothing that is not in the palette', async ({ page }) => {
    // Fifteen page loads: every family on every screen.
    test.slow()

    for (const name of FAMILIES) {
      for (const route of ['/', '/products', '/system']) {
        await open(page, name, route)

        const strays = await page.evaluate(() => {
          /** Colour functions, pulled out of values that carry more than one. */
          const COLOUR = /#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\([^)]*\)/gi

          /**
           * Every colour one box actually paints.
           *
           * `fill` and `stroke` are read only on SVG elements: they are
           * inherited SVG properties whose initial value is black, so an HTML
           * <div> reports rgb(0, 0, 0) for a colour it never paints, and
           * including that would flood the sweep with a false stray.
           */
          function painted(element, computed) {
            const seen = [computed.color, computed.backgroundColor]

            if (element.namespaceURI === 'http://www.w3.org/2000/svg') {
              seen.push(computed.fill, computed.stroke)
            }

            for (const side of ['Top', 'Right', 'Bottom', 'Left']) {
              if (computed[`border${side}Style`] !== 'none') {
                seen.push(computed[`border${side}Color`])
              }
            }

            if (computed.outlineStyle !== 'none') {
              seen.push(computed.outlineColor)
            }

            // These carry their colours inside a longer value — a shadow's
            // offsets, a gradient's stops — so the colours are extracted
            // rather than compared whole.
            for (const value of [
              computed.boxShadow,
              computed.backgroundImage,
              computed.textDecorationColor,
            ]) {
              if (value && value !== 'none') {
                seen.push(...(value.match(COLOUR) || []))
              }
            }

            return seen.filter((value) => value && value !== 'none')
          }

          const found = new Set()

          for (const element of document.querySelectorAll('#fchub-app, #fchub-app *')) {
            for (const value of painted(element, getComputedStyle(element))) {
              found.add(value)
            }

            // A pseudo-element with no content generates no box and paints
            // nothing, so only the ones that exist are swept.
            for (const pseudo of ['::before', '::after']) {
              const computed = getComputedStyle(element, pseudo)

              if (computed.content === 'none') {
                continue
              }

              for (const value of painted(element, computed)) {
                found.add(value)
              }
            }
          }

          return [...found]
        })

        // A page that painted nothing at all would satisfy every assertion in
        // the loop below by never entering it. A mount failure the open()
        // helper did not catch, or a selector that stopped matching, would
        // look exactly like a clean sweep.
        expect(strays.length, `${name} ${route} painted nothing — the sweep found no elements`)
          .toBeGreaterThan(0)

        for (const value of strays) {
          expect(
            PALETTE.has(value),
            `${name} ${route} paints ${value}, which is not an FCHub token`,
          ).toBe(true)
        }
      }
    }
  })
})

// ── The build itself ─────────────────────────────────────────────────────────

test.describe('the bundle under test', () => {
  test('is the built one, byte for byte, and not a development module', async ({ page }) => {
    const requested = []
    const origins = new Set()

    page.on('request', (request) => {
      const url = new URL(request.url())

      requested.push(url.pathname)
      origins.add(url.origin)
    })

    await open(page, 'healthy')

    // Nothing left the machine. Not a font CDN, not an analytics beacon, not
    // fchub.co — the whole point of a suite that can be trusted offline.
    expect([...origins]).toEqual(['http://127.0.0.1:4173'])

    const loaded = await page.evaluate(() => window.__fchubSmoke)
    const manifest = JSON.parse(readFileSync(`${ROOT}assets/dist/.vite/manifest.json`, 'utf8'))
    const entry = manifest['resources/admin/main.js']

    expect(loaded.entry).toBe(`assets/dist/${entry.file}`)
    expect(loaded.styles).toEqual(entry.css.map((file) => `assets/dist/${file}`))

    // Served bytes against built bytes. Nothing transformed anything on the way.
    const served = await page.request.get(`/${loaded.entry}`)

    expect(served.status()).toBe(200)
    expect(await served.text()).toBe(readFileSync(`${ROOT}${loaded.entry}`, 'utf8'))

    // Nothing from a dev server, the source tree, or node_modules was fetched.
    expect(
      requested.filter((path) => /\/@vite|\/@id|\/@fs|\/resources\/|\/node_modules\//.test(path)),
    ).toEqual([])

    expect(requested).toContain(`/${loaded.entry}`)

    // Every module the document loaded is either the smoke host or the build.
    const scripts = await page.evaluate(() =>
      [...document.querySelectorAll('script[type="module"]')].map((tag) =>
        new URL(tag.src).pathname,
      ),
    )

    expect(scripts).toEqual(['/smoke/main.js', `/${loaded.entry}`])
  })

  test('answers every screen from the fixture and nothing else', async ({ page }) => {
    await open(page, 'healthy')

    expect(await page.evaluate(() => window.__fchubSmoke.requests)).toEqual(['GET products'])
  })
})

// ── Narrow viewport ──────────────────────────────────────────────────────────

test.describe('at 768 × 1024', () => {
  test.use({ viewport: { width: 768, height: 1024 } })

  for (const name of FAMILIES) {
    test(`${name} stacks into one column and stays readable`, async ({ page }) => {
      await open(page, name)

      // Below 900px the summary and card grids collapse to a single column.
      const grid = page.locator('#fchub-app .fchub-card-grid').first()

      expect(await styles(grid, ['gridTemplateColumns'])).toEqual({
        gridTemplateColumns: expect.stringMatching(/^\d+(\.\d+)?px$/),
      })

      await expectNoLeaks(page, `${name} at 768px`)
      await expect(page.locator('#fchub-app')).toHaveScreenshot(`overview-${name}-mobile.png`)
    })
  }

  test('shows the failure banner without pushing anything off screen', async ({ page }) => {
    await open(page, 'failed-operation')

    await page
      .getByRole('article')
      .filter({ hasText: 'Multi-Currency' })
      .getByRole('button', { name: 'Update to 1.4.0' })
      .click()

    await expect(page.locator('[role="alert"][aria-live="assertive"]')).toContainText(
      'The download could not be reached',
    )

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )

    expect(overflow, 'nothing should scroll sideways at 768px').toBeLessThanOrEqual(0)

    await expect(page.locator('#fchub-app')).toHaveScreenshot(
      'overview-failed-operation-banner-mobile.png',
    )
  })
})
