=== FCHub Multi-Currency ===
Contributors: vcodesh
Tags: fluentcart, currency, exchange-rates, ecommerce
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 1.4.4
Requires PHP: 8.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display FluentCart prices in customer-selected currencies while orders and payments remain in the store base currency.

== Description ==

FCHub Multi-Currency converts displayed FluentCart prices for browsing convenience. It does not change settlement: orders and payments remain in the store base currency, and checkout tells the customer which base currency will be charged.

Manual rates are the default. The plugin makes no exchange-rate request until an administrator selects and successfully saves a remote provider. Existing rate history remains available when a provider request fails.

= Features =

* Customer-selectable display currencies.
* Manual rates with no external rate request.
* Optional scheduled rates from the European Central Bank, ExchangeRate-API or Open Exchange Rates.
* Rate history and last-known-rate fallback.
* Checkout disclosure and order metadata recording the display currency and rate.
* WordPress Privacy Tools integration for stored customer preferences and user-linked events.

== Installation ==

1. Install and activate FluentCart.
2. Install and activate FCHub Multi-Currency.
3. Open FluentCart > Multi-Currency.
4. Configure display currencies and manual rates.
5. If wanted, explicitly select a remote provider and save its required credentials.

== External services ==

Remote services are optional. Manual makes no remote request.

= European Central Bank (ECB) =

When an administrator selects ECB, the plugin requests `https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml` during a manual refresh or the configured scheduled refresh. It sends no site identifier or customer identifier; ordinary HTTP metadata, including the server IP address, is necessarily visible to the service.

Service: https://www.ecb.europa.eu/
Terms and copyright: https://www.ecb.europa.eu/services/using-our-site/disclaimer/html/index.en.html
Privacy: https://www.ecb.europa.eu/services/data-protection/privacy-statements/html/ecb.privacy_statement_website.en.html

= ExchangeRate-API =

When an administrator selects ExchangeRate-API and supplies a key, the plugin requests `https://v6.exchangerate-api.com/v6/{key}/latest/{base}` during a manual refresh or the configured scheduled refresh. The request sends the administrator-provided API key and the store base currency. It sends no customer identifier.

Service: https://www.exchangerate-api.com/
Terms: https://www.exchangerate-api.com/terms
Privacy: https://www.exchangerate-api.com/terms

= Open Exchange Rates =

When an administrator selects Open Exchange Rates and supplies an app ID, the plugin requests `https://openexchangerates.org/api/latest.json` during a manual refresh or the configured scheduled refresh. The query sends the administrator-provided app ID and the store base currency. It sends no customer identifier.

Service: https://openexchangerates.org/
Terms: https://openexchangerates.org/terms
Privacy: https://openexchangerates.org/privacy

== Data storage and privacy ==

The selected currency preference can be stored locally in a browser cookie for the configured lifetime, which defaults to 90 days. When account persistence is enabled, the preference is also stored in WordPress user metadata until the user changes it, uses the WordPress Privacy Tools eraser, or the site owner removes plugin data.

The plugin stores rate history in a custom database table. Automated refreshes prune rate history older than 90 days. The event log records currency and rate activity; user-linked entries can be exported and erased through WordPress Privacy Tools. Operational event entries otherwise remain until the site owner removes plugin data.

Orders retain the display currency, exchange rate and checkout disclosure metadata with the FluentCart order for the order's normal retention period. FCHub Multi-Currency preserves its tables and settings on uninstall by default. If the site owner enables "Remove data on uninstall", uninstall removes plugin settings, rate history, the event log, scheduled refreshes and stored user currency preferences.

== Screenshots ==

1. Customer currency switcher with converted FluentCart catalogue prices.
2. Exchange-rate provider, refresh policy and current rate table in FluentCart settings.
3. Checkout disclosure showing the base currency used for the order and payment.

== Frequently Asked Questions ==

= Does this charge customers in the selected display currency? =

No. Converted prices are display-only. Checkout identifies the FluentCart store base currency used for the order and payment.

= Does the plugin contact a rate provider after activation? =

No. Manual is the no-network default. A scheduled refresh is created only after an administrator successfully saves a configured remote provider.

= What happens when a provider is unavailable? =

The failed response is not persisted. Customer prices continue to use the last good or manually entered rates according to the configured stale-rate fallback.

== Changelog ==

