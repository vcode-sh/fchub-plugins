# Product mapping — design

**Date:** 2026-08-08
**Component:** CartShift (`plugins/cartshift`)
**Status:** Implemented on `feat/cartshift-product-mapping`, staged and unreviewed by the owner.
Corrections made during implementation are marked in the sections they affect — the largest was
the FluentCart product post type, which this document originally got wrong.

---

## Problem

CartShift assumes FluentCart is empty. Every product it migrates, it creates.

That assumption is wrong for the most common real migration. WooCommerce and FluentCart run on
the same WordPress install, so shop owners install FluentCart, rebuild their catalogue by hand —
properly, with the pricing model and licensing FluentCart actually supports — and only *then*
think about moving eight years of order history across.

CartShift's answer today is to create a second "Pro Licence" next to the one they just built, and
attach every historical order to the duplicate. The owner is left with a split catalogue, split
revenue reporting, and a manual reconciliation job that is worse than the migration they were
trying to avoid.

What is missing is a way to say **"this Woo product is that FluentCart product"** before the run
starts.

---

## Principle

> **Migration writes history into the shop you already built. It does not build you a second shop.**

Two structural consequences:

1. **A product the owner made by hand is sacred.** CartShift may read it, reference it, and add a
   missing variant to it — visibly, and reversibly. It may never rename it, reprice it, or delete
   it. Rollback must leave it exactly as found.
2. **Nothing links itself.** Every link is a decision the owner made and can see. The matcher
   ranks candidates and pre-fills the answer; it does not commit one.

---

## Feasibility, and why this is cheaper than it looks

Every downstream reference in the migration already resolves through a single function:

```php
IdMapRepository::getFcId(Constants::ENTITY_PRODUCT | ENTITY_VARIATION, (string) $wcId)
```

Order line items (`app/Domain/Mapping/OrderMapper.php:125`), subscriptions
(`app/Domain/Mapping/SubscriptionMapper.php:79`), coupon restrictions
(`app/Domain/Mapping/CouponMapper.php:349`) and product downloads
(`app/Migrator/ProductMigrator.php:1219`) all ask the same table the same question.

So product mapping is not a feature that reaches into five migrators. It is **rows seeded into
`wp_cartshift_id_map` before the run starts.** Orders, subscriptions and coupons need no changes
at all.

The precedent exists in the plugin already. `IdMapRepository::store()`
(`app/Storage/IdMapRepository.php:73`) takes a `$createdByMigration` flag, and both
`CustomerMigrator` (`app/Migrator/CustomerMigrator.php:390`) and `CouponMigrator`
(`app/Migrator/CouponMigrator.php:296`) already use `false` for exactly this case — adopting an
FluentCart record the migration did not create. Customers adopt by email, coupons adopt by code.
Products have no natural key to adopt by, which is the entire reason this needs a human.

---

## Scope

**In:** WooCommerce products and their variations, mapped to existing FluentCart products and
their variants, decided before a run and honoured by every entity that references a product.

**Out:** customers and coupons — they already adopt automatically on email and code, and a
manual mapping screen for them would be ceremony for a solved problem.

---

## Where it plugs in

A new wizard step, `map`, between Select and Progress:

```
preflight → select → map → progress → results
```

`src/App.vue:4` currently switches on four screens; this adds a fifth.

**The step is skipped entirely when FluentCart's catalogue is empty.** A virgin migration — the
majority case, and the one CartShift was built for — never sees this screen and is not slowed
down by a feature it has no use for. The check is one `COUNT(*)` on FluentCart products, returned
by the preflight response.

The screen lists only Woo products already in scope, so `MigrationScope` does the filtering for
free and a "let me choose" run does not present the other 1,900 products for mapping.

---

## Data model

One new table, `wp_cartshift_product_map`, added as a `v6()` step in
`app/Support/Migrations.php`: a new entry in the `VERSIONS` map (`app/Support/Migrations.php:26`),
`Migrations::CURRENT_VERSION` `'5'` → `'6'`, and the `CARTSHIFT_DB_VERSION` constant in
`cartshift.php` bumped to match. **Both constants exist and both must move** — they are declared
independently and nothing enforces their agreement.

