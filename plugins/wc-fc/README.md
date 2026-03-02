# FCHub - WC Migrator

Migrate your WooCommerce data to [FluentCart](https://fluentcart.com). Products, customers, orders, subscriptions, coupons — the whole messy drawer of WooCommerce, neatly packed into FluentCart's schema. One-way trip. No regrets.

## What it does

Reads your WooCommerce database and maps everything into FluentCart equivalents. Products become products, customers become customers, orders stay orders — revolutionary concept, really. Handles the gnarly edge cases WooCommerce is famous for so you don't have to.

### Features

- **Products** — simple, variable, and their variations
- **Customers** — user data, addresses, the works
- **Orders** — with line items, shipping, taxes, meta
- **Subscriptions** — WooCommerce Subscriptions → FluentCart recurring billing
- **Coupons** — discount rules mapped to FluentCart's coupon system
- **ID mapping** — tracks old→new IDs so nothing gets lost in translation
- **Preflight checks** — validates your data before migration, because prevention beats debugging
- **Batch processing** — won't murder your server's memory
- **Admin UI** — step-by-step wizard, not a CLI you'll forget the flags to

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [WooCommerce](https://woocommerce.com) plugin (active, with data to migrate)
- [FluentCart](https://fluentcart.com) plugin (active)
- A cup of coffee for the migration wait

## Installation

1. Download the latest release ZIP from [Releases](../../releases)
2. WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate
4. Navigate to the migrator in wp-admin
5. Run preflight check — fix anything it complains about
6. Hit migrate and watch the progress bar
7. Deactivate when done — this isn't a plugin you keep around

## How it works

1. **Preflight** — scans WooCommerce data, flags issues, estimates migration size
2. **Products first** — migrates products and variations, builds ID map
3. **Customers** — maps user accounts and guest checkouts
4. **Orders** — recreates orders with correct product/customer references
5. **Subscriptions** — if WooCommerce Subscriptions is present, maps billing cycles
6. **Coupons** — translates discount rules to FluentCart format

Each entity migrator extends `AbstractMigrator` and uses mapper classes to translate WooCommerce's... creative data structures into something sane.

## Important notes

- **Back up your database** before migrating. Obviously.
- Migration is **one-directional** — there's no "undo" button
- **Deactivate after migration** — this plugin has no reason to run permanently
- WooCommerce Subscriptions support requires the WCS plugin to be active during migration

## License

GPLv2 or later. Your WooCommerce data's escape plan.