= Unreleased (local patch on top of 1.4.4, issue #72) =

Not an official release. Applied locally ahead of the next official update; the
plugin version is intentionally left at 1.4.4 so the update checker still
detects the eventual official release as newer instead of treating this build
as already current.

* Fixed logged-out visitors seeing their currency choice revert to the default on hosts whose edge/WAF layer strips the guest currency cookie on request paths it hasn't whitelisted. The switcher now also mirrors the choice to localStorage, and the storefront script reconciles it against the page's baked-in currency on load, correcting client-side when the cookie never reached the server. This is disabled automatically alongside cookie persistence, so sites that intentionally turned it off for guests are unaffected.
* Fixed the reconciliation fix above being silently defeated by the browser's own HTTP cache: once a given `?currency=X` URL had been fetched once, the browser could reuse that cached response on later page loads instead of asking the server again, so the page kept settling back on the stale currency. The reconciliation request now sends `cache: "no-store"`, and the public `GET /context` and `POST /context` REST responses now carry `Cache-Control: no-store`, since both responses are resolved per visitor and must never be cached by the browser or an edge/CDN layer.
* Fixed reconciliation still silently failing under ordinary page-load conditions on a slow host, even with the two fixes above: the reconciliation request's own timeout (4s) was too tight for real PHP response times under concurrent load, confirmed live where it competed with the page's other PHP-driven requests for a limited PHP-FPM worker pool. Raised the timeout to 12s to comfortably clear that, and re-timed the CSS safety-net fallback that force-reveals prices if JavaScript never finishes (previously a flat 2s, which fired mid-reconciliation on a slow host and force-revealed raw, unconverted base-currency prices every time) so it only ever fires after JavaScript's own bound has passed, not on a shorter clock racing it.
* Added price-formatting fields (symbol, decimals, position, separators, disclosure text) to the public `GET /context` REST response.
* Fixed the currency switcher dropdown showing the wrong currency as checked/active after a client-side reconciliation (see above): the trigger label and prices both updated correctly, but the dropdown's visible checkmark is baked into each option as static content at render time and isn't driven by the CSS active-state class, so it stayed on whichever currency the page was originally served with until the next full reload. The checkmark now moves along with everything else reconciliation updates.
* Fixed the switcher's trigger label displaying a currency the visitor didn't choose while a client-side reconciliation is still in progress. The trigger is server-rendered with whatever currency the page happened to be served with — exactly the value reconciliation exists to double-check — and showed it, uncorrected (merely dimmed, which still left it fully legible), for the full multi-second window. It now syncs immediately to the visitor's own saved choice the moment reconciliation begins, and stays showing that choice for the whole wait. Reconciliation still corrects the trigger afterward in the rare case the server disagrees with the visitor's saved choice.
* Fixed prices going fully blank for the entire multi-second reconciliation wait (previously hidden the same way as the plain, non-reconciliation price-conversion FOUC prevention) — a blank gap right after the page finishes reloading reads as "finished" when reconciliation has actually just started. Prices are no longer touched at all — no hiding, no dimming, no other visual treatment — while a reconciliation is in progress; they stay exactly as the page originally rendered them until the moment reconciliation completes, then switch directly to the corrected, converted values in one step. Only the switcher trigger itself shows a loading state during the wait (see above); prices get none. The plain (non-reconciliation) FOUC-prevention path, where prices are hidden briefly while first converted on a normal page load, is unchanged.
* Fixed switching currency taking noticeably longer for logged-out visitors than for signed-in ones: a switch already does one round-trip (save the preference, then reload) before any of the fixes above ever run, and on a host where the guest cookie doesn't reliably reach the server on that reload, the reconciliation fallback above added a second, separate round-trip on top of it — sequentially, not in parallel — to work out the correct currency after the fact. A logged-out visitor's switch now reloads straight to the switched-to currency as a URL parameter (the same one an explicit `?currency=` link uses, resolved server-side ahead of the cookie), so the reload's own server-render already has the right answer in that one request. This restores switching to a single round-trip for a guest, and the reconciliation fallback above no longer runs at all for this path — it's still exactly what handles a guest arriving fresh at a stale cached page, which this doesn't change. Sites that turned URL-parameter resolution off keep today's plain reload behavior unchanged. Once the reloaded page has settled, the currency parameter is quietly removed from the address bar again (no navigation, no visible change) so it doesn't accumulate on every switch or give a CDN a reason to cache each currency as a separate URL.
* Fixed switching currency jumping the page to the top on a long page, regardless of where the visitor had scrolled to before switching. The single-round-trip reload above navigated to a new URL (the currency parameter appended), and any navigation to a new URL always starts a fresh history entry, which the browser always opens scrolled to the top — the plugin's original plain reload before that change never had this problem, because reloading reuses the current history entry instead. The switcher now rewrites the current entry's URL in place first and reloads that, rather than navigating to a new one, which keeps the browser's own scroll position for a reload exactly as it always was. Applies to every switch, logged in or out. Confirmed live: the server-side resolution and round-trip count are unaffected either way.
* Fixed the single-round-trip fix above being applied to signed-in visitors too, which regressed their own switch time to match a guest's slower one. It exists specifically to work around a guest's cookie not reliably reaching the server on the post-switch reload — a problem signed-in visitors never had, since their preference has always resolved correctly and quickly via account storage, a separate, faster channel. Routing them through the URL parameter as well gained them nothing and cost them the same slower path. A signed-in visitor's switch now reloads the current page exactly as it was, with no currency parameter added, and still via the scroll-preserving reload above.
* Added temporary, opt-in (`?_debug_timing=1`) request-timing and cache-diagnostic instrumentation for the ongoing issue #72 investigation into why switching to any non-base currency takes several seconds longer than switching to the base currency. Not a permanent addition and never runs for ordinary traffic — remove `app/Support/Profiler.php`, `app/Support/CacheDiagnostics.php`, and their call sites once the bottleneck is found and fixed.

= 1.4.4 =

* Fixed converted prices reading 100x too high in stores whose FluentCart number format uses a comma decimal separator. The frontend was told the decimal separator by the wrong setting, so a base price of "20,00" was parsed as 2000 before conversion.

= 1.4.3 =

* Restored GitHub release updates. WordPress.org listing is still pending, so without this the plugin had no update channel at all.

= 1.4.2 =

* Fixed the FluentCRM automation email editor failing to load whenever Multi-Currency was active.
* Registered the funnel smart-code group against the real FluentCart trigger names.
* Reported honestly when a currency preference cannot be stored, instead of claiming it was saved.
* Stopped the switcher reloading the page after a switch the server rejected.
* Added stable outcome codes to the public context endpoint so clients no longer match on message text.
* Stated in settings that disabling cookie persistence prevents logged-out visitors keeping a currency.

= 1.4.1 =

* Make Manual the no-network default and require explicit remote-provider configuration.
* Bound remote rate requests and reject malformed or oversized responses without replacing valid rates.
