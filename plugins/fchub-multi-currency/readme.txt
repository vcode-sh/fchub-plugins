=== FCHub Multi-Currency ===
Contributors: vcodesh
Tags: fluentcart, currency, exchange-rates, ecommerce
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 1.4.8
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

The plugin stores rate history in a custom database table. A daily maintenance schedule prunes rate history and event-log entries older than 90 days on every store. The event log records currency and rate activity; user-linked entries can be exported and erased through WordPress Privacy Tools at any time.

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

= 1.4.8 =

* Fixed the shop price filter after a currency switch. FluentCart's filter speaks base currency end to end, so the filter block now stays in base — and actually filters — instead of corrupting queries with converted values.
* Stopped touching FluentCart's checkout patch fragments; the old disclosure entry broke checkout updates for visitors browsing a converted currency. The disclosure ships client-side.
* Read the order's WordPress user through FluentCart's customer relation, so the FluentCRM order sync and manual-order snapshot fallback actually run.
* Stopped the selector-buttons block baking one visitor's currency into cacheable HTML, gave the switcher full keyboard navigation, and painted the resolved currency on stores without price projection.
* Rendered the rate-freshness badge in the browser from a shipped timestamp, so cached pages stop repeating their age; mirrored FluentCart's ISO and symbol price positions.
* Counted every REST attempt against the rate limiter with an unforgeable per-socket ceiling, validated forwarded IP headers, and survived rogue context filters with a logged fallback.
* Let guests submit the no-JS form however old the cached nonce; added a daily schedule pruning the event log and rate history on every store.
* Rebuilt the admin page into per-tab components with a working currency grid, value-stating diagnostics, a one-click FluentCRM field creator, and a default display currency that follows the store base.
* Thanks to @ManniGH for the live 1.4.7 measurements in #72 that set this release's direction.

= 1.4.7 =

* Kept guest switchers locked from the confirmed choice through context recovery and completed price projection. The visitor's code appears immediately; stale cached prices do not.
* Started recovery before body parsing and moved price shielding into a dedicated sub-1 KB compressed stylesheet instead of loading the full switcher CSS everywhere.
* Restored the served currency honestly after failures and preserved existing disabled controls, newer preferences and projection-disabled stores.
* Added adversarial runtime coverage and a real 4.5-second delayed-browser measurement.
* Thanks to @ManniGH for catching the remaining production-only reconciliation window in #72 and mapping the useful evidence from PR #149.

Earlier releases are documented in full at https://fchub.co/docs/fchub-multi-currency/changelog
