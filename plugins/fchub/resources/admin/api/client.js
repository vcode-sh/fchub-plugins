/**
 * The only thing in this interface that talks to anything. It talks to the
 * FCHub REST namespace and nowhere else: no analytics, no CDN, no third-party
 * anything. A control plane that phones home would be a slightly different
 * product.
 */

const FALLBACK_MESSAGE = 'WordPress could not complete that action.'

/**
 * Read the injected config on every call rather than once at module load.
 * The bundle is a module, so its evaluation order relative to the inline
 * script is WordPress's business, not ours.
 */
function config() {
  return window.fchubAdmin || {}
}

function baseUrl() {
  return String(config().rest_url || '/wp-json/fchub/v1/').replace(/\/+$/, '')
}

/**
 * There are two error shapes on this wire and both end up here as one.
 * WordPress core answers a rejected nonce or capability with
 * `{code, message, data:{status}}`; FCHub answers everything else with
 * `{success, code, message, product}`. Both carry `code` and `message`, which
 * is the only part the interface ever needs.
 */
function failure(body) {
  // Checked for type rather than truthiness: `new Error()` String()-coerces
  // whatever it is handed, so an object here would reach a banner as
  // "[object Object]" — which is a sentence, technically.
  const message = typeof body?.message === 'string' && body.message !== ''
    ? body.message
    : FALLBACK_MESSAGE

  const error = new Error(message)

  error.code = typeof body?.code === 'string' && body.code !== '' ? body.code : 'request_failed'

  // Three failures are thrown after files are already on disk — a failed
  // install, a failed activation, a version nobody ordered — and those answers
  // carry `state`: the success envelope minus its notice. Dropping it on the
  // floor is how a screen goes on insisting a plugin sitting in
  // wp-content/plugins/ is not installed, right up until the next click is
  // refused for being already installed.
  if (Array.isArray(body?.state?.products)) {
    error.state = body.state
  }

  return error
}

async function request(method, path) {
  let response

  try {
    response = await fetch(`${baseUrl()}/${path}`, {
      method,
      headers: {
        'X-WP-Nonce': config().nonce || '',
        Accept: 'application/json',
      },
    })
  } catch {
    // fetch() itself rejected: offline, DNS gone, the site restarting
    // mid-request. The browser's own wording for that is "Failed to fetch" in
    // Chrome and "NetworkError when attempting to fetch resource." in Firefox
    // — untranslated, off-voice, and exactly the raw transport detail this
    // interface is not supposed to show anybody.
    throw failure(null)
  }

  // A 500 from a plugin conflict is frequently an HTML page rather than JSON,
  // and "Unexpected token <" is not a sentence anybody wants to read.
  const body = await response.json().catch(() => null)

  if (!response.ok) {
    throw failure(body)
  }

  return body
}

export const restClient = {
  products: () => request('GET', 'products'),
  refreshCatalogue: () => request('POST', 'catalogue/refresh'),
  runAction: (slug, action) => request('POST', `products/${slug}/${action}`),
}
