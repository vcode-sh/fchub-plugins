# Selective migration — design

**Date:** 2026-08-07
**Component:** CartShift (`plugins/cartshift`)
**Status:** Approved, not yet implemented

---

## Problem

CartShift migrates all-or-nothing per entity type. The screen offers five checkboxes
(products, customers, coupons, orders, subscriptions) and nothing finer. Shop owners want to
migrate a subset — a trial run, or leaving old junk behind — and cannot.

Underneath that, three different policies govern what happens when a record references
something that was not migrated. None of them is visible to the user, and two are harmful:

| Reference | Current behaviour |
|---|---|
| Order → missing customer | Order skipped entirely. Revenue silently vanishes. |
| Order → missing product | Line item kept with `post_id = 0`; name and price preserved. Correct, but silent. |
| Subscription → missing product | Subscription skipped entirely. A paying subscriber silently disappears. |
| Coupon → missing product/category restrictions | Restrictions silently dropped. |

The coupon case is a live money leak. FluentCart's `DiscountService::isApplicableForItem()`
(`app/Services/Coupon/DiscountService.php:316`) reads:

```php
if ($includedProducts && !in_array($item['object_id'], $includedProducts)) {
    return false;
}
```

An empty `included_products` array is falsy, so the restriction check is skipped entirely.
Dropping unmapped IDs therefore converts *"20% off these three clearance items"* into
*"20% off the entire shop."*

---

## Principle

> **History migrates complete; live instructions never migrate broken.**

A past order is a **record**. It already happened, the money must add up, and a missing link
does not make it untrue. A subscription or coupon is an **instruction** — it will execute
against the shop tomorrow. A record with a gap is still correct. An instruction with a gap does
the wrong thing.

Two structural consequences:

1. **Orders are atomic.** A €100 order with three line items, migrated with two, is not a
   partial migration — it is corrupted books. Taking an order takes everything it references.
2. **Products are not atomic.** A catalogue is a list. Taking some and leaving others breaks
   nothing.

---

## Selection model

Scope-first, not a record picker. Three doors, with a live receipt panel that updates as the
owner changes their mind.

1. **Everything** — default, preselected. The common case for someone leaving WooCommerce.
2. **Everything from a date** — full catalogue and coupons; orders, customers and subscriptions
   from a chosen date onward. This is the real-world "leave the ancient history behind" case.
3. **Let me choose** — reveals a searchable picker for products and customers. Orders are never
   picked directly; they follow from customers.

Rejected: a tab-per-entity checkbox browser. A 50,000-order shop makes it unusable, and letting
someone tick orders and products independently invites contradictions the system then has to
adjudicate.

### Dependency closure

Selection flows **downward** along "needs" edges, automatically, and is never presented as a
question:

- customer → their orders → the products in those orders
- order → its customer, its products
- product → just the product (pulling orders in would drag in unrelated customers)

The **upward** direction is the only genuine choice, and it is framed as an offer:

> You picked 12 products. **47 orders** contain them — include those too? That also brings in
> **31 customers** and **12 more products**, because an order has to come complete.

That last clause matters: taking an order forces its *other* products in. The closure is
computed transitively and the result is reported as a total, never as a queue of decisions.

Closure runs server-side and returns counts only — never record lists — so it stays cheap on
large shops.

---

## Gap policy — one rule, four cases

Applied uniformly. Every case writes a coded, countable log entry.

### 1. Order whose customer is not being migrated

