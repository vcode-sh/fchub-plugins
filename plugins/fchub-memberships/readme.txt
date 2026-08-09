=== FCHub Memberships ===
Contributors: vcodesh
Tags: fluentcart, memberships, content-restriction, subscriptions, ecommerce
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 1.4.5
Requires PHP: 8.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Membership plans, access rules, protected content, lifecycle automation and reporting for FluentCart.

== Description ==

FCHub Memberships adds a membership workspace to FluentCart. Build reusable plans, connect them to product variations, protect WordPress content, grant access after purchases and subscriptions, and inspect member history without assembling the whole arrangement from unrelated add-ons.

Features include:

* Membership plans linked to FluentCart product variations.
* Rules for posts, pages, taxonomies, URLs and supported provider resources.
* Fixed, rolling, lifetime, trial, grace-period and drip access.
* Member profiles, access history, imports, exports and bulk actions.
* A customer account view with membership and access details.
* Lifecycle email notifications with built-in or FluentCRM delivery.
* Local FluentCRM, FluentCommunity and LearnDash adapters when those plugins are installed.
* Reports, audit history and provider reconciliation.
* Administrator-configured outbound webhooks and authenticated REST access.
* WP-CLI maintenance, diagnostics and export commands.

FluentCart is required. Full guides live at https://fchub.co/docs/fchub-memberships.

== Installation ==

1. Install and activate FluentCart.
2. Upload and activate FCHub Memberships, or install it from the WordPress plugin directory.
3. Open **FluentCart > Memberships**.
4. Create a plan and connect at least one FluentCart product variation.
5. Add content rules, validity and drip settings, then review the plan before publishing it.
6. Configure the customer portal, notifications and optional integrations from Memberships settings.

Activation creates the plugin tables and recurring maintenance events. Existing runtime checks still show a safe inactive state when FluentCart is unavailable.

== Plans, protection and drip ==

A plan defines the membership term and the resources it grants. Product connections decide which paid order or subscription grants that plan. Protection rules can cover individual posts, groups of content, taxonomies, URLs and supported provider resources.

Drip rules release protected resources relative to the grant date. The member profile and audit history show the resulting access rather than asking an administrator to infer it from three menus and a suspiciously optimistic spreadsheet.

== Customer portal and email ==

The customer account view lists memberships, their state, dates and available community context. Notification Studio controls grant, revoke, pause, resume, trial and drip messages. Each message can use built-in WordPress email, FluentCRM when available, or remain disabled.

== REST access and webhooks ==

Read-only access checks use the generated FCHub key in the `X-API-Key` header. The key is shown once and only a password hash and display prefix are retained.

Membership writes use WordPress Application Password authentication and require the `manage_fchub_memberships` capability. External writes also require an `Idempotency-Key` header. Reusing a completed key with the same request returns the stored response; changing the operation or payload returns a conflict.

Administrators can create independent webhook endpoints, test them, and explicitly activate or pause each one. Active deliveries are signed with an endpoint-specific HMAC SHA-256 secret. Failed deliveries use bounded retries and retain redacted delivery status for troubleshooting.

== WP-CLI ==

Commands are registered below `wp fchub-membership`, including grants, revocation, synchronisation, expiry, drip processing, access diagnostics, member exports and summaries. Provider repair and reconciliation commands are available below `wp fchub-membership provider-reconcile`. Run `wp help fchub-membership` for the installed command list and arguments.

== External services ==

FCHub Memberships sends no request to FCHub for ordinary operation.

An authorised administrator can create outbound webhook endpoints. Delivery begins only after an endpoint is explicitly created, successfully tested and enabled. The plugin sends the configured destination URL an event payload containing timestamps, relevant membership and entity identifiers, and the event data needed by the receiver. Signature, event, delivery and timestamp headers are sent with the request. The destination's terms and privacy policy are selected and controlled by the administrator who configures that URL.

Application Password authentication is handled entirely by WordPress. FCHub Memberships does not send the Application Password to FCHub or another vendor.

FluentCRM, FluentCommunity and LearnDash adapters are local plugin-to-plugin integrations. They do not turn those plugins into an FCHub service and do not make a request to FCHub.

== Privacy ==

FCHub Memberships stores membership grants and entitlement records tied to WordPress user IDs, including plan/resource ownership, status, source and lifecycle dates. The audit log stores the actor, action, entity and bounded context needed to explain access changes.

Webhook event and delivery history can contain timestamps, entity identifiers, member details included in the event, destination URLs, delivery state, response codes and bounded redacted response summaries. Webhook signing secrets are stored in WordPress options and are never returned by the settings API after creation. Successful webhook deliveries are retained for 30 days and failed deliveries for 90 days.

Email and drip records store recipient or grant references, scheduled and sent state, and delivery timing. Provider-operation and reconciliation history stores resource ownership, desired actions, attempts and bounded failure details. Completed external mutation receipts are retained for 30 days. Audit entries are cleaned after 90 days by default.

The read-only access API key is stored as a password hash with a short display prefix. WordPress stores and manages Application Passwords. Endpoint signing secrets remain in the plugin settings so requests can be signed and may be rotated or revoked by an administrator.

Administrators can export member data from the Memberships workspace or with WP-CLI. The plugin does not register a separate WordPress personal-data exporter or eraser, so site operators must include Memberships records in their established privacy-request process. Uninstall preserves data by default. Enabling **Delete all Memberships data on uninstall** removes plugin tables, settings, capabilities and scheduled work when the plugin is uninstalled.

== Source and build ==

Readable source and build inputs are included in the distributed package.

Public source: https://github.com/vcode-sh/fchub-plugins/tree/main/plugins/fchub-memberships

Build the admin application from the plugin directory:

`npm ci && npm run build`

== Support ==

Documentation: https://fchub.co/docs/fchub-memberships

Source and issues: https://github.com/vcode-sh/fchub-plugins

When reporting a problem, include the WordPress, PHP, FluentCart and FCHub Memberships versions. Do not include API keys, webhook secrets, Application Passwords or customer data.

== Screenshots ==

1. Membership plans and product connections.
2. Member profile with access and lifecycle history.
3. Protected content and drip configuration.
4. Membership reports and provider health.

== Changelog ==

= 1.4.5 =

* Restored GitHub release updates. WordPress.org listing is still pending, so without this the plugin had no update channel at all.

= 1.4.4 =

* Fixed the Access Granted email listing an empty bullet instead of the granted resources.
* Resolved each membership rule to its real title and permalink before the email is sent.
* Fixed the drip schedule showing a bare dash instead of a readable unlock date.
* Skipped resources that no longer exist, are unpublished, or are not addressable content.
* Omitted the resources and drip sections entirely when nothing can be listed.

= 1.4.3 =

* Add every eligible Space from a FluentCommunity Space Group to a plan in one action.
* Show linked FluentCart products as soon as an existing plan opens.
* Prevent duplicate product connections and keep the list accurate after changes.
* Provide clear retry actions when linked products or plan members cannot be loaded.
* Improve delayed-access previews and give content-rule controls more breathing room.

= 1.4.2 =

* Fixed automatic plan slugs for Polish accents and other international scripts.
* Added server-authoritative slug previews, collision checks and safe 100-byte limits.
* Rejected titles and custom slugs that WordPress cannot turn into a usable identifier.

= 1.4.1 =

* Prepared WordPress.org metadata and dependency declarations.
* Replaced remote Inter loading with licensed local font subsets.
* Removed the legacy GitHub updater.
* Clarified opt-in webhooks, authenticated REST access, data retention and build instructions.