| Column | Type | Meaning |
|---|---|---|
| `id` | BIGINT PK | |
| `wc_id` | BIGINT, indexed | The WooCommerce product |
| `wc_type` | VARCHAR(20) | `simple`, `variable`, … — recorded so the screen can render without re-loading every `WC_Product` |
| `decision` | VARCHAR(10) | `link` \| `create` \| `skip` |
| `fc_post_id` | BIGINT NULL | The FluentCart product, when `decision = 'link'` |
| `band` | VARCHAR(10) | `strong` \| `likely` \| `weak` \| `none` at the time the decision was made |
| `variant_map` | LONGTEXT NULL | JSON, Woo variation ID → FC variation ID |
| `decided_at` | DATETIME | |

`UNIQUE KEY (wc_id)` — one decision per Woo product, last write wins.

### Why not write straight into `wp_cartshift_id_map`

Because a draft is not a fact. The ID map is the live resolution layer: the moment a row exists
there, `ProductMigrator::processRecord()` (`app/Migrator/ProductMigrator.php:939`) treats that
product as already migrated. An owner half-way through a mapping session, still changing their
mind, must not be silently altering how the next run behaves. Worse, `reset` and `rollback` both
operate on the ID map, so a draft stored there would be destroyed by an unrelated action or —
depending on the flag — mistaken for migration output.

The staging table also means a mapping session survives a browser crash, and survives being
reused for a second run.

---

## Promotion

At migration start, before the first batch, decisions are promoted:

| Decision | Promotion |
|---|---|
| `link` | `idMap->store(ENTITY_PRODUCT, wcId, fcPostId, migrationId, createdByMigration: false)`, plus one `ENTITY_VARIATION` row per resolved variant |
| `create` | Nothing. This is CartShift's existing behaviour. |
| `skip` | Added to an exclusion list the product migrator's source query honours |

A promoted `link` makes `ProductMigrator` log the product as already migrated and move on, and
makes every order line item, subscription and coupon restriction resolve to the owner's own
FluentCart product. No migrator changes.

Promotion is idempotent and runs inside the same guard as the rest of run start, so a resumed run
does not double-promote.

---

## The matcher

SKU-first matching was rejected. Real WooCommerce shops mostly leave SKUs blank, so a matcher
that keys on SKU reports "no candidate" for the entire catalogue and the screen becomes 300 rows
of manual search.

Scoring is weighted, and title similarity carries it:

| Signal | Weight | Notes |
|---|---|---|
| Normalised title similarity | primary | Lower-cased, punctuation and stop-words stripped, then `similar_text` |
| SKU equality | strong bonus | Deliberate identity when present, worthless when absent — a bonus, never a gate |
| Price equality | bonus | |
| Variation count equality | small bonus | |

Bands fall out of the total:

| Band | Meaning |
|---|---|
| `strong` | Same SKU, or same title and same price |
| `likely` | Similar title alone |
| `weak` | Fuzzy, low confidence |
| `none` | No plausible candidate — shown as "will be created", never as a bad guess |

The matcher runs server-side on entering the step and its output is cached in the staging table's
`band` column. It is a suggestion engine, not a decision engine: **no band auto-commits.**

---

## Variants

FluentCart order line items reference a product *and* a `fct_product_variations` row, so a link
that leaves variants unresolved produces orders with broken line items. Woo simple products are
not exempt — `migrateSimpleDownloads()` (`app/Migrator/ProductMigrator.php:1219`) resolves
`ENTITY_VARIATION` keyed by the *product* ID, mirroring how the real migrator stores it at
`app/Migrator/ProductMigrator.php:1017`.

Under a `link`, variants resolve in order: **SKU → attribute name → position.**

When a Woo variation has no counterpart among the FC product's variants, CartShift **adds it** to
that product, flagged `created_by_migration = 1`. The row says so before the owner confirms
(`adds "XL"`). The alternatives were both rejected:

- Pointing the orphan at an existing variant puts "XL" revenue on the "L" row, and silently
  breaks FluentCart's per-variant reporting for ever.
- Leaving the line item unlinked is honest but leaves orphan lines on the order detail page for a
  product that plainly exists.

Adding is the only option that is both correct and reversible, and it is reversible precisely
because of the flag.

---

## Run modes

A single control on the mapping screen sets what happens to rows the owner never touched:

| Mode | Untouched rows |
|---|---|
| **Create the rest** (default) | Migrated and created, exactly as CartShift behaves today |
| **Only what I mapped** | Not migrated at all — mapping becomes a whitelist |

