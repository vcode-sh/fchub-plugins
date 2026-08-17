=== FCHub Multi-Currency ===
Contributors: vcodesh
Tags: fluentcart, currency, exchange-rates, ecommerce
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 1.4.6
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

The selected currency preference can be stored locally in a browser cookie and a matching local-storage fallback for the configured lifetime, which defaults to 90 days. The fallback lets a cached storefront recover the same choice when an edge host does not vary pages by cookie. The cookie contains the three-letter currency code; the local-storage record contains the same code and its expiry time. When account persistence is enabled, the preference is also stored in WordPress user metadata until the user changes it, uses the WordPress Privacy Tools eraser, or the site owner removes plugin data.

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

= 1.4.6 =

* Fixed guest switching on Rocket.net and other edge-cached storefronts. Confirmed choices use browser storage, a one-shot currency URL and unique no-store recovery requests. The URL is removed only after verification, so shared HTML cannot reset the selector.
* Matched the mirror to the cookie lifetime, migrated existing preferences, rejected invalid data and kept explicit links and signed-in accounts in charge.
* Kept prices visible while a slow recovery request is pending, then projected the resolved currency as one update.
* Passed the validated display code through both FluentCart checkout forms, so order metadata records what the customer saw despite a stale cookie. Payment remains in the base currency.
* Formatted PHP helper and FluentCRM prices with the display currency's own decimals, separators, symbol and position, including zero-decimal bases and three-or-four-decimal display currencies.
* Returned the default display currency to FluentCart's base when its configured currency is removed.
* Rejected a non-base currency before saving it when no usable exchange rate exists, instead of accepting the click and quietly showing the base currency.
* Added runtime and PHP tests for cache, storage, expiry, failed persistence, races, checkout hand-off and number-format boundaries.
* Thanks to @ManniGH for reproducing the real Rocket.net cache boundary, testing it in production and contributing PR #149. The first fix did not go wide enough; this one follows the evidence.

= 1.4.5 =

* Fixed guest currency choices on edge-cached pages. Cached HTML is now reconciled with the existing `fchub_mc_currency` cookie, without host-specific page exclusions or a second browser preference store.
* Kept prices, ranges, variants, subscriptions, switchers, flags and currency context blocks on the same recovered currency, including base-currency, stale-URL and malformed-cookie fallbacks.
* Stopped stale guest nonces, failed cookie or account writes and bare successful HTTP responses from being reported as saved currency changes.
* Added proper Manual rate editing and saved manual or remote rates as complete snapshots. If one configured currency is missing, invalid or cannot be persisted, every previous rate remains active.
* Prevented Manual mode from refreshing old rates into a new timestamp, delayed cache updates until database success, and excluded removed currencies from public rate and context responses.
* Made FluentCart the authority for the store base currency, number format and cent-based price helpers. Also fixed signed rounding, zero-decimal output and repeat variant updates that could compound converted prices.
* Captured display currency and rate during checkout so a later payment event cannot replace the customer's choice with an admin, webhook or another request's context.
* Added browser-behaviour tests for the shipped JavaScript to CI, covering cached-page recovery, switch failures, price projection and Manual rate editing rather than merely admiring the source code.
* Thanks to @ManniGH for the production report, Rocket.net investigation and PR #149 that drove the cached-page work in #72.

= 1.4.4 =

* Fixed converted prices reading 100x too high in stores whose FluentCart number format uses a comma decimal separator. The frontend was told the decimal separator by the wrong setting, so a base price of "20,00" was parsed as 2000 before conversion. Thanks to @zellfusion for tracing the mismatch and supplying the exact reproduction in #142.

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
