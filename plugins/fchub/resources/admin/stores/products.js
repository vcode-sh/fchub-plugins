import { reactive } from 'vue'
import { restClient } from '../api/client.js'

/**
 * One store, holding exactly what the server last said and nothing it worked
 * out for itself. After a mutation the whole envelope is replaced — no
 * optimistic patching, no locally recomputed counts. The server knows what is
 * installed on this site; the browser is guessing, and a guess that disagrees
 * with the Plugins screen is worse than a slightly slower refresh.
 */

const EMPTY_SUMMARY = { active: 0, updates: 0, compatibility_issues: 0 }
const EMPTY_CATALOGUE = { source: null, last_refresh: null }
const EMPTY_SITE = { wp: null, php: null, fluentcart: null }
const NO_CAPABILITIES = { install: false, activate: false, update: false }
const FALLBACK_MESSAGE = 'WordPress could not complete that action.'

/**
 * The fallback for `refresh_failed_after_operation` only. That branch exists
 * precisely because the action *did* complete, so telling the customer nothing
 * happened would be the one thing that is definitely untrue.
 */
const REFRESH_FAILED_MESSAGE =
  'That worked, but FCHub could not refresh what it shows. Reloading should sort it out.'

/**
 * The one message this interface writes for WordPress rather than repeating
 * after it. Core answers a stale nonce with "Cookie check failed", which is
 * accurate, four words long, and means nothing whatsoever to somebody who has
 * never read the REST API handbook.
 *
 * It is also the only failure with no route out on the page: the nonce is baked
 * into the markup when WordPress renders it, so every button afterwards fails
 * identically until the page is reloaded. Saying so is the entire fix.
 */
const EXPIRED_SESSION_CODE = 'rest_cookie_invalid_nonce'

const EXPIRED_SESSION_MESSAGE =
  'Your WordPress login session expired before the request arrived, so nothing was changed. ' +
  'Reload the page and try again.'

/** Platforms FCHub can name properly. Anything else is printed as it arrived. */
const DEPENDENCY_NAMES = {
  fluentcart: 'FluentCart',
}

const state = reactive({
  products: [],
  summary: { ...EMPTY_SUMMARY },
  catalogue: { ...EMPTY_CATALOGUE },
  /** What this site runs: `{wp, php, fluentcart}`, straight from the server. */
  site: { ...EMPTY_SITE },
  capabilities: { ...NO_CAPABILITIES },
  /** Slug -> the action id currently in flight for it. */
  actionPending: {},
  /** `{tone: 'success'|'warning', message}` or nothing. */
  notice: null,
  /** One recoverable failure, `{code, message}`, in the server's own words. */
  error: null,
  loading: false,
  refreshing: false,
  ready: false,
})

/**
 * Turns the compatibility reason — an object, never renderable as one — into a
 * sentence. Task 4's `compatibility_reason` names the failed requirement and,
 * where it can, the version this site actually has.
 *
 * It lives here rather than in a component because three different screens ask
 * the same question of the same server data, and three answers would
 * eventually be three different answers.
 *
 * @param {object} product
 * @returns {string|null} null when the product runs here perfectly well.
 */
export function compatibilitySentence(product) {
  if (!product || product.compatibility === 'compatible') {
    return null
  }

  const name = product.name || 'This product'
  const unverifiable = `${name} has a requirement FCHub cannot check here, so it was left alone.`
  const reason = product.compatibility_reason

  if (!reason || typeof reason !== 'object') {
    return unverifiable
  }

  const required = typeof reason.required === 'string' ? reason.required : null
  const current = typeof reason.current === 'string' ? reason.current : null

  if (!required) {
    return unverifiable
  }

  if (reason.requirement === 'php') {
    return current
      ? `${name} needs PHP ${required}. This site runs ${current}.`
      : `${name} needs PHP ${required}.`
  }

  if (reason.requirement === 'wp') {
    return current
      ? `${name} needs WordPress ${required}. This site runs ${current}.`
      : `${name} needs WordPress ${required}, and FCHub cannot read this site’s version.`
  }

  if (reason.requirement === 'dependency') {
    const platform = DEPENDENCY_NAMES[required] || required

    return product.compatibility === 'unknown'
      ? `${name} needs ${platform}, which FCHub cannot check here.`
      : `${name} needs ${platform} installed and active first.`
  }

  return unverifiable
}

