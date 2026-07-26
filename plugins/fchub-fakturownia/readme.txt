=== FCHub Fakturownia ===
Contributors: vcodesh
Tags: fluentcart, invoices, ksef, accounting
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 1.1.2
Requires PHP: 8.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create Fakturownia invoices and corrections from FluentCart orders, with optional KSeF submission through Fakturownia.

== Description ==

FCHub Fakturownia connects FluentCart orders to the Fakturownia invoicing service. An enabled integration feed can create an invoice when an order is paid and a correction when an order is refunded.

Administrators configure their own Fakturownia account domain, API token and invoice preferences. KSeF submission is optional and is handled by Fakturownia when the administrator enables automatic submission.

The plugin records invoice and KSeF results on the FluentCart order so administrators can review them without copying provider credentials into the browser.

== Requirements ==

* WordPress 7.0 or later.
* PHP 8.3 or later.
* FluentCart is required and must be active.
* A Fakturownia account and API token are required to enable invoice feeds.

== Installation ==

1. Install FCHub Fakturownia from the WordPress plugin directory.
2. Activate FluentCart, then activate FCHub Fakturownia.
3. Open FluentCart settings and select the Fakturownia integration.
4. Enter the account subdomain and API token, then authenticate or save the settings.
5. Choose the required invoice, payment, language and KSeF options.
6. Enable the required FluentCart integration feed.

== Privacy ==

The plugin stores its integration settings in the WordPress database. These settings include the Fakturownia account domain, API token, seller department, invoice type, payment type, invoice language, KSeF preference, checkout tax-number preference and connection status.

On FluentCart orders, the plugin can store the Fakturownia invoice identifier, number and URL; client identifier; KSeF status, identifier and verification link; correction identifier, number and KSeF metadata; and bounded retry counters used for scheduled KSeF status polling.

The plugin does not send this integration data to FCHub. FCHub receives none of this data.

Fakturownia and KSeF processing is controlled by the administrator's agreement with Fakturownia. Site administrators are responsible for informing customers about that processing and for configuring appropriate retention and access controls.

== External services ==

This plugin connects to the administrator's configured `https://{account}.fakturownia.pl` endpoint. No request is made until an administrator supplies an account domain and API token and uses an authenticated save or authenticate action. Invoice and correction requests run only for enabled FluentCart integration feeds. Scheduled KSeF polling runs only for previously created invoices that require a status update.

Depending on the enabled feed and the available order data, the plugin sends:

* order and invoice identifiers, sale, issue and payment dates, and payment state;
* buyer or company name, tax number and type, address, country, email and phone when present;
* item names, quantities, net and gross prices, tax rates, currency, shipping and discounts;
* invoice type and language, seller department, notes and correction reason;
* `gov_save_and_send` only when KSeF automatic submission is enabled.

Fakturownia returns invoice, client and KSeF identifiers, statuses, links, correction data and PDF files. The plugin stores the relevant result metadata on the FluentCart order. FCHub receives none of this data.

Service information:

* API examples: https://fakturownia.pl/api-przyklady
* Terms: https://fakturownia.pl/regulamin
* Privacy policy: https://fakturownia.pl/polityka-prywatnosci
* Data-processing terms: https://fakturownia.pl/regulamin-powierzenia-2026

== Frequently Asked Questions ==

= Does the plugin send order data to FCHub? =

No. Requests go from the WordPress site to the administrator's configured Fakturownia account. FCHub receives none of this data.

= When is KSeF data submitted? =

The plugin includes `gov_save_and_send` only when KSeF automatic submission is enabled. Fakturownia then handles submission under the administrator's Fakturownia agreement.

= What happens if FluentCart is inactive? =

The integration does not register its FluentCart feeds. WordPress also reports FluentCart as a required plugin dependency.

= Does the plugin expose the API token in invoice links? =

No. Administrators download invoice PDFs through an authenticated WordPress endpoint. Provider failures are sanitised and token-bearing URLs are not returned.

== Screenshots ==

1. Fakturownia account and invoice settings in FluentCart.
2. A Fakturownia invoice result on a FluentCart order.
3. Successful KSeF submission with the assigned reference number.

== Changelog ==

= 1.1.2 =

* Moved plugin updates to WordPress.org and declared the FluentCart dependency.
* Added WordPress 7.0 and PHP 8.3 requirements.
* Bounded provider responses, blocked redirects, validated PDF downloads and sanitised failures.
* Added complete Fakturownia and KSeF service and privacy disclosure.
* Replaced third-party artwork with an owned neutral invoice glyph.

= 1.1.1 =

* Added current FluentCart invoice, correction and KSeF integration behaviour.

== Upgrade Notice ==

= 1.1.2 =

Updates now come from WordPress.org. Existing settings and FluentCart invoice, correction and KSeF order metadata are preserved.
