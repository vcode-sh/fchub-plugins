# Issue #72 post-mortem — guests could not keep a currency, and every fix made it slower

Closed 19 August 2026 with [v1.4.8](https://github.com/vcode-sh/fchub-plugins/releases/tag/fchub-multi-currency/v1.4.8),
confirmed in production by the reporter: instant switching, both auth states,
choice survives checkout and full browser restarts. This document records what
was actually wrong, why four releases of fixes made it worse, and which tests
now pin every guarantee so the next refactor cannot quietly undo them.

## What the visitor experienced

- 1.4.0: logged-out visitors picked a currency and watched it snap back to the
  default. Logged-in worked. (Report, 9 August.)
- 1.4.5–1.4.6: switching worked once, then locked; or worked only until the
  next cached page.
- 1.4.7: switching "worked" but took 11–12 seconds per change and flashed a
  dimmed intermediate state, twice. The reporter stayed on 1.4.4.1.
- 1.4.8: click to converted prices in under 10 ms, zero server requests on a
  cached arrival.

## Root cause — one sentence

The server was answering a question only the browser can answer: **"which
currency does the visitor looking at this page want?"** — and every answer it
baked into HTML was cached by the edge and served to somebody else.

## Why four releases of fixes failed

Each release treated a symptom of server-owned resolution instead of ending it:

1. **1.4.2–1.4.4** trusted the cookie. Rocket.net's edge does not reliably
   forward cookies to the origin and never varies cache on them, so the origin
   kept resolving strangers' preferences. The one thing the reporter's week of
   debugging had already proven unreliable was the thing the fix leaned on.
2. **1.4.5** validated the cookie harder. Same channel, same fate.
3. **1.4.6** added browser storage plus a cache-busted *recovery request*: every
   page load asked the server "what should this page have said?" and repaired
   the HTML afterwards. Correctness arrived, carrying a per-page-load GET, a
   cross-file lock, two stylesheets that optimizers stripped or deferred, and a
   correction window a visitor could watch.
4. **1.4.7** tuned that window and shipped an 11–12 s regression on real
   hosting: the recovery protocol's moving parts (lock spanning three scripts,
   late stylesheets, head-before-DOM ordering) degraded worst exactly where
   caching was most aggressive — the sites the plugin exists for.

The pattern worth remembering: when a fix keeps needing another fix in the same
place, the architecture is wrong, not the code. Four attempts at "make
server-owned resolution survive caches" lost to one attempt at "stop the server
resolving".

## The 1.4.8 architecture

- The server renders **identical bytes for every visitor**: base-currency
  markup plus the full rate table and translated templates, inline in the head.
  Nothing per-visitor may enter cacheable HTML — no resolved currency, no
  guest nonce, no "active" marker on a switcher option.
- The **browser resolves** (URL param → stored → account → cookie → default →
  base) before first paint, shields prices only when a conversion is coming
  (bounded to 2 s by a one-shot CSS animation), and projects prices in place.
- A switch is **local**: re-project from originals, sync labels, announce to
  screen readers. The POST that follows is fire-and-forget bookkeeping for the
  surfaces the browser cannot reach (order meta, CRM, emails); its failure
  never un-switches a correct display.
- The one server-decided path left is the no-JS form, which is also the only
  one reading POST data — and its guest path survives a cached-out nonce,
  because the equivalent operations were already nonce-free by design.

## Lessons learned

1. **Cache-safety is an invariant, not a feature.** Anything per-visitor in
   cacheable output is a defect even when no cache is configured today.
   Enforced by tests, below.
2. **Test doubles must be shaped like the dependency actually ships.** Two
   integrations (CRM order sync, snapshot fallback) were dead in production for
   months while 430 tests stayed green, because mocks carried an
   `$order->user_id` that FluentCart orders never had. Verify mock shapes
   against the real source in `fchub-playground/wp-content/plugins/fluent-cart`.
3. **Verify against the dependency's source, not its documentation or our
   memory.** The checkout-fragments filter corrupted FluentCart's checkout for
   every converted-currency visitor; the price-filter projection corrupted its
   filtering. Both were "obviously fine" until read against FluentCart 1.6.1.
4. **Silent failure is the twin of silent success.** WordPress silently prunes
   active plugins with missing files; our nonce check silently swallowed guest
   submissions; the fragments crash silently stopped checkout updates. Paths
   that can fail must say so somewhere a person will look.
