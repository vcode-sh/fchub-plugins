import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'

/**
 * The three pages and the shell around them. ProductCard and the store are
 * tested directly elsewhere; this file covers what only appears once they are
 * assembled — the calm and attention Overviews, the Products filters, the
 * System page's catalogue wording, and what happens when the very first read
 * fails.
 *
 * Everything is imported after `vi.resetModules()` because the store is a
 * module singleton, exactly as it is in production. Sharing one between tests
 * would let a passing test be a leftover from the previous one.
 */
function product(overrides = {}) {
  return {
    slug: 'fchub-p24',
    name: 'Przelewy24',
    description: 'A payment gateway.',
    version: '1.0.3',
    requires_wp: '6.4',
    requires_php: '8.1',
    dependencies: ['fluentcart'],
    docs_url: 'https://fchub.co/docs/fchub-p24',
    release_url: 'https://github.com/vcode-sh/fchub-plugins/releases/tag/fchub-p24/v1.0.3',
    lifecycle: 'active',
    update: 'current',
    compatibility: 'compatible',
    compatibility_reason: null,
    health: 'unknown',
    health_message: null,
    installed_version: '1.0.3',
    admin_url: 'https://example.com/wp-admin/admin.php?page=fluent-cart',
    actions: ['deactivate'],
    ...overrides,
  }
}

const BUSY = {
  products: [
    product(),
    product({
      slug: 'fchub-memberships',
      name: 'Memberships',
      installed_version: '1.3.0',
      version: '1.4.0',
      update: 'available',
      actions: ['update', 'deactivate'],
    }),
    product({
      slug: 'fchub-wishlist',
      name: 'Wishlist',
      lifecycle: 'not_installed',
      update: 'unknown',
      installed_version: null,
      admin_url: null,
      compatibility: 'blocked',
      compatibility_reason: { requirement: 'php', required: '8.3', current: '8.1.2' },
      actions: [],
    }),
    product({
      slug: 'fchub-multi-currency',
      name: 'Multi-Currency',
      lifecycle: 'not_installed',
      update: 'unknown',
      installed_version: null,
      admin_url: null,
      compatibility: 'unknown',
      compatibility_reason: { requirement: 'dependency', required: 'fluentcart', current: null },
      actions: [],
    }),
  ],
  summary: { active: 2, updates: 1, compatibility_issues: 2 },
  catalogue: { source: 'bundled', last_refresh: '2026-07-24T09:00:00+00:00' },
  site: { wp: '6.5', php: '8.1.2', fluentcart: '1.2.3' },
  capabilities: { install: true, activate: true, update: true },
}

const CALM = {
  products: [product()],
  summary: { active: 1, updates: 0, compatibility_issues: 0 },
  catalogue: { source: 'remote', last_refresh: '2026-07-24T09:00:00+00:00' },
  site: { wp: '6.7', php: '8.3.0', fluentcart: '1.2.3' },
  capabilities: { install: true, activate: true, update: true },
}

/**
 * Everything compatible, everything current, and one running product saying it
 * is unhappy. `health` comes from the product's own descriptor and is resolved
 * independently of compatibility, so this state is entirely reachable — and it
 * is the one the hero used to sail straight past.
 */
const HEALTH_ATTENTION = {
  products: [
    product(),
    product({
      slug: 'fchub-memberships',
      name: 'Memberships',
      health: 'attention',
      health_message: 'Two plans have no content rules, so nobody gets anything.',
    }),
  ],
  summary: { active: 2, updates: 0, compatibility_issues: 0 },
  catalogue: { source: 'remote', last_refresh: '2026-07-24T09:00:00+00:00' },
  site: { wp: '6.7', php: '8.3.0', fluentcart: '1.2.3' },
  capabilities: { install: true, activate: true, update: true },
}