Migrate the order. Recreate the buyer from the billing details already on the order (the same
path `CustomerMigrator::processGuest()` uses for guest checkouts, which derives a customer from
`$order->get_billing_email()` and the order's address).

> "**3 orders** belong to customers you're not bringing. We've kept the orders and recreated
> those buyers from the order's own billing details, so your revenue totals still add up."

If the order has no billing email either, `fct_orders.customer_id` is nullable — migrate with a
null customer and log it. Never skip.

### 2. Order containing a product that is not being migrated

Keep current behaviour (`OrderMapper::mapItems()` already writes `post_id = 0`, preserving
`post_title` and price). Make it visible.

> "**12 orders** contain products you're not bringing. They still show what was bought and what
> it cost — those items just won't link to a product page. *Bring those 9 products too →*"

### 3. Subscription whose product is not being migrated

Migrate as **paused** rather than skipping. `Status::SUBSCRIPTION_PAUSED` exists
(`app/Helpers/Status.php:58`). The subscriber and their billing history survive; nothing charges
until a human decides.

> "**2 subscriptions** are for products you're not bringing. We've moved them across **paused**
> so nobody is charged for something that isn't in your shop. Review them before resuming."

Rationale: skipping loses a paying customer with no trace — worse for the business than a
paused record. A live subscription with no product is worse still. Paused is the only safe
middle.

### 4. Coupon restricted to products or categories that are not being migrated

Never widen a coupon. Migrate it in a non-active state so it exists and is reviewable but cannot
be redeemed. Preserve the original restriction IDs in the coupon's stored config for audit.

> "**1 coupon** was limited to products you're not bringing. We've brought it across **switched
> off** — leaving it on would have made it valid shop-wide."

**Status value: `disabled`.** Verified against FluentCart 1.6.0. `DiscountService.php:573-580`
compares the status as a plain string and ends with a catch-all:

```php
if ($status === 'expired')   { ... }
if ($status === 'scheduled') { ... }
if ($status !== 'active')    { ... }   // anything not 'active' is refused
```

There is no status enum, and `fct_coupons.status` is `VARCHAR(20)`, so any short string is
accepted and anything other than `active` is unredeemable. `disabled` is preferred over reusing
`expired` because `expired` would misstate the reason to anyone reading the coupon later.
`Coupon::scopeActive()` (`app/Models/Coupon.php:219`) filters on `status = 'active'`, so a
disabled coupon also stays out of active-coupon listings without further work.

### The rule in one line

**Nothing is ever silently dropped, and nothing broken is ever left switched on.**

---

## UI flow

Screen 2 (currently `SelectScreen.vue`) becomes:

```
┌──────────────────────────────┬──────────────────────┐
│ ◉ Everything                 │ You'll migrate       │
│ ○ Everything from a date     │   1,204 products     │
│ ○ Let me choose              │     856 customers    │
│                              │   3,102 orders       │
│  [picker appears here when   │      18 coupons      │
│   "Let me choose" selected]  │                      │
│                              │ Nothing left behind. │
│                              │ [ Start migration ]  │
└──────────────────────────────┴──────────────────────┘
```

The receipt panel is the primary feedback surface. Consequences appear inside it as calm
sentences with an inline offer link, never as modals, banners or per-record prompts.

Copy rules for this screen and the results screen:

- Say what will happen, not what failed.
- Counts first, mechanism second, jargon never. No "ID map", no "unmapped reference".
- Every consequence that has a remedy carries a one-click link to apply it.
- Destructive or irreversible language stays plain and unembellished.

---

## Scope boundary — CartShift migrates commerce, not entitlements

Decided 2026-08-07 against a real store (WooCommerce 11.0.0, HPOS, 699 orders, 31 subscriptions)
running LearnDash + `learndash-woocommerce`, and Paid Memberships Pro + `pmpro-woocommerce`.

On that store a single product can simultaneously be a subscription, grant a LearnDash course
enrollment, and grant a PMPro membership level. **CartShift migrates the commerce record only** —
products, customers, orders, subscriptions, coupons. Course enrollments and membership levels
are entitlements and belong to `fchub-memberships`.

This is a deliberate boundary, not an oversight. CartShift stays a commerce-data migrator; making
it a platform migrator would put it in the business of every access-control plugin in the
ecosystem.

**Consequence the UI must own:** a migration can complete perfectly and still leave customers
without course access or membership. Preflight must say so when it detects an entitlement plugin
bridging WooCommerce, rather than letting the owner discover it afterwards.

**Related, and in scope:** unknown product types. `learndash-woocommerce` registers a `course`
type, which `ProductMigrator::getProductTypes()` does not include — 2 products across 41 of 699
orders on that store. Handle this generically rather than special-casing LearnDash: preflight
should report **any** unrecognised product type with its count and the number of orders affected,
before the migration starts.

---

## Delivery — releases

The gap policy corrects behaviour that is already shipping and is independent of the selection
UI. It goes first, on its own.

### 1.2.1 — gap policy only

All four cases from the section above, plus their error codes, logging and tests. No UI change
beyond the results screen naming the new outcomes. Nothing here depends on selective migration:
these gaps occur during ordinary full migrations, because products are legitimately skipped
(`ProductMapper::map()` returns `null` for `grouped` and `external` types) and can fail for
other reasons.

Shipping this first means the dangerous inconsistencies — a lost paying subscriber, a shop-wide
coupon — are fixed for existing users without waiting on a feature.

Also in 1.2.1, from the same real-store audit: **migrate `_billing_nip`** into the billing
address as `meta.other_data.nip`. Present on effectively every order (707 meta rows / 699
orders) and currently dropped entirely. `fchub-fakturownia` needs it to issue company invoices,
and Polish B2B invoicing legally requires it — so without this, no migrated order can ever be
invoiced to a business.

### 1.3.0 — selective migration

Scope-first selection screen, dependency closure, the `/preview` endpoint, scope persistence,
and scope predicates composing with the keyset cursor. Built on a base where the gap policy is
already correct and tested, so the closure work never has to reason about three different
missing-reference behaviours.

Includes the generic unknown-product-type reporting described in the scope boundary above, and
the preflight warning when an entitlement plugin is detected.

### Separate release — Fakturownia bridge

Migrating existing invoice references from `woocommerce-fakturownia` to `fchub-fakturownia`.
Scoped apart because it is a real integration, not a field copy. Source shapes observed on the
audited store:

| Source meta | Rows | Shape |
|---|---|---|
| `_woo_fakturownia_faktura` | 631 | serialized array; **sometimes an error payload**, e.g. `a:1:{s:5:"error";s:147:"Cannot unserialize data: <html>…` |
| `_woo_fakturownia_faktura_hash` | 609 | 32-char hash used to build the public invoice URL |
| `_woo_fakturownia_faktura_korekta` | 15 | serialized, `{id: 408410372, numer: "K2"}` |
| `_woo_fakturownia_faktura_korekta_hash` | 14 | as above, for corrections |

Destination keys exist in `fchub-fakturownia`: `_fakturownia_invoice_id`,
`_fakturownia_invoice_number`, `_fakturownia_invoice_url`, `_fakturownia_correction_id`,
`_fakturownia_correction_number`, plus the `_fakturownia_ksef_*` family.

**Hard requirement:** parse and validate, never blind-copy. A stored error payload must be
logged as an unusable invoice reference, not written into an invoice ID field.

---

## Implementation surfaces

**Front end** (`src/`)
- `SelectScreen.vue` — rewritten to the three-door layout plus receipt panel.
- New `ScopePicker.vue` (products/customers search) and `MigrationReceipt.vue`.
- `useMigration.js` — `autoIncludeDependencies()` is superseded by server-computed closure;
  keep it as the client-side fallback and keep its Vitest coverage.

**REST** (`app/Http/Controllers/`)
- New `POST /cartshift/v1/preview` — accepts a scope, returns entity counts plus a list of
  consequence descriptors (`{code, count, remedy}`). Counts only, never records.
- `POST /migrate` accepts the same scope object and persists it in migration state.

**Scope persistence** (`app/State/MigrationState.php`)
- Store the resolved scope alongside `entity_types`. Retry and resume must reuse it, or a
  resumed run would silently widen.

**Record sourcing** (`app/Migrator/*`)
- `fetchBatch()` composes the scope predicate with the existing keyset cursor:
  `WHERE id > :cursor AND <scope predicates> ORDER BY id ASC LIMIT n`.
- Scope predicates must be indexed — date ranges on `wc_orders.date_created_gmt`, explicit ID
  sets via `IN (...)` chunks.

**Policy** (`app/Migrator/`, `app/Domain/Mapping/`)
- `OrderMigrator` — replace the customer-missing skip with the rebuild-from-billing path.
- `SubscriptionMigrator` — replace the product-missing skip with paused migration.
- `CouponMigrator` / `CouponMapper` — detect dropped restrictions and force a non-active status.

**Error taxonomy** (`app/Support/Enums/MigrationErrorCode.php`)
- Add: `customer_rebuilt_from_order`, `product_link_missing`, `subscription_paused_missing_product`,
  `coupon_disabled_missing_restrictions`. All are *informational* severity — they describe a
  deliberate policy outcome, not a failure.

---

## Testing

**PHP**
- Closure correctness: selecting a customer yields their orders and those orders' full product
  sets; selecting a product does not pull orders.
- Each of the four gap policies, asserting both the resulting record state and the coded log entry.
- The coupon case specifically: a coupon whose restrictions are all unmapped must not be
  redeemable after migration. This is the money-leak regression guard.
- Scope composes correctly with keyset pagination — no record skipped or repeated at a batch
  boundary when a scope predicate is active.
- A resumed or retried run reuses the persisted scope and does not widen.

**JS (Vitest)**
- Receipt panel renders each consequence descriptor, including unknown codes.
- Scope serialisation matches what `/preview` and `/migrate` expect.
- Degradation when `/preview` is unavailable.

---

## Out of scope

- Picking individual orders. They follow from customers by design.
- Editing records during migration.
- Two-way sync or re-migration of already-migrated records — that is what retry covers.

---

## Open questions

1. Whether "Everything from a date" should also bound products, or always take the full
   catalogue. **Current assumption: full catalogue.** A product costs little to migrate, and an
   order referencing a missing product is a worse outcome than an unused product sitting in the
   catalogue. Revisit only if a real shop shows catalogue size to be a problem.
