/**
 * Everything WordPress would normally do for the FCHub interface, minus
 * WordPress: the injected config, the REST namespace, and the enqueued assets.
 *
 * The assets are the point. This file reads `assets/dist/.vite/manifest.json`
 * and injects whatever the last `npm run build` produced — the same lookup
 * AdminMenu::enqueueAssets() performs against the same manifest key. Hard-coding
 * the hashed filenames would mean the smoke suite silently testing a stale
 * bundle the moment anybody rebuilt, which is the exact failure this suite is
 * supposed to catch.
 *
 * Pick a state with
 * ?fixture=healthy|update|attention|incompatible|offline|failed-operation.
 */

/** The namespace Routes registers. Only requests to it are answered from a fixture. */
const REST_PATH = '/wp-json/fchub/v1/'

/** Kept before the stub replaces window.fetch, and used for everything on disk. */
const nativeFetch = window.fetch.bind(window)

const requested = new URLSearchParams(window.location.search).get('fixture') || 'healthy'

// A fixture name reaches the filesystem through the static host, so it gets the
// same treatment as any other untrusted path segment.
const name = requested.replace(/[^a-z0-9-]/g, '')

/** What the suite reads back to check the host did what it claims. */
window.__fchubSmoke = {
  fixture: name,
  /** Every REST call the interface made, in order. */
  requests: [],
  /** Calls this fixture does not describe. Any entry is a broken test. */
  unscripted: [],
  /** The built files this page actually loaded, from the manifest. */
  entry: null,
  styles: [],
}

const fixture = await nativeFetch(`../tests/e2e/fixtures/${name}.json`).then((response) => {
  if (!response.ok) {
    throw new Error(`No smoke fixture called "${name}".`)
  }

  return response.json()
})

/**
 * Byte-for-byte the shape AdminMenu prints, key order included. `rest_url` is
 * root-relative so a stub that fails to install hits the smoke host's own
 * /wp-json/ handler rather than wandering onto the network.
 */
window.fchubAdmin = {
  rest_url: REST_PATH,
  nonce: 'fchub-smoke-nonce',
  admin_url: '/wp-admin/',
  version: '1.0.0',
  locale: 'en_US',
}

function json(status, body) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

window.fetch = async (input, init = {}) => {
  const url = typeof input === 'string' ? input : String(input?.url ?? input)

  if (!url.includes(REST_PATH)) {
    return nativeFetch(input, init)
  }

  const method = String(init.method || 'GET').toUpperCase()
  const path = url.slice(url.indexOf(REST_PATH) + REST_PATH.length)

  window.__fchubSmoke.requests.push(`${method} ${path}`)

  if (method === 'GET' && path === 'products') {
    return json(200, fixture.snapshot)
  }

  const scripted = fixture.operations?.[path]

  if (scripted) {
    return json(scripted.status, scripted.body)
  }

  // Answering an undescribed mutation with a cheerful envelope would let a test
  // pass while asserting nothing. This is deliberately visible in the interface
  // and recorded for the suite to fail on.
  window.__fchubSmoke.unscripted.push(`${method} ${path}`)

  return json(500, {
    success: false,
    code: 'smoke_unscripted',
    message: `This smoke fixture does not describe ${method} ${path}.`,
    product: null,
  })
}

const manifest = await nativeFetch('../assets/dist/.vite/manifest.json').then((response) => {
  if (!response.ok) {
    throw new Error('No build to smoke-test. Run `npm run build` first.')
  }

  return response.json()
})

// The same key AdminMenu::ENTRY_KEY looks up.
const entry = manifest['resources/admin/main.js']

if (!entry) {
  throw new Error('The manifest has no entry for resources/admin/main.js.')
}

/** Awaited, so the first paint has the stylesheet and screenshots start settled. */
function stylesheet(href) {
  return new Promise((done, fail) => {
    const link = document.createElement('link')

    link.rel = 'stylesheet'
    link.href = href
    link.addEventListener('load', () => done(), { once: true })
    link.addEventListener('error', () => fail(new Error(`Could not load ${href}`)), { once: true })

    document.head.append(link)
  })
}

for (const file of entry.css || []) {
  window.__fchubSmoke.styles.push(`assets/dist/${file}`)

  await stylesheet(`../assets/dist/${file}`)
}

window.__fchubSmoke.entry = `assets/dist/${entry.file}`

const script = document.createElement('script')

script.type = 'module'
script.src = `../assets/dist/${entry.file}`

document.body.append(script)