"Only what I mapped" is `MigrationScope::MODE_EXPLICIT` (`app/Domain/Scope/MigrationScope.php:26`)
with `product_ids` set to the linked set. Existing machinery, no second filter mechanism, and
`ScopeResolver` already handles the closure of what those products drag in.

Per-row `create` and `skip` always override the mode.

---

## The screen

A single scrolling list, grouped into the four bands, each with its own bulk action. Three
summary tiles at the top: Woo products in scope, decisions made, FluentCart catalogue size.

```
Strong · 19          same SKU, or same name and price      [ Link all 19 ]
  Pro Licence — Annual                    →  Pro Licence
  variable · 4 variations · 214 orders       4/4 variants matched     [Link] [Create] [Skip]

Likely · 6           similar name only                     [ Link all 6 ]
  T-Shirt (Classic)                       →  ⌄ Classic T-Shirt
  variable · 3 variations · 88 orders        2/3 matched · adds "XL"  [Link] [Create] [Skip]

No candidate · 287                                [ Create all ] [ Skip all ]
```

Design rules:

- **Order count per row.** "214 orders" is what tells the owner which twelve rows out of 300
  actually matter. Without it every row looks equally important and the screen is unusable.
- **Variant state is a summary, not a table.** `4/4 variants matched` collapsed; expandable only
  when the owner wants to override a specific variant.
- **The FC side is a `<select>` of candidates**, not a free search, when candidates exist. Search
  is the fallback, reusing the `/scope/search` endpoint pattern from
  `app/Http/Controllers/PreviewController.php:77` and the `ScopePicker.vue` component.
- **Nothing is written until Continue.** Decisions POST to the staging table as they are made so
  the session is durable, but promotion into the ID map happens only at run start.

---

## Rollback and dry run

Rollback needs no changes and this is the whole point. `getCreatedByMigration()`
(`app/Storage/IdMapRepository.php:232`) and `deleteCreatedByMigration()`
(`app/Storage/IdMapRepository.php:279`) already restrict to `created_by_migration = 1`, so:

- **Linked FluentCart products survive rollback intact.** They were never CartShift's to delete.
- **Variants CartShift added are removed**, because those *were* created by the migration.
- The staging table is untouched by rollback, so the owner's mapping work is not destroyed by
  undoing a run they want to retry.

**A dry run creates nothing in FluentCart, promotion included.**

This paragraph originally claimed the opposite — that promoted rows are always real
(`is_simulated = 0`). That was written when promotion ran only on the real path, and it stopped
being true the moment promotion moved into `MigrationOrchestratorFactory` and began running on
every path, dry runs among them. The contract that governs is `IdMapRepository`'s own: a
rehearsal writes nothing outside CartShift's own table, and a rehearsal that added a variant to a
product the shop owner built by hand is exactly the careless write this feature exists to
prevent.

So promotion honours the simulation realm like every other write in the plugin:

- **Its ID map rows are simulated.** `MigrationOrchestratorFactory::promote()` derives the realm
  from `MigrationState::isDryRun()`, the same way `MigrationOrchestrator::processBatch()` does —
  and has to derive it there, because promotion runs *before* `processBatch()`: on a batch tick
  the `IdMapRepository` it is handed is freshly constructed and not simulating yet.
- **Orphan variants are not created.** `MappingPromoter` mints a synthetic ID from its own
  `SIMULATED_VARIATION_BASE` rather than calling the creator at all, mirroring what
  `ProductMigrator` already does for the variations it would have created. Skipping the orphan
  outright would leave every order line item referencing that variation unresolvable, so the
  rehearsal would report problems the real run will not have.

A real run reads only `is_simulated = 0` and therefore cannot see a rehearsal's rows: promotion's
idempotency check does not fire, the links are promoted properly, and the variants are created
for real.

That last point is what makes minting a synthetic ID the right answer rather than merely a tidy
one. The obvious alternative — skip orphan creation during a dry run and change nothing else —
was rejected while promotion still wrote real rows, because a rehearsal that promoted a product
*without* its orphans would have satisfied the idempotency check and made the real run skip the
whole decision. Once the whole promotion moved into the simulated realm that particular hazard
disappeared, and the reason the orphan still must not simply be skipped is the one above: an
unresolvable line item makes the rehearsal report problems the real run will not have.

`reset` clears a rehearsal's rows with `purgeSimulated()`, as it does for every other dry run.
The preview still reports exactly what the real run will do, because a dry run reads both realms
(`app/Storage/IdMapRepository.php:115`) and so sees its own promotion.