/** Nothing installed, and nothing installable either — a site without FluentCart. */
const BARE_SITE = {
  products: [
    product({
      slug: 'fchub-wishlist',
      name: 'Wishlist',
      lifecycle: 'not_installed',
      update: 'unknown',
      installed_version: null,
      admin_url: null,
      compatibility: 'blocked',
      compatibility_reason: { requirement: 'dependency', required: 'fluentcart', current: null },
      actions: [],
    }),
  ],
  summary: { active: 0, updates: 0, compatibility_issues: 1 },
  catalogue: { source: 'remote', last_refresh: '2026-07-24T09:00:00+00:00' },
  site: { wp: '6.7', php: '8.3.0', fluentcart: null },
  capabilities: { install: true, activate: true, update: true },
}

/** Not installed anywhere, and installable — the Overview's discovery row. */
const DISCOVERY = {
  products: [
    product({
      slug: 'fchub-wishlist',
      name: 'Wishlist',
      lifecycle: 'not_installed',
      update: 'unknown',
      installed_version: null,
      admin_url: null,
      actions: ['install', 'install-and-activate'],
    }),
  ],
  summary: { active: 0, updates: 0, compatibility_issues: 0 },
  catalogue: { source: 'remote', last_refresh: '2026-07-24T09:00:00+00:00' },
  site: { wp: '6.7', php: '8.3.0', fluentcart: '1.2.3' },
  capabilities: { install: true, activate: true, update: true },
}

/** The same product after install-and-activate: it has changed section. */
const DISCOVERY_INSTALLED = {
  ...DISCOVERY,
  products: [
    product({
      slug: 'fchub-wishlist',
      name: 'Wishlist',
      lifecycle: 'active',
      update: 'current',
      installed_version: '1.0.3',
      actions: ['deactivate'],
    }),
  ],
  summary: { active: 1, updates: 0, compatibility_issues: 0 },
  notice: 'Wishlist is installed and switched on.',
}

let complaints

/** Vue reports render problems through console. Silence here means silence. */
beforeEach(() => {
  complaints = []
  vi.spyOn(console, 'warn').mockImplementation((...args) => complaints.push(args.join(' ')))
  vi.spyOn(console, 'error').mockImplementation((...args) => complaints.push(args.join(' ')))
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

async function open(path, response) {
  vi.resetModules()
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response))

  const App = (await import('../../resources/admin/App.vue')).default
  const router = (await import('../../resources/admin/router/index.js')).default

  await router.push(path)
  await router.isReady()

  const wrapper = mount(App, { global: { plugins: [router] }, attachTo: document.body })

  await flushPromises()

  return wrapper
}

function ok(body) {
  return { ok: true, status: 200, json: () => Promise.resolve(body) }
}

/** Rows of the System page's Compatibility panel, and only that panel. */
function compatibilityRows(wrapper) {
  return wrapper
    .get('section[aria-labelledby="fchub-compatibility-heading"]')
    .findAll('.fchub-facts__row')
}

