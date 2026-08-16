=== FCHub Multi-Currency ===
Contributors: vcodesh
Tags: fluentcart, currency, exchange-rates, ecommerce
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 1.4.4.1
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

= 1.4.4.1 =

* Fixed logged-out visitors seeing their currency choice revert to the default on hosts whose edge/WAF layer strips the guest currency cookie on request paths it hasn't whitelisted, or whose full-page cache serves a shared, anonymous-visitor snapshot of the page. The switcher now also mirrors a guest's choice to localStorage, and the storefront script reconciles it against the page's baked-in currency on load — via a `GET /context?currency=<code>` request that resolves through the URL query string rather than the cookie — correcting client-side whenever the two disagree. Bounded by a 12-second timeout tuned to real host latency, with a matching CSS fallback that never force-reveals prices before that timeout has actually had a chance to resolve them. Disabled automatically alongside cookie persistence, so sites that intentionally turned it off for guests are unaffected, and never overrides an explicit `?currency=` link or a signed-in visitor's saved preference.
* Fixed a currency switch costing guests a second, separate round-trip on top of the reconciliation above: switching now reloads straight to the switched-to currency as a URL parameter — the same one an explicit `?currency=` link uses, resolved server-side ahead of the cookie in a single request — so the reconciliation fetch above no longer needs to run for this path at all. Restores switching to a single round-trip for guests, matching what signed-in visitors (whose preference has always resolved quickly via their account) already had; signed-in visitors' own reload is untouched, never carries the currency parameter, and never touches this mechanism. The parameter is removed from the address bar again once the page has settled, so it never accumulates across switches or gives a CDN a reason to cache each currency as a separate URL. Sites that have URL-parameter resolution turned off keep the previous plain-reload behavior.
* Fixed switching currency jumping the page to the top on a long page regardless of where the visitor had scrolled to before switching. The reload above rewrites the current browser history entry in place and reloads that, rather than navigating to a new one, which preserves scroll position exactly the way a plain reload always has.
* Fixed the currency switcher dropdown showing the wrong currency as checked/active after a client-side reconciliation: the trigger label and prices both updated correctly, but the dropdown's visible checkmark is baked into each option as static content at render time and isn't driven by the CSS active-state class, so it stayed on whichever currency the page was originally served with until the next full reload. The checkmark now moves along with everything else reconciliation updates.
* Added price-formatting fields (symbol, decimals, position, separators, disclosure text) to the public `GET /context` REST response.

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