`reset` leaves the staging table alone, matching what it already does to the ID map: reset calls
only `purgeSimulated()` (`app/Http/Controllers/MigrationController.php`, reset handler) and never
touches real rows. Reset forgets a *run*; the mapping is not a run, it is the owner's decisions,
and destroying them is precisely the wrong response to "I want to try again". Clearing mappings is
an explicit action on the mapping screen instead.

`Migrations::dropAll()` (`app/Support/Migrations.php:56`) gains the new table, which is what
`uninstall.php` calls when `cartshift_delete_data_on_uninstall` is set.

---

## Edge cases

| Case | Behaviour |
|---|---|
| Two Woo products linked to one FC product | Allowed, warned. The ID map supports it (distinct `wc_id`, same `fc_id`), and merging an old and a rebuilt product is a legitimate thing to want. |
| Woo product already migrated by an earlier run | Read-only row, shown as "migrated in run *X*". No re-link — that would orphan the orders already attached. |
| Linked FC product deleted between mapping and run | Promotion validates `fc_post_id` still exists; a dead link degrades to `create` and is reported in the log, not fatal. |
| Woo product in scope, FC catalogue empty | Step skipped entirely, everything creates. |
| Variable Woo product linked to a simple FC product | Allowed; every Woo variation resolves to the FC product's single variant unless the owner overrides. Warned, because it flattens per-variant history. |
| Mapping exists for a product no longer in scope | Ignored at promotion. Kept in the table — scope may change on the next run. |

---

## Testing

PHPUnit, following the existing `tests/Unit` layout:

- **Matcher** — scoring and band assignment, table-driven. Includes the blank-SKU catalogue, which
  is the case that killed SKU-first matching.
- **Variant resolution** — SKU / attribute / position precedence, and the orphan-variation path
  producing an `added` instruction rather than a silent re-point.
- **Promotion** — `link` produces `created_by_migration = 0` rows; `skip` excludes from the source
  query; `create` is a no-op; promotion is idempotent across a resumed run.
- **Rollback** — a linked FC product and its original variants survive; only added variants go.
- **Dead link** — deleted `fc_post_id` degrades to `create` and logs.
- **Migrations** — `5` → `6` creates the table, and re-running is a no-op.

Vitest, following `tests/js`:

- Banded bulk actions apply to the right band and nothing else.
- Per-row override survives a subsequent bulk action on its band.
- Run-mode toggle changes only untouched rows.

---

## Files

**New**

```
app/Domain/Mapping/ProductMatcher.php        scoring and bands
app/Domain/Mapping/VariantResolver.php       SKU → attribute → position
app/Storage/ProductMapRepository.php         the staging table
app/Domain/Migration/MappingPromoter.php     staging → ID map at run start
app/Http/Controllers/MappingController.php   REST: candidates, decide, bulk
src/components/MapScreen.vue                 the screen
src/components/MapRow.vue                    one product row
src/composables/useMapping.js                state and bulk actions
```

**Modified**

```
cartshift.php                    CARTSHIFT_DB_VERSION 5 → 6
app/Support/Migrations.php       v6(), VERSIONS map, CURRENT_VERSION, dropAll()
app/Migrator/ProductMigrator.php honour the skip exclusion list
app/Modules/*/…Module.php        register controller and services
src/App.vue                      the 'map' screen
```

`uninstall.php` needs no change — it delegates to `Migrations::dropAll()`. `Constants.php` needs
no change either: CartShift's own table names are built inline from `$wpdb->prefix` in each
repository, and only FluentCart's tables are named in `Constants`.

`ProductMigrator` is the only migrator touched, and only for the `skip` list. Orders,
subscriptions and coupons are untouched — they inherit everything through the ID map.

---

## Rejected

- **SKU-first matching.** Most shops leave SKUs blank; it degrades to a blank screen.
- **Auto-accepting any band.** The owner asked for confirmation on every row, and a silently
  fused "Gift Card" is unrecoverable once orders are attached.
- **Writing drafts into the ID map.** Conflates intent with fact, and hands `reset` and `rollback`
  the power to destroy a mapping session.
- **Updating linked FC products from Woo data.** The owner built that product deliberately. The
  migration brings history, not opinions about pricing.
- **CSV import/export of mappings.** YAGNI until someone maps a catalogue big enough to want it.
- **Mapping customers and coupons.** Already adopt automatically on email and code.