/**
 * Replaces the whole picture with the server's. A response carrying `products`
 * is a full envelope. A 2xx carrying only a `code` is
 * `refresh_failed_after_operation`: the mutation landed and only the refreshed
 * view is missing, so it is a warning, not a failure.
 *
 * `describesCatalogue` says whether the notice on this envelope is *about* the
 * catalogue. Only the refresh route produces one of those, and only then does
 * `catalogue.source` decide the tone — see noticeTone().
 */
function apply(payload, { describesCatalogue = false } = {}) {
  if (!payload) {
    return
  }

  if (Array.isArray(payload.products)) {
    state.products = payload.products
    // Merged onto the empty shapes rather than assigned over them: a partial
    // envelope should leave a count reading 0, not `undefined`, which renders
    // as an interesting sentence about how many products cannot run here.
    state.summary = { ...EMPTY_SUMMARY, ...(payload.summary || {}) }
    state.catalogue = { ...EMPTY_CATALOGUE, ...(payload.catalogue || {}) }
    state.site = { ...EMPTY_SITE, ...(payload.site || {}) }
    state.capabilities = { ...NO_CAPABILITIES, ...(payload.capabilities || {}) }
    state.notice = payload.notice
      ? { tone: noticeTone(describesCatalogue, state.catalogue.source), message: payload.notice }
      : null
    state.ready = true

    return
  }

  if (payload.code) {
    state.notice = { tone: 'warning', message: sentence(payload.message, REFRESH_FAILED_MESSAGE) }
  }
}

/**
 * Green is a claim, not a decoration.
 *
 * A refresh notice is *about* the catalogue, so its tone follows the source it
 * ended up on: "The catalogue could not be reached, so this is the copy that
 * shipped with FCHub" is not good news and must not be painted as though it
 * were. The refresh is the only route that produces such a notice.
 *
 * Deliberately narrower than "any notice arriving with a non-remote source".
 * A mutation notice describes the mutation, not the catalogue — an offline site
 * runs permanently on the bundled copy, and painting "Memberships is switched
 * on." amber there would make every successful action look like a problem.
 */
function noticeTone(describesCatalogue, source) {
  return describesCatalogue && source !== 'remote' ? 'warning' : 'success'
}

/**
 * A string or the fallback, never whatever arrived. The contract always sends
 * a message, but a banner with no words and a lone Dismiss button is a poor way
 * to find out that it stopped.
 *
 * The fallback is a parameter because the default one says nothing was done,
 * and there is one branch — the post-mutation refresh failure — where something
 * very much was.
 */
function sentence(value, fallback = FALLBACK_MESSAGE) {
  return typeof value === 'string' && value !== '' ? value : fallback
}

function record(error) {
  const code = typeof error.code === 'string' && error.code !== '' ? error.code : 'request_failed'

  state.error = {
    code,
    message: code === EXPIRED_SESSION_CODE ? EXPIRED_SESSION_MESSAGE : sentence(error.message),
  }

  // A failure that changed the site anyway sends the refreshed picture with it.
  // Applying it is not optimism about the operation — the banner above still
  // says it failed — it is refusing to describe a site that no longer exists.
  if (error.state) {
    apply(error.state)
  }
}

async function load() {
  // Same re-entrancy rule as refreshCatalogue(): the retry button says
  // aria-disabled while this runs, and a promise the code does not keep is
  // worse than no promise at all.
  if (state.loading) {
    return
  }

  state.loading = true
  state.error = null

  try {
    apply(await restClient.products())
  } catch (error) {
    record(error)
  } finally {
    state.loading = false
  }
}

async function refreshCatalogue() {
  if (state.refreshing) {
    return
  }

  state.refreshing = true
  state.error = null
  state.notice = null

  try {
    apply(await restClient.refreshCatalogue(), { describesCatalogue: true })
  } catch (error) {
    record(error)
  } finally {
    state.refreshing = false
  }
}

async function runAction(slug, action) {
  // Two installs of the same product, racing, is not a feature.
  if (state.actionPending[slug]) {
    return
  }

  state.actionPending[slug] = action
  state.error = null
  state.notice = null

  try {
    apply(await restClient.runAction(slug, action))
  } catch (error) {
    record(error)
  } finally {
    delete state.actionPending[slug]
  }
}

function dismissNotice() {
  state.notice = null
}

function dismissError() {
  state.error = null
}

const store = Object.assign(state, {
  load,
  refreshCatalogue,
  runAction,
  dismissNotice,
  dismissError,
})

export function useProductsStore() {
  return store
}
