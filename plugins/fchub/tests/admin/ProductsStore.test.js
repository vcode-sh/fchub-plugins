import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const REST = 'https://example.com/wp-json/fchub/v1'

/**
 * These tests drive the store through a stubbed `fetch` rather than a stubbed
 * client. The URL, the method and the headers are the contract with Task 4's
 * routes; a mocked client would let all three drift and still go green.
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

function envelope(overrides = {}) {
  return {
    products: [
      product(),
      product({ slug: 'fchub-fakturownia', name: 'Fakturownia' }),
      product({ slug: 'fchub-portal-extender', name: 'Portal Extender' }),
      product({ slug: 'fchub-wishlist', name: 'Wishlist' }),
      product({
        slug: 'fchub-memberships',
        name: 'Memberships',
        version: '1.4.0',
        installed_version: '1.3.0',
        update: 'available',
        actions: ['update', 'deactivate'],
      }),
      product({
        slug: 'fchub-multi-currency',
        name: 'Multi-Currency',
        lifecycle: 'not_installed',
        installed_version: null,
        admin_url: null,
        actions: ['install', 'install-and-activate'],
      }),
    ],
    summary: { active: 5, updates: 1, compatibility_issues: 0 },
    catalogue: { source: 'remote', last_refresh: '2026-07-24T09:00:00+00:00' },
    site: { wp: '6.7', php: '8.3.0', fluentcart: '1.2.3' },
    capabilities: { install: true, activate: true, update: true },
    ...overrides,
  }
}

function jsonResponse(body, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  }
}

function unreadableResponse(status) {
  return {
    ok: false,
    status,
    json: () => Promise.reject(new SyntaxError('Unexpected token < in JSON')),
  }
}

let store
let fetchMock

async function freshStore() {
  vi.resetModules()
  const module = await import('../../resources/admin/stores/products.js')

  return module.useProductsStore()
}

beforeEach(async () => {
  fetchMock = vi.fn()
  vi.stubGlobal('fetch', fetchMock)
  store = await freshStore()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('the products store, before anything has happened', () => {
  it('starts empty, calm, and with nothing pending', () => {
    expect(store.products).toEqual([])
    expect(store.summary).toEqual({ active: 0, updates: 0, compatibility_issues: 0 })
    expect(store.catalogue).toEqual({ source: null, last_refresh: null })
    expect(store.site).toEqual({ wp: null, php: null, fluentcart: null })
    expect(store.capabilities).toEqual({ install: false, activate: false, update: false })
    expect(store.actionPending).toEqual({})
    expect(store.error).toBeNull()
    expect(store.notice).toBeNull()
    expect(store.ready).toBe(false)
  })
})

describe('load()', () => {
  it('reads the products route with the WordPress nonce', async () => {
    fetchMock.mockResolvedValue(jsonResponse(envelope()))

    await store.load()

    expect(fetchMock).toHaveBeenCalledTimes(1)

    const [url, options] = fetchMock.mock.calls[0]

    expect(url).toBe(`${REST}/products`)
    expect(options.method).toBe('GET')
    expect(options.headers['X-WP-Nonce']).toBe('test-nonce')
    expect(options.headers.Accept).toBe('application/json')
  })

  it('keeps the summary the server sent, verbatim', async () => {
    fetchMock.mockResolvedValue(jsonResponse(envelope()))

    await store.load()

    expect(store.summary).toEqual({
      active: 5,
      updates: 1,
      compatibility_issues: 0,
    })
    expect(store.products).toHaveLength(6)
    expect(store.catalogue).toEqual({
      source: 'remote',
      last_refresh: '2026-07-24T09:00:00+00:00',
    })
    expect(store.capabilities).toEqual({ install: true, activate: true, update: true })
    expect(store.site).toEqual({ wp: '6.7', php: '8.3.0', fluentcart: '1.2.3' })
    expect(store.ready).toBe(true)
    expect(store.error).toBeNull()
  })

  it('reads once even if the retry button is pressed twice in a hurry', async () => {
    let settle
    fetchMock.mockReturnValue(
      new Promise((resolve) => {
        settle = () => resolve(jsonResponse(envelope()))
      }),
    )

    const first = store.load()
    await store.load()

    expect(fetchMock).toHaveBeenCalledTimes(1)

    settle()
    await first
  })

  it('explains an expired session instead of repeating “Cookie check failed”', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(
        {
          code: 'rest_cookie_invalid_nonce',
          message: 'Cookie check failed',
          data: { status: 403 },
        },
        403,
      ),
    )

    await store.load()

    // Core's own wording is accurate and useless: it is four words about a
    // cookie for something that is actually "you have been signed in too long",
    // and it is the one failure with no way out on the page — so the message
    // has to be the one that names the way out. The code is kept so the branch
    // stays identifiable.
    expect(store.error).toEqual({
      code: 'rest_cookie_invalid_nonce',
      message:
        'Your WordPress login session expired before the request arrived, so nothing was changed. Reload the page and try again.',
    })
    expect(store.error.message).not.toContain('Cookie check failed')
    expect(store.ready).toBe(false)
    expect(store.products).toEqual([])
  })

  it('falls back to one plain sentence when the body is not even JSON', async () => {
    fetchMock.mockResolvedValue(unreadableResponse(500))

    await store.load()

    expect(store.error).toEqual({
      code: 'request_failed',
      message: 'WordPress could not complete that action.',
    })
  })

  it('never lets a non-string message become “[object Object]” in a banner', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse({ code: { nested: 'nonsense' }, message: { also: 'nonsense' } }, 500),
    )

    await store.load()

    expect(store.error).toEqual({
      code: 'request_failed',
      message: 'WordPress could not complete that action.',
    })
    expect(store.error.message).not.toContain('[object Object]')
  })

  it('never leaves the interface loading after a failure', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

    await store.load()

    expect(store.loading).toBe(false)
    expect(store.error.message).toBeTruthy()
  })

  it('does not put the browser’s own network wording in front of a customer', async () => {
    // What Chrome throws when the site is unreachable. Firefox says
    // "NetworkError when attempting to fetch resource." Neither is translated,
    // neither is on-voice, and both are raw transport detail.
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

    await store.load()

    expect(store.error).toEqual({
      code: 'request_failed',
      message: 'WordPress could not complete that action.',
    })
    expect(store.error.message).not.toContain('Failed to fetch')
    expect(store.error.message).not.toContain('NetworkError')
  })
})

describe('runAction()', () => {
  beforeEach(async () => {
    fetchMock.mockResolvedValue(jsonResponse(envelope()))
    await store.load()
    fetchMock.mockClear()
  })

  it('posts to the exact route the action belongs to', async () => {
    fetchMock.mockResolvedValue(jsonResponse(envelope()))

    await store.runAction('fchub-memberships', 'update')

    const [url, options] = fetchMock.mock.calls[0]

    expect(url).toBe(`${REST}/products/fchub-memberships/update`)
    expect(options.method).toBe('POST')
    expect(options.headers['X-WP-Nonce']).toBe('test-nonce')
  })

  it('replaces the whole envelope with the server’s, rather than patching locally', async () => {
    const after = envelope({
      products: [
        product({
          slug: 'fchub-memberships',
          name: 'Memberships',
          version: '1.4.0',
          installed_version: '1.4.0',
          update: 'current',
          actions: ['deactivate'],
        }),
      ],
      summary: { active: 1, updates: 0, compatibility_issues: 0 },
      catalogue: { source: 'last_good', last_refresh: '2026-07-24T09:00:00+00:00' },
      capabilities: { install: false, activate: true, update: true },
      notice: 'Memberships is now on 1.4.0.',
    })

    fetchMock.mockResolvedValue(jsonResponse(after))

    await store.runAction('fchub-memberships', 'update')

    expect(store.products).toHaveLength(1)
    expect(store.products[0].installed_version).toBe('1.4.0')
    expect(store.summary).toEqual({ active: 1, updates: 0, compatibility_issues: 0 })
    expect(store.catalogue.source).toBe('last_good')
    expect(store.capabilities.install).toBe(false)
    expect(store.notice).toEqual({
      tone: 'success',
      message: 'Memberships is now on 1.4.0.',
    })
  })

  it('marks the product pending while the request is in flight and clears it after', async () => {
    let settle
    fetchMock.mockReturnValue(
      new Promise((resolve) => {
        settle = () => resolve(jsonResponse(envelope()))
      }),
    )

    const running = store.runAction('fchub-memberships', 'update')

    expect(store.actionPending).toEqual({ 'fchub-memberships': 'update' })

    settle()
    await running

    expect(store.actionPending).toEqual({})
  })

  it('ignores a second press while the first is still running', async () => {
    let settle
    fetchMock.mockReturnValue(
      new Promise((resolve) => {
        settle = () => resolve(jsonResponse(envelope()))
      }),
    )

    const running = store.runAction('fchub-memberships', 'update')
    await store.runAction('fchub-memberships', 'update')

    expect(fetchMock).toHaveBeenCalledTimes(1)

    settle()
    await running
  })

  it('keeps the server’s friendly message and leaves every product where it was', async () => {
    const before = JSON.parse(JSON.stringify(store.products))

    fetchMock.mockResolvedValue(
      jsonResponse(
        {
          success: false,
          code: 'product_incompatible',
          message: 'Memberships needs PHP 8.3 before it can be activated.',
          product: 'fchub-memberships',
        },
        409,
      ),
    )

    await store.runAction('fchub-memberships', 'update')

    expect(store.error).toEqual({
      code: 'product_incompatible',
      message: 'Memberships needs PHP 8.3 before it can be activated.',
    })
    expect(store.products).toEqual(before)
    expect(store.summary).toEqual({ active: 5, updates: 1, compatibility_issues: 0 })
    expect(store.actionPending).toEqual({})
  })

  it('takes the refreshed picture a failed-but-changed operation sends with it', async () => {
    const after = envelope({
      products: [
        product({
          slug: 'fchub-memberships',
          name: 'Memberships',
          version: '1.4.0',
          // The install landed at a version nobody ordered, which is precisely
          // what version_mismatch means.
          installed_version: '1.3.9',
          lifecycle: 'inactive',
          admin_url: null,
          update: 'available',
          actions: ['activate', 'update'],
        }),
      ],
      summary: { active: 0, updates: 1, compatibility_issues: 0 },
    })

    fetchMock.mockResolvedValue(
      jsonResponse(
        {
          success: false,
          code: 'version_mismatch',
          message:
            'Memberships installed, but the files are not the 1.4.0 release we expected. Worth a look on the Plugins screen.',
          product: 'fchub-memberships',
          state: after,
        },
        500,
      ),
    )

    await store.runAction('fchub-memberships', 'update')

    // The banner still says it failed. The cards stop insisting nothing
    // happened to a product that is now sitting on disk at the wrong version.
    expect(store.error.code).toBe('version_mismatch')
    expect(store.products).toHaveLength(1)
    expect(store.products[0].installed_version).toBe('1.3.9')
    expect(store.summary).toEqual({ active: 0, updates: 1, compatibility_issues: 0 })
    expect(store.notice).toBeNull()
    expect(store.ready).toBe(true)
  })

  it('leaves every product where it was when a failure carries no state at all', async () => {
    const before = JSON.parse(JSON.stringify(store.products))

    fetchMock.mockResolvedValue(
      jsonResponse(
        { success: false, code: 'package_unavailable', message: 'Nope.', product: 'fchub-p24' },
        502,
      ),
    )

    await store.runAction('fchub-p24', 'update')

    expect(store.products).toEqual(before)
    expect(store.summary).toEqual({ active: 5, updates: 1, compatibility_issues: 0 })
  })

  it('ignores a state that is not an envelope rather than emptying the screen', async () => {
    const before = JSON.parse(JSON.stringify(store.products))

    fetchMock.mockResolvedValue(
      jsonResponse(
        {
          success: false,
          code: 'installation_failed',
          message: 'WordPress could not install that package. No other product was touched.',
          product: 'fchub-p24',
          state: { products: 'not an array' },
        },
        500,
      ),
    )

    await store.runAction('fchub-p24', 'install')

    expect(store.products).toEqual(before)
    expect(store.error.code).toBe('installation_failed')
  })

  it('treats refresh_failed_after_operation as a success with a gentle warning', async () => {
    const before = JSON.parse(JSON.stringify(store.products))

    fetchMock.mockResolvedValue(
      jsonResponse({
        success: true,
        code: 'refresh_failed_after_operation',
        message:
          'That worked, but FCHub could not read its catalogue afterwards. A page reload should sort it out.',
        product: 'fchub-memberships',
      }),
    )

    await store.runAction('fchub-memberships', 'update')

    expect(store.error).toBeNull()
    expect(store.notice).toEqual({
      tone: 'warning',
      message:
        'That worked, but FCHub could not read its catalogue afterwards. A page reload should sort it out.',
    })
    expect(store.products).toEqual(before)
    expect(store.actionPending).toEqual({})
  })

  it('never claims nothing happened in the one branch where something did', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse({ success: true, code: 'refresh_failed_after_operation' }),
    )

    await store.runAction('fchub-memberships', 'update')

    expect(store.notice).toEqual({
      tone: 'warning',
      message: 'That worked, but FCHub could not refresh what it shows. Reloading should sort it out.',
    })
    // This branch exists precisely because the mutation succeeded.
    expect(store.notice.message).not.toContain('could not complete')
    expect(store.error).toBeNull()
  })

  it('clears a previous error once the next action succeeds', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse({ success: false, code: 'package_unavailable', message: 'Nope.' }, 502),
    )
    await store.runAction('fchub-memberships', 'update')
    expect(store.error).not.toBeNull()

    fetchMock.mockResolvedValue(jsonResponse(envelope({ notice: 'Memberships is now on 1.4.0.' })))
    await store.runAction('fchub-memberships', 'update')

    expect(store.error).toBeNull()
  })
})

describe('refreshCatalogue()', () => {
  it('posts to the catalogue route and applies the refreshed envelope', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(
        envelope({
          catalogue: { source: 'bundled', last_refresh: '2026-07-20T08:00:00+00:00' },
          notice: 'The catalogue could not be reached, so this is the copy that shipped with FCHub.',
        }),
      ),
    )

    await store.refreshCatalogue()

    const [url, options] = fetchMock.mock.calls[0]

    expect(url).toBe(`${REST}/catalogue/refresh`)
    expect(options.method).toBe('POST')
    expect(store.catalogue).toEqual({
      source: 'bundled',
      last_refresh: '2026-07-20T08:00:00+00:00',
    })
    expect(store.refreshing).toBe(false)
  })

  it('does not announce a catalogue it could not reach in the success tone', async () => {
    for (const source of ['bundled', 'last_good']) {
      fetchMock.mockResolvedValue(
        jsonResponse(
          envelope({
            catalogue: { source, last_refresh: '2026-07-20T08:00:00+00:00' },
            notice: 'The catalogue could not be reached, so this is the last copy FCHub trusted.',
          }),
        ),
      )

      await store.refreshCatalogue()

      expect(store.notice.tone, `${source} is not good news`).toBe('warning')
    }
  })

  it('does use the success tone when the refresh actually reached fchub.co', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(envelope({ catalogue: { source: 'remote', last_refresh: null }, notice: 'Catalogue refreshed.' })),
    )

    await store.refreshCatalogue()

    expect(store.notice).toEqual({ tone: 'success', message: 'Catalogue refreshed.' })
  })

  it('still calls a successful action a success on a site running the bundled copy', async () => {
    // The tone follows what the notice is *about*. An offline site runs on the
    // bundled catalogue permanently, and "Memberships is switched on." is not a
    // warning just because fchub.co is unreachable.
    fetchMock.mockResolvedValue(
      jsonResponse(
        envelope({
          catalogue: { source: 'bundled', last_refresh: null },
          notice: 'Memberships is switched on.',
        }),
      ),
    )

    await store.runAction('fchub-memberships', 'activate')

    expect(store.notice).toEqual({ tone: 'success', message: 'Memberships is switched on.' })
  })

  it('keeps the previous catalogue when the refresh itself fails', async () => {
    fetchMock.mockResolvedValue(jsonResponse(envelope()))
    await store.load()

    fetchMock.mockResolvedValue(
      jsonResponse(
        {
          success: false,
          code: 'catalogue_unavailable',
          message: 'The FCHub catalogue could not be read. Reinstalling FCHub should sort it out.',
        },
        503,
      ),
    )

    await store.refreshCatalogue()

    expect(store.catalogue.source).toBe('remote')
    expect(store.error.code).toBe('catalogue_unavailable')
    expect(store.refreshing).toBe(false)
  })
})

describe('compatibilitySentence()', () => {
  let sentence

  beforeEach(async () => {
    vi.resetModules()
    const module = await import('../../resources/admin/stores/products.js')
    sentence = module.compatibilitySentence
  })

  it('says nothing about a product that runs here perfectly well', () => {
    expect(sentence(product())).toBeNull()
  })

  it('names the PHP version the site is missing', () => {
    expect(
      sentence(
        product({
          name: 'Memberships',
          compatibility: 'blocked',
          compatibility_reason: { requirement: 'php', required: '8.3', current: '8.1.2' },
        }),
      ),
    ).toBe('Memberships needs PHP 8.3. This site runs 8.1.2.')
  })

  it('names the WordPress version the site is missing', () => {
    expect(
      sentence(
        product({
          name: 'Wishlist',
          compatibility: 'blocked',
          compatibility_reason: { requirement: 'wp', required: '6.7', current: '6.5' },
        }),
      ),
    ).toBe('Wishlist needs WordPress 6.7. This site runs 6.5.')
  })

  it('names the platform a product is waiting on', () => {
    expect(
      sentence(
        product({
          name: 'Przelewy24',
          compatibility: 'blocked',
          compatibility_reason: { requirement: 'dependency', required: 'fluentcart', current: null },
        }),
      ),
    ).toBe('Przelewy24 needs FluentCart installed and active first.')
  })

  it('admits when it simply cannot check', () => {
    expect(
      sentence(
        product({
          name: 'Wishlist',
          compatibility: 'unknown',
          compatibility_reason: { requirement: 'wp', required: '6.7', current: null },
        }),
      ),
    ).toBe('Wishlist needs WordPress 6.7, and FCHub cannot read this site’s version.')

    expect(
      sentence(
        product({
          name: 'Wishlist',
          compatibility: 'unknown',
          compatibility_reason: null,
        }),
      ),
    ).toBe('Wishlist has a requirement FCHub cannot check here, so it was left alone.')
  })

  it('never returns an object, whatever shape the reason arrives in', () => {
    const shapes = [
      undefined,
      null,
      'nonsense',
      { requirement: 'quantum-flux', required: '9', current: null },
      { requirement: 'php', required: null, current: null },
    ]

    for (const reason of shapes) {
      const result = sentence(product({ compatibility: 'blocked', compatibility_reason: reason }))

      expect(typeof result).toBe('string')
      expect(result).not.toContain('[object Object]')
      expect(result).not.toContain('undefined')
    }
  })
})
