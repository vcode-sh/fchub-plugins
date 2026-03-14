# Changelog

## 1.1.0

This release is what happens when a plugin stops pretending one 700-line file is a personality — and then discovers half its SQL was querying tables that don't exist.

### Refactored

- rebuilt a big part of the plugin structure so the code is cleaner, smaller, and far less cursed to work on
- split the admin side into more focused pieces, so plans, members, reports, settings, and content tools are easier to follow and less of a maze
- cleaned up the plan flow, member flow, and subscription flow so the moving parts are separated properly instead of living in one enormous "good luck" service
- cut the initial admin bundle size down dramatically by stopping the app from loading the whole UI library like it was trying to impress someone
- added a proper local development setup for tests and admin builds, because mystery dependencies in random folders are not a strategy
- added and expanded automated tests, including bug-focused and edge-case checks, so future changes are less likely to set the plugin on fire
- cleaned up packaging and repo hygiene so fewer useless generated files end up hanging around where they do not belong

### Fixed

- revenue reports displayed raw cent values instead of whole currency units — a $99 order showed as $9900.00, which is only accurate if you're buying a yacht
- revenue display now uses the store's configured currency (symbol, position, decimal separator) instead of hardcoding USD like it's 2005
- linked products tab was querying a table that doesn't exist (`fct_order_integration_feeds`). FluentCart stores integration feeds in `fct_product_meta`. Every product link, unlink, and search query has been rewritten against the correct schema
- product search and linked products were joining `fct_products` — also doesn't exist. Products live in `wp_posts` (post type `fluent-products`), pricing in `fct_product_variations`. Fixed across all queries
- subscription renewal silently failed because `next_billing_at` was used instead of FluentCart's actual column `next_billing_date`. Grants never got their expiry extended on renewal
- FluentCommunity badge assignment and revocation broken — adapter `grant()` and `revoke()` calls were missing the `$context` parameter containing `plan_id`, so badge mappings could never resolve
- grace period calculation used `gmdate()` while everything else used `current_time('mysql')`, causing grants to expire at the wrong time depending on server timezone offset
- trial expiration checks and notifications had the same `gmdate()` vs `current_time()` mismatch
- grant expiry maintenance fired hooks before the database update — if anything threw after the hook, the audit log said "expired" but the grant was still active. DB update now runs first
- grace period expiry audit log used generic "revoked" action type — now logged as "grace_period_revoked" so the audit trail actually tells you what happened
- WP-CLI `backfill`, `sync --feed`, and `sync --plan` commands all queried the non-existent feeds table
- FluentCRM `CheckoutUrlHelper::getLinkedProductId()` queried the wrong table, breaking checkout URL and upgrade URL smart codes in automation emails
- four Vue pages (Dashboard, MemberProfile, PlanEditor, DripOverview) used Element Plus icons without importing them
- linking a product opened an `ElMessageBox.confirm` behind the link dialog, creating an overlay deadlock where the screen dimmed and nothing was clickable
- unlinking a product had the same modal stacking issue

### Changed

- linked products tab now shows all product variations (name, price, type) instead of just the first one
- link product dialog has a proper two-step flow (select → confirm) inside one dialog instead of stacking modals
- unlink uses inline `el-popconfirm` instead of a separate modal dialog
- product search returns all variations per product with title, price, and payment type
- revenue chart Y-axis ticks use the store currency formatter
- made the dashboard and admin navigation feel more consistent, including fixing small UI annoyances

In short: same plugin, much better manners, and it actually talks to the right database tables now.
