=== FCHub Przelewy24 ===
Contributors: vcodesh
Tags: fluentcart, payments, przelewy24, blik
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 1.0.5
Requires PHP: 8.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Przelewy24 payments in FluentCart with signed callbacks, refunds, BLIK and optional recurring card payments.

== Description ==

FCHub Przelewy24 connects FluentCart to the Przelewy24 payment service. A Przelewy24 merchant agreement is required. Customers are redirected to Przelewy24 to complete payment.

The plugin starts in test mode. It does not contact Przelewy24 until complete credentials have been saved and an administrator runs the connection test or a customer explicitly selects Przelewy24 during checkout.

= External service =

This plugin connects to the Przelewy24 sandbox at `https://sandbox.przelewy24.pl` in test mode and to `https://secure.przelewy24.pl` in live mode.

Depending on the payment, it sends merchant, shop, session and order identifiers; amount, currency, description and selected payment method; payer email and country; and, when available or required, payer name, address, postcode, city and phone. It also sends callback and return URLs, optional bounded cart line data, and the payer IP address and browser user agent required for the payment flow.

When recurring card payments are enabled, the plugin receives and later sends a Przelewy24 card reference token. It never receives or stores full card details.

Przelewy24 documentation and policies:

* [API documentation](https://developers.przelewy24.pl/)
* [Terms](https://www.przelewy24.pl/regulamin)
* [Merchant terms](https://www.przelewy24.pl/owu)
* [Privacy policy](https://www.przelewy24.pl/polityka-prywatnosci)

= Local data =

The plugin stores gateway settings plus payment session, Przelewy24 order, transaction and card reference identifiers. For recurring payments it may store a masked card number, card type, card expiry metadata, a pending renewal session and a bounded retry count. Uninstall removes gateway settings, cached payment-method data, plugin-owned renewal actions and `_p24_` order metadata. Normal deactivation preserves transaction data but removes cached methods and plugin-owned scheduled actions.

== Installation ==

1. Install and activate FluentCart.
2. Upload and activate FCHub Przelewy24.
3. Open FluentCart payment settings and save sandbox credentials.
4. Test the connection before enabling the gateway.

== Screenshots ==

1. Przelewy24 gateway settings in FluentCart using test-mode fixture values.
2. Przelewy24 payment-method selection during a FluentCart checkout.
3. Successful test-mode transaction status in FluentCart without customer or credential data.

== Frequently Asked Questions ==

= Does the plugin send full card details to WordPress? =

No. Card entry and processing happen at Przelewy24. The plugin stores only the reference and masked metadata returned for enabled recurring payments.

= Can I use the plugin without a Przelewy24 agreement? =

No. Transaction processing requires an active Przelewy24 merchant agreement.

== Changelog ==

= 1.0.5 =

* Release metadata is now checked against the plugin header, so a version bump can no longer land half-applied. No change to gateway behaviour.

= 1.0.4 =

* Restored GitHub release updates. WordPress.org listing is still pending, so without this the plugin had no update channel at all.
* Prepare the gateway for WordPress.org distribution.
* Restrict outbound requests and strengthen callback, refund and renewal validation.
* Document Przelewy24 data transfer and local storage.
