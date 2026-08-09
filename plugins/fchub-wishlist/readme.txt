=== FCHub Wishlist ===
Contributors: vcodesh
Tags: fluentcart, wishlist, ecommerce
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 1.0.3
Requires PHP: 8.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Wishlists for FluentCart customers, including guest lists, customer portal access and optional FluentCRM automation.

== Description ==

FCHub Wishlist adds saved-product lists to FluentCart stores. Customers can save products and specific variants, review their list on a dedicated page or in the FluentCart customer portal, and add available items to the cart.

Guests can keep a wishlist between visits. When a guest signs in or creates an account, the plugin merges that guest list into the account wishlist and removes the guest cookie.

The optional FluentCRM integration can apply on-site tags and provide wishlist conditions, triggers and actions for local automations. Reminder emails are disabled by default and use WordPress email delivery when enabled.

== Requirements ==

* WordPress 7.0 or later.
* PHP 8.3 or later.
* FluentCart is required and must be active.
* FluentCRM is optional. Wishlist operation does not require it.

== Installation ==

1. Install FCHub Wishlist from the WordPress plugin directory.
2. Activate FluentCart, then activate FCHub Wishlist.
3. Open FluentCart settings and configure the Wishlist integration.
4. Create a WordPress page containing the `[fchub_wishlist]` shortcode if you want a standalone wishlist page.
5. Sign in as a customer and confirm that the **My Wishlist** entry appears in the FluentCart customer portal.
6. If FluentCRM is active, enable its Wishlist integration only when you want local contact tags and automations.

== Privacy ==

For guests, the plugin sets the `fchub_wishlist_hash` cookie. It contains a random identifier used to find the guest wishlist. The cookie lasts for 30 days by default. Guest wishlist retention is controlled by the configured guest cleanup period, which defaults to 30 days.

Wishlist records can contain a WordPress user identifier, a FluentCart customer identifier, the guest session hash, a list title and timestamps. Wishlist items store product and variation identifiers, the price recorded when the item was saved, an optional note and a timestamp.

When a guest signs in or creates an account, the plugin merges the guest wishlist into the account wishlist and removes the guest cookie. Account wishlist data remains until the customer removes it, a site administrator uses the WordPress personal-data eraser, or the site uninstalls the plugin with data removal enabled.

The plugin registers with the WordPress personal-data exporter and eraser. The exporter returns wishlist item details for the requested account email. The eraser deletes that account's wishlist and detaches its active FluentCRM wishlist tag.

By default, uninstalling the plugin preserves its tables and settings. Administrators can choose to remove FCHub Wishlist data during uninstall in the plugin settings.

== External services ==

Core wishlist operation sends no data to FCHub or any FCHub service.

The optional FluentCRM integration runs on the same WordPress site. It passes local user, product and wishlist state to the installed FluentCRM plugin only when the integration is enabled and a wishlist event or automation runs.

When reminder emails are enabled, the plugin passes the recipient address, subject, message body and mail headers to WordPress through `wp_mail()`. WordPress then uses the mail transport configured by the site. The external provider, service URL, terms and privacy policy therefore depend on the site's own mail configuration; FCHub Wishlist does not select or contact a mail provider directly.

== Frequently Asked Questions ==

= How long does a guest wishlist persist? =

The guest cookie lasts for 30 days by default. Stored guest lists are removed according to the configured guest cleanup period, which also defaults to 30 days.

= Is FluentCRM required? =

No. FluentCart is required. FluentCRM is optional and is used only for local contact tags and wishlist automations.

= What happens when a customer deletes wishlist data? =

Customers can remove individual items through the wishlist interface. A site administrator can also use the WordPress personal-data eraser to delete the wishlist associated with an account email.

= Does uninstalling the plugin delete its data? =

Not by default. Wishlist tables and settings are preserved unless **Remove data on uninstall** is enabled before uninstalling the plugin.

== Screenshots ==

1. Wishlist action on a FluentCart product page.
2. Customer wishlist page with saved variant information.
3. FCHub Wishlist settings in FluentCart.

== Changelog ==

= 1.0.3 =

* Split bulk deletes and count queries out of the item repository into the collaborators that already held their siblings.
* Removed five copies of the same count query from the stats overview.
* Extracted the pagination markup the wishlist page and the customer portal were each carrying their own identical copy of.

= 1.0.2 =

* Restored GitHub release updates. WordPress.org listing is still pending, so without this the plugin had no update channel at all.
* Declared the FluentCart dependency and the WordPress 7.0 and PHP 8.3 requirements.
* Added WordPress.org documentation and licensing without changing wishlist data.

= 1.0.1 =

* Added current wishlist database compatibility and maintenance behaviour.

== Upgrade Notice ==

= 1.0.3 =

Internal restructuring only. No change to wishlist data, settings, guest lists or account lists.