5. **Measure on hostile infrastructure.** The 1.4.7 regression was invisible
   locally and obvious behind a slow origin plus fast edge. The hermetic
   hostile-cache Playwright lane exists so that environment is simulated on
   every run.
6. **The reporter was the best instrument we had.** ManniGH reproduced on
   Rocket.net, captured request timelines, tested every release in production,
   and twice corrected our diagnosis. Take outside evidence seriously the
   first time.

## Regression map — which test pins which guarantee

All paths relative to `plugins/fchub-multi-currency/`.

| Guarantee (what 1.4.8 promises) | Pinned by |
|---|---|
| Cached page arrival makes zero requests | `tests/e2e/switch-journey.spec.mjs` — "a cached page load asks the server for nothing" |
| Click → converted, stable prices inside 1 s budget, no reload | `tests/e2e/switch-journey.spec.mjs` — journey tests across keyed/bypassed/ignored cache modes |
| Served HTML is byte-identical whatever the visitor's cookie | `tests/Unit/Frontend/CurrencyTablePayloadTest.php` — identical-table tests; `CurrencyBlockFamilyTest.php` — "a cached block must not name one visitor" (both block families) |
| No guest nonce, no per-visitor config in cacheable pages | `tests/Unit/Bootstrap/FrontendModuleTest.php` — guest nonce withheld; fresh-store defaults |
| Resolution order: URL → stored → account → cookie → default → base | `tests/js/currency-bootstrap.test.mjs` |
| Choice survives browser restart (storage + expiry honoured) | `tests/js/currency-bootstrap.test.mjs` — stored-preference and expiry tests |
| Switch works with projection disabled; labels never paint "undefined" | `tests/js/currency-context-load.test.mjs` — load-paint tests |
| Chosen currency rides FluentCart's checkout form into order meta | `tests/js/currency-context-load.test.mjs` — "checkout field carries the browser's currency" (paint + capture-phase submit); `tests/Unit/Integration/OrderSnapshotCheckoutCaptureTest.php` — server-side validation and snapshot |
| FluentCart's checkout fragments stay a sequential list | `tests/Unit/Integration/CheckoutFragmentsContractTest.php` |
| FluentCart's price filter keeps working (never projected) | `tests/js/projection-behaviour-runtime.test.mjs` — filter-inputs-stay-base test |
| Order's WP user read through the customer relation | `tests/Unit/Integration/FluentCrmSyncTest.php`, `OrderSnapshotHooksTest.php` — FluentCart-shaped order fixtures |
| Shield always lifts; critical CSS ships with the page | `tests/js/critical-css.test.mjs` (selector parity); `tests/e2e/switch-journey.spec.mjs` — critical-window CSS orphans |
| Repeated switches convert from originals, never compound | `tests/js/projection-reprojection.test.mjs` |
| Observer idles for base-currency visitors and irrelevant mutations | `tests/js/projection-observer.test.mjs` |
| Keyboard operation of the switcher (combobox pattern) | `tests/js/currency-switcher-keyboard.test.mjs` |
| Screen readers hear the switch | announcer assertions in `tests/js/currency-switcher-runtime.test.mjs` and the live-region markup tests |
| Rate-freshness badge tells the truth on aged caches | `tests/js/rate-badge.test.mjs` |
| REST persist: guests admitted without nonce, attempts rate-limited with an unforgeable backstop | `tests/Unit/Http/Controllers/Pub/RateLimitTest.php`, `RateLimiterIpTest.php`, `ContextControllerTest.php` |
| Guest no-JS form survives a cached-out nonce; logged-in still requires one | `tests/Unit/Frontend/NoscriptCurrencyFormTest.php` |

Run everything with: `composer test && composer analyse && composer lint &&
npm test && npx playwright test` from the plugin directory. The Playwright lane
simulates the hostile edge (slow origin, fast cache, all three query-string
modes) hermetically — no network, no live site needed.

## If you change the architecture

Re-read the invariant first: identical bytes for every visitor, browser owns
resolution, POST is bookkeeping. Any change that makes the server answer a
per-visitor question in cacheable output reopens this issue, whatever the
benchmark says. The tests above are the tripwires; a red one is this document
knocking.
