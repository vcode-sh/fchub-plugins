# CartShift

Migrate WooCommerce to [FluentCart](https://fluentcart.com). Products, customers, orders, subscriptions, coupons — I vacuum your entire WooCommerce into FluentCart's schema so you can finally close that chapter of your life.

## What it actually does

Reads your WooCommerce database. Maps everything into FluentCart. Products become products, customers become customers, orders stay orders. It sounds simple until you realise WooCommerce stores half its data in post meta and the other half in custom tables it added three years too late. I deal with that so you don't have to.

- **Products** — simple, variable, variations. The whole taxonomy of things people buy
- **Customers** — accounts, addresses, guest checkouts. Everyone who ever gave you money
- **Orders** — line items, shipping, taxes, meta. The paper trail
- **Subscriptions** — WooCommerce Subscriptions → FluentCart recurring billing. The escape hatch
- **Coupons** — applied coupon history stays with orders; standalone coupon definitions are reported but are not migrated yet
- **ID mapping** — old IDs → new IDs. Nothing gets orphaned
- **Preflight checks** — refuses to start when starting would produce nonsense. See below
- **Batch processing** — won't eat your server's RAM for breakfast
- **Guided screen** — one WordPress running both plugins gets a wizard that reads the shop, shows warnings, compromises and surviving decisions in plain English, and stops before target writes when a safe result or rollback cannot be proved
- **Transfer engine** — the package, evidence and rollback contracts stay underneath the guided screen. A different-site migration needs its own guided product route and is not exposed as terminal instructions here

## Requirements

- WordPress 6.8+ (tested up to 7.0)
- PHP 8.3+ (clean on 8.5, no deprecations)
- [WooCommerce](https://woocommerce.com) — active, with actual data, **and High-Performance Order Storage switched on**
- [FluentCart](https://fluentcart.com) — active
- [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/) — only if you want subscriptions migrated, and only if it's active at the time
- A database backup. Non-negotiable

### About that HPOS requirement

CartShift reads orders, customers and subscriptions from WooCommerce's HPOS tables and nowhere else. On a store still keeping orders in the posts table those tables are empty, every count comes back zero, and — this is the fun part — nothing errors. You would get a green results screen, a pile of products, and not one order. So preflight blocks instead.

Turn it on in **WooCommerce → Settings → Advanced → Features**, set order data storage to "High-performance order storage", let the sync finish, then re-run preflight. FluentCart's own WooCommerce importer requires the same thing, so this is the ecosystem's opinion rather than mine.

## Installation

1. ZIP from [Releases](../../releases)
2. Plugins → Add New → Upload Plugin
3. Activate
4. Open the migrator in wp-admin (Tools → CartShift)
5. Run preflight — fix whatever it complains about
6. Review what CartShift found. Safe compromises are reported with manual next steps; unsafe collisions and unproved rollback stop before target writes
7. Deactivate when done — this plugin has no business running permanently

## How it works

1. **Preflight** — scans WooCommerce, flags problems, estimates migration size
2. **Products** — migrates products and variations, builds ID map
3. **Customers** — user accounts and guest checkouts
4. **Orders** — recreates with correct product/customer references via ID map
5. **Subscriptions** — maps billing cycles from WooCommerce Subscriptions (if present)
6. **Coupons** — preserves applied order history; standalone definitions appear in the migration report but are not migrated yet

WooCommerce and FluentCart do not model every feature the same way. CartShift does not pretend otherwise. A variation that shares its parent's WooCommerce stock is migrated active but unavailable, with zero FluentCart stock and backorders disabled. The original shared stock evidence is retained, and the guided report explains how to allocate it manually without multiplying inventory across variants.

Each migrator extends `AbstractMigrator` and hands the orchestrator a batch at a time. Each mapper translates WooCommerce's... creative data structures into something a normal database would recognise.

## Reading the preflight screen

Three outcomes, and they mean different things:

- **✗ Failure** — blocks. Migrating anyway would produce wrong or missing data. Missing WooCommerce, missing FluentCart, HPOS off, missing migration tables
- **⚠ Warning** — proceeds. Something you should know: a modest memory limit, unsupported product types or standalone coupons that will be skipped, a WooCommerce sync that hasn't caught up
- **✓ Pass** — nothing to say

A warning is advice, not a veto. A low memory limit on a twenty-product store is fine, and pretending otherwise would just teach you to ignore the screen.

## The fine print

- **Back up your database.** I shouldn't have to say this but here I am, saying it
- **No guessed undo.** The guided route does not write target records until it can prove a completed rehearsal can be rolled back exactly
- **No silent approximation.** When target semantics differ, the report names the compromise, preserves the source evidence and gives the owner practical next steps
- **Deactivate after.** This is a migration tool, not a roommate. It leaves when the job's done
- **Uninstalling keeps your data.** The ID map is the only record of which WooCommerce row became which FluentCart row, and you will want it the week after you think you don't. Deleting the plugin leaves `{prefix}cartshift_id_map` and `{prefix}cartshift_migration_log` behind on purpose

To have them dropped on uninstall, opt in deliberately:

```bash
wp option update cartshift_delete_data_on_uninstall yes
```

Or, from a must-use plugin:

```php
add_filter('cartshift/delete_data_on_uninstall', '__return_true');
```

There is no checkbox for this in the UI, and there isn't going to be. A "delete everything" toggle two clicks from the migrate button is a support ticket with a countdown on it.

## Hooks

- `cartshift/migration/batch_size` — filter the batch size per entity type
- `cartshift/migration/record_migrated` — fires after each record, with the entity type, WooCommerce ID, FluentCart ID and migration ID
- `cartshift/feature_flags` — filter the feature flag array
- `cartshift/delete_data_on_uninstall` — filter whether uninstall drops the tables

## License

GPLv2 or later. Your WooCommerce exit strategy. Built by [Vibe Code](https://x.com/vcode_sh).