describe('the Overview', () => {
  it('leads with reassurance when there is nothing to do', async () => {
    const wrapper = await open('/', ok(CALM))

    expect(wrapper.text()).toContain('Everything is ticking along nicely.')
    expect(wrapper.text()).not.toContain('Worth a look')
    expect(wrapper.text()).toContain('Active products')
    expect(wrapper.text()).toContain('Useful updates')
    expect(wrapper.text()).toContain('Compatibility issues')
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('says what needs a look, in sentences, when something does', async () => {
    const wrapper = await open('/', ok(BUSY))
    const text = wrapper.text()

    expect(text).toContain('A few things could use a look.')
    expect(text).toContain('Worth a look')
    expect(text).toContain('Memberships has 1.4.0 ready. This site is on 1.3.0.')
    expect(text).toContain('Wishlist needs PHP 8.3. This site runs 8.1.2.')
    expect(text).toContain('Multi-Currency needs FluentCart, which FCHub cannot check here.')
    expect(text).not.toContain('[object Object]')
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('puts what is installed before what is merely available', async () => {
    const wrapper = await open('/', ok(BUSY))
    const text = wrapper.text()

    expect(text.indexOf('On this site')).toBeLessThan(text.indexOf('The rest of the suite'))
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('does not promise to speak up while a product is reporting a problem', async () => {
    const wrapper = await open('/', ok(HEALTH_ATTENTION))
    const text = wrapper.text()

    // Nothing needs updating and nothing is blocked, so the old hero called
    // this calm — directly above a card wearing a "Needs attention" badge.
    expect(text).not.toContain('Everything is ticking along nicely.')
    expect(text).toContain('One thing could use a look.')
    expect(text).toContain('On this site, one product is reporting a problem.')
    expect(text).toContain('Worth a look')
    expect(text).toContain('Two plans have no content rules, so nobody gets anything.')
    expect(text).toContain('Needs attention')
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('counts a health problem alongside the updates and the blocked products', async () => {
    const wrapper = await open(
      '/',
      ok({
        ...HEALTH_ATTENTION,
        products: [
          product({ update: 'available', version: '1.1.0', actions: ['update', 'deactivate'] }),
          ...HEALTH_ATTENTION.products.slice(1),
        ],
        summary: { active: 2, updates: 1, compatibility_issues: 0 },
      }),
    )

    // Two things, joined by "and" rather than run together as one.
    expect(wrapper.text()).toContain(
      'On this site, one update is waiting and one product is reporting a problem.',
    )
    expect(wrapper.text()).toContain('A few things could use a look.')
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('says what an unconfirmed requirement is, rather than claiming it is unmet', async () => {
    const wrapper = await open('/', ok(BARE_SITE))
    const text = wrapper.text()

    expect(text).toContain('On this site, one product is not ready to run here.')
    expect(text).toContain('Requirements this site does not meet, or cannot confirm.')
    expect(text).not.toContain('cannot run here yet')
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('does not call a list of blocked products a decent place to start', async () => {
    const wrapper = await open('/', ok(BARE_SITE))

    expect(wrapper.text()).toContain(
      'Nothing from the suite is installed yet. The list below shows what exists, and what each one is waiting for.',
    )
    expect(wrapper.text()).not.toContain('a decent place to start')
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('still points at the list when something on it can actually be installed', async () => {
    const wrapper = await open('/', ok(DISCOVERY))

    expect(wrapper.text()).toContain(
      'Nothing from the suite is installed yet. The list below is a decent place to start.',
    )
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('keeps tables, option names and transport status off the page entirely', async () => {
    const wrapper = await open('/', ok(BUSY))
    const html = wrapper.html()

    expect(wrapper.find('table').exists()).toBe(false)

    for (const leak of ['fchub_catalogue', 'transient', 'sha256', 'checksum', 'bundled', 'ETag']) {
      expect(html).not.toContain(leak)
    }

    wrapper.unmount()
  })
})

describe('the Products page', () => {
  it('offers all, installed and updates, each with its own count', async () => {
    const wrapper = await open('/products', ok(BUSY))
    const filters = wrapper.findAll('.fchub-filter')

    expect(filters).toHaveLength(3)
    expect(filters[0].text()).toContain('All')
    expect(filters[0].text()).toContain('4')
    expect(filters[1].text()).toContain('Installed')
    expect(filters[1].text()).toContain('2')
    expect(filters[2].text()).toContain('Updates')
    expect(filters[2].text()).toContain('1')
    expect(filters[0].attributes('aria-pressed')).toBe('true')
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('narrows to the chosen filter and says so to assistive tech', async () => {
    const wrapper = await open('/products', ok(BUSY))
    const result = wrapper.get('[data-filter-result]')

    // aria-pressed is the button's own state, not the outcome. Without the
    // live region a screen-reader user hears "Updates 1, pressed" and nothing
    // at all about the grid that just changed underneath them.
    expect(result.attributes('aria-live')).toBe('polite')
    expect(result.text()).toBe('Showing all 4 products.')

    await wrapper.findAll('.fchub-filter')[2].trigger('click')

    expect(wrapper.findAll('.fchub-card')).toHaveLength(1)
    expect(wrapper.text()).toContain('Memberships')
    expect(wrapper.findAll('.fchub-filter')[2].attributes('aria-pressed')).toBe('true')
    expect(wrapper.findAll('.fchub-filter')[0].attributes('aria-pressed')).toBe('false')
    expect(result.text()).toBe('Showing 1 of 4 products.')

    wrapper.unmount()
  })

  it('announces an empty result rather than swapping the grid out in silence', async () => {
    const wrapper = await open('/products', ok(CALM))

    await wrapper.findAll('.fchub-filter')[2].trigger('click')

    expect(wrapper.get('[data-filter-result]').text()).toBe('No products match this filter.')
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('explains an empty filter instead of showing a blank page', async () => {
    const wrapper = await open('/products', ok(CALM))

    await wrapper.findAll('.fchub-filter')[2].trigger('click')

    expect(wrapper.text()).toContain('No updates waiting.')
    expect(complaints).toEqual([])

    wrapper.unmount()
  })
})

describe('focus after an action that moves the card', () => {
  it('does not drop a keyboard user at the top of wp-admin', async () => {
    const wrapper = await open('/', ok(DISCOVERY))

    // Install-and-activate moves this product out of "The rest of the suite"
    // and into "On this site", so Vue unmounts the very card that was pressed
    // and its own focus watcher never runs. The shell has to catch it.
    const install = wrapper.get('[data-action="install-and-activate"]')

    install.element.focus()
    expect(document.activeElement).toBe(install.element)

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(ok(DISCOVERY_INSTALLED)))

    await install.trigger('click')
    await flushPromises()
    await nextTick()

    expect(wrapper.find('[data-action="install-and-activate"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('On this site')
    expect(document.activeElement).not.toBe(document.body)
    expect(document.activeElement).toBe(wrapper.get('main').element)
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('does the same on Products when a filter drops the card', async () => {
    const wrapper = await open('/products', ok(BUSY))

    await wrapper.findAll('.fchub-filter')[2].trigger('click')

    const update = wrapper.get('[data-action="update"]')

    update.element.focus()

    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        ok({
          ...BUSY,
          products: BUSY.products.map((entry) =>
            entry.slug === 'fchub-memberships'
              ? { ...entry, installed_version: '1.4.0', update: 'current', actions: ['deactivate'] }
              : entry,
          ),
          summary: { active: 2, updates: 0, compatibility_issues: 2 },
          notice: 'Memberships is now on 1.4.0.',
        }),
      ),
    )

    await update.trigger('click')
    await flushPromises()
    await nextTick()

    // The Updates filter is still on, and nothing matches it any more.
    expect(wrapper.find('[data-action="update"]').exists()).toBe(false)
    expect(document.activeElement).toBe(wrapper.get('main').element)
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('does not grab focus for somebody who never had it', async () => {
    const wrapper = await open('/', ok(DISCOVERY))

    // Safari on macOS does not focus a button on click, so activeElement is
    // already <body> when a mouse user presses Install. Pulling their
    // screen-reader cursor into <main> uninvited is not a courtesy.
    document.body.focus()
    expect(document.activeElement).toBe(document.body)

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(ok(DISCOVERY_INSTALLED)))

    await wrapper.get('[data-action="install-and-activate"]').trigger('click')
    await flushPromises()
    await nextTick()

    expect(wrapper.text()).toContain('On this site')
    expect(document.activeElement).toBe(document.body)
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('moves focus once, not twice, when the card survives but its button does not', async () => {
    const wrapper = await open('/', ok(BUSY))

    // A successful update: the card stays where it is, but the primary button
    // becomes an "Open settings" link. Both handlers are live for this one —
    // the card's restoreFocus() and the page's <main> safety net — and if both
    // fire, a screen reader announces <main> and then the card heading.
    const update = wrapper.get('[data-action="update"]')

    update.element.focus()

    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        ok({
          ...BUSY,
          products: BUSY.products.map((entry) =>
            entry.slug === 'fchub-memberships'
              ? { ...entry, installed_version: '1.4.0', update: 'current', actions: ['deactivate'] }
              : entry,
          ),
          summary: { active: 2, updates: 0, compatibility_issues: 2 },
          notice: 'Memberships is now on 1.4.0.',
        }),
      ),
    )

    await update.trigger('click')
    await flushPromises()
    await nextTick()
    await nextTick()

    const card = wrapper
      .findAll('.fchub-card')
      .find((entry) => entry.text().includes('Memberships'))

    // The card's own watcher wins: it restores focus before the page looks, so
    // the page's `activeElement === body` condition is already false.
    expect(document.activeElement).toBe(card.get('[data-card-heading]').element)
    expect(document.activeElement).not.toBe(wrapper.get('main').element)
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('leaves focus alone when the card survived and handled it itself', async () => {
    const wrapper = await open('/products', ok(BUSY))

    const update = wrapper.get('[data-action="update"]')

    update.element.focus()

    // A failed update changes nothing, so the button is still there and the
    // card's own watcher is the one that should put focus back on it.
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: false,
        status: 502,
        json: () =>
          Promise.resolve({ success: false, code: 'package_unavailable', message: 'Not today.' }),
      }),
    )

    await update.trigger('click')
    await flushPromises()
    await nextTick()

    expect(document.activeElement).toBe(wrapper.get('[data-action="update"]').element)
    expect(wrapper.get('[role="alert"]').text()).toContain('Not today.')

    wrapper.unmount()
  })
})

describe('the System page', () => {
  it('says what this site actually runs', async () => {
    const wrapper = await open('/system', ok(BUSY))
    const text = wrapper.text()

    expect(text).toContain('This site')
    expect(text).toContain('WordPress')
    expect(text).toContain('6.5')
    expect(text).toContain('PHP')
    expect(text).toContain('8.1.2')
    expect(text).toContain('Version 1.2.3')
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('says so plainly when FluentCart is not running here', async () => {
    const wrapper = await open('/system', ok({ ...CALM, site: { ...CALM.site, fluentcart: null } }))

    expect(wrapper.text()).toContain('Not running on this site')

    wrapper.unmount()
  })

  it('files a non-FluentCart dependency under Other requirements, not under FluentCart', async () => {
    const wrapper = await open(
      '/system',
      ok({
        ...CALM,
        products: [
          product({
            slug: 'fchub-portal-extender',
            name: 'Portal Extender',
            lifecycle: 'not_installed',
            update: 'unknown',
            installed_version: null,
            admin_url: null,
            compatibility: 'unknown',
            compatibility_reason: {
              requirement: 'dependency',
              required: 'fluentcommunity',
              current: null,
            },
            actions: [],
          }),
        ],
        summary: { active: 0, updates: 0, compatibility_issues: 1 },
      }),
    )

    // Scoped to the Compatibility panel: "This site" has its own FluentCart
    // row, which reports the version rather than what is waiting on it.
    const rows = compatibilityRows(wrapper)
    const fluentCart = rows.find((row) => row.get('dt').text() === 'FluentCart')
    const other = rows.find((row) => row.get('dt').text() === 'Other requirements')

    // Every dependency failure carries requirement: 'dependency', so a row keyed
    // on the requirement kind would file this one under a heading saying
    // FluentCart.
    expect(fluentCart.text()).toContain('Nothing is waiting on FluentCart.')
    expect(fluentCart.text()).not.toContain('fluentcommunity')
    expect(other).toBeTruthy()
    expect(other.text()).toContain('Portal Extender needs fluentcommunity')
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('still files a FluentCart dependency under FluentCart', async () => {
    const wrapper = await open(
      '/system',
      ok({
        ...CALM,
        products: [
          product({
            slug: 'fchub-p24',
            name: 'Przelewy24',
            compatibility: 'blocked',
            compatibility_reason: {
              requirement: 'dependency',
              required: 'fluentcart',
              current: null,
            },
            actions: ['deactivate'],
          }),
        ],
        summary: { active: 1, updates: 0, compatibility_issues: 1 },
      }),
    )

    const rows = compatibilityRows(wrapper)
    const fluentCart = rows.find((row) => row.get('dt').text() === 'FluentCart')

    expect(fluentCart.text()).toContain('Przelewy24 needs FluentCart installed and active first.')
    expect(rows.find((row) => row.get('dt').text() === 'Other requirements')).toBeUndefined()

    wrapper.unmount()
  })

  it('explains a missing capability instead of leaving buttons unaccounted for', async () => {
    const wrapper = await open(
      '/system',
      ok({ ...CALM, capabilities: { install: false, activate: true, update: true } }),
    )

    expect(wrapper.text()).toContain('Your account cannot install products on this site.')

    wrapper.unmount()
  })

  it('stays quiet about capabilities when nothing is withheld', async () => {
    const wrapper = await open('/system', ok(CALM))

    expect(wrapper.text()).not.toContain('Your account')
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('translates the catalogue source into English', async () => {
    const wrapper = await open('/system', ok(BUSY))

    expect(wrapper.text()).toContain('Using the catalogue included with FCHub')
    // last_refresh is the last *successful* check, so it stands alongside a
    // bundled catalogue rather than describing the age of what is shown.
    expect(wrapper.text()).toContain('Last checked')
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('says which requirements this site is failing, and which it is not', async () => {
    const wrapper = await open('/system', ok(BUSY))
    const text = wrapper.text()

    expect(text).toContain('Nothing is waiting on a newer WordPress.')
    expect(text).toContain('Wishlist needs PHP 8.3. This site runs 8.1.2.')
    expect(text).toContain('Multi-Currency needs FluentCart, which FCHub cannot check here.')
    expect(text).toContain('FCHub')
    expect(text).not.toContain('[object Object]')
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('offers a manual refresh that posts to the catalogue route', async () => {
    const wrapper = await open('/system', ok(BUSY))

    await wrapper.get('.fchub-panel__actions .fchub-button').trigger('click')
    await flushPromises()

    expect(fetch).toHaveBeenLastCalledWith(
      'https://example.com/wp-json/fchub/v1/catalogue/refresh',
      expect.objectContaining({ method: 'POST' }),
    )

    wrapper.unmount()
  })
})

describe('when the very first read fails', () => {
  it('announces it and offers another go, rather than showing an empty page', async () => {
    const wrapper = await open('/', {
      ok: false,
      status: 503,
      json: () =>
        Promise.resolve({
          success: false,
          code: 'catalogue_unavailable',
          message: 'The FCHub catalogue could not be read. Reinstalling FCHub should sort it out.',
        }),
    })

    const alert = wrapper.get('[role="alert"]')

    expect(alert.attributes('aria-live')).toBe('assertive')
    expect(alert.text()).toContain('The FCHub catalogue could not be read.')
    expect(wrapper.text()).toContain('Try again')
    expect(complaints).toEqual([])

    wrapper.unmount()
  })

  it('keeps a live region in the document even with nothing to announce', async () => {
    const wrapper = await open('/', ok(CALM))

    expect(wrapper.get('[role="alert"]').exists()).toBe(true)
    expect(wrapper.get('[role="status"]').attributes('aria-live')).toBe('polite')

    wrapper.unmount()
  })

  it('parks focus somewhere sensible once a retry works', async () => {
    const wrapper = await open('/', {
      ok: false,
      status: 503,
      json: () => Promise.resolve({ success: false, code: 'catalogue_unavailable', message: 'Nope.' }),
    })

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(ok(CALM)))

    const retry = wrapper.get('.fchub-button--primary')

    retry.element.focus()
    await retry.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Everything is ticking along nicely.')
    // The button it was pressed on no longer exists; <main> catches the focus
    // rather than letting it fall back to the top of wp-admin.
    expect(document.activeElement).toBe(wrapper.get('main').element)

    wrapper.unmount()
  })
})

describe('document structure', () => {
  // All three routes, not just the Overview. System grows its own h2 -> h3
  // panel tree and Products has a heading the cards nest under; one route
  // proving itself says nothing whatsoever about the other two.
  for (const route of ['/', '/products', '/system']) {
    it(`gives ${route} exactly one h1 and no gaps below it`, async () => {
      const wrapper = await open(route, ok(BUSY))
      const levels = wrapper
        .findAll('h1, h2, h3, h4')
        .map((heading) => Number(heading.element.tagName.slice(1)))

      expect(levels.filter((level) => level === 1)).toHaveLength(1)
      expect(levels[0]).toBe(1)
      expect(levels.length, 'the route rendered no headings at all').toBeGreaterThan(1)

      for (let index = 1; index < levels.length; index += 1) {
        expect(levels[index] - levels[index - 1]).toBeLessThanOrEqual(1)
      }

      wrapper.unmount()
    })
  }
})
