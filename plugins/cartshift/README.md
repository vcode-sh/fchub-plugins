# CartShift

Migrate WooCommerce to [FluentCart](https://fluentcart.com). Products, customers, orders, subscriptions, coupons — I vacuum your entire WooCommerce into FluentCart's schema so you can finally close that chapter of your life.

## What it actually does

Reads your WooCommerce database. Maps everything into FluentCart. Products become products, customers become customers, orders stay orders. It sounds simple until you realise WooCommerce stores half its data in post meta and the other half in custom tables it added three years too late. I deal with that so you don't have to.

- **Products** — simple, variable, variations. The whole taxonomy of things people buy
- **Customers** — accounts, addresses, guest checkouts. Everyone who ever gave you money
- **Orders** — line items, shipping, taxes, meta. The paper trail
- **Subscriptions** — WooCommerce Subscriptions → FluentCart recurring billing. The escape hatch
- **Coupons** — discount rules translated to FluentCart's format. Because apparently people keep these
- **ID mapping** — old IDs → new IDs. Nothing gets orphaned
- **Preflight checks** — validates your data before migrating. Prevention > debugging at midnight
- **Batch processing** — won't eat your server's RAM for breakfast
- **Admin wizard** — step-by-step UI. Not a CLI you'll forget the flags for

## Requirements

- WordPress 6.8+
- PHP 8.3+
- [WooCommerce](https://woocommerce.com) (active, with actual data)
- [FluentCart](https://fluentcart.com) (active)
- A database backup. Non-negotiable

## Installation

1. ZIP from [Releases](../../releases)
2. Plugins → Add New → Upload Plugin
3. Activate
4. Open the migrator in wp-admin
5. Run preflight — fix whatever it complains about
6. Migrate. Watch the progress bar. Resist the urge to refresh
7. Deactivate when done — this plugin has no business running permanently

## How it works

1. **Preflight** — scans WooCommerce, flags problems, estimates migration size
2. **Products** — migrates products and variations, builds ID map
3. **Customers** — user accounts and guest checkouts
4. **Orders** — recreates with correct product/customer references via ID map
5. **Subscriptions** — maps billing cycles from WooCommerce Subscriptions (if present)
6. **Coupons** — translates WooCommerce's discount logic to FluentCart's format

Each migrator extends `AbstractMigrator`. Each mapper translates WooCommerce's... creative data structures into something a normal database would recognise.

## The fine print

- **Back up your database.** I shouldn't have to say this but here I am, saying it
- **One-way trip.** There's no undo. That's the point — you're leaving WooCommerce
- **Deactivate after.** This is a migration tool, not a roommate. It leaves when the job's done
- **WCS required** — WooCommerce Subscriptions must be active during migration if you want subscription data

## License

GPLv2 or later. Your WooCommerce exit strategy. Built by [Vibe Code](https://x.com/vcode_sh).
