# Changelog

## Unreleased

The member profile used to show database rows and call them memberships. A plan
writes one row per rule, so one membership could appear as six cards, each with
its own Pause button, and pausing one of them left the rest wide open. That is
now fixed rather than documented.

- **One card per membership.** The profile groups grant rows by plan, matching
  the member list instead of contradicting it. The rows survive as "what this
  plan unlocks", inside the card, where they belong.
- **Pause and resume act on the whole membership.** `POST /admin/members/pause`
  and `/resume` keep their request shape and still accept `grant_id`, but now
  resolve it to its membership. Half-paused access is no longer reachable.
- **A verdict instead of three counters.** The header answers the question
  people arrive with, including whether a subscription is still going to renew.
- **Provenance you can follow.** Order and subscription sources link into
  FluentCart, and a source that cannot be resolved shows its identifier without
  a link that leads nowhere.
- **Provider state per membership, on request.** Checking calls the providers,
  so it happens when asked and never on page load. WordPress reports as local
  and uncertified providers say so, rather than displaying a reassuring tick.
- **One history.** Grant history and activity merged into a single timeline with
  descriptions written for a reader. Revocations and pauses now appear at all —
  they were read from fields that never existed.
- **Extensions are recorded and presettable.** Extending writes an audit record,
  and `+1 month` / `+1 year` are measured from the current expiry, so extending
  an unexpired membership cannot shorten it.
- **An access check that refuses to guess.** Pick protected content and the real
  evaluator answers, with its reason. It states plainly that URL patterns and
  menu protection are decided at request time and not covered.
- **Access that has not started is called scheduled.** The member list used to
  file it under Ended, offer no filter that could find it, and label the row
  Active — because the row status was an alphabetical minimum over a column.
  Summary, filter, and row now derive the same status the profile does. The
  Scheduled tile appears only when there is scheduled access to report.
- **Picking an expiry date works again.** Every membership date picker emits
  `YYYY-MM-DD` while the REST layer has only ever accepted `Y-m-d H:i:s`, so
  extending, bulk-extending, and granting with an expiry all failed with
  "Invalid parameter: expires_at" the moment anyone touched the calendar. A
  picked day now becomes the last second of that day, so the member keeps the
  whole of it.
- **Bulk export writes one row per membership**, matching the filtered export
  and the list instead of repeating a member once per protected resource. Both
  exports now carry the same columns and the same derived status, and plan
  titles are read in one query rather than one per grant.
- Profile loading no longer issues a query per grant, and stopped shipping fifty
  audit entries the admin app never read.

## 1.4.3 - 2026-08-06

- Add every eligible Space from a FluentCommunity Space Group to a plan in one action.
- Show linked FluentCart products as soon as an existing plan opens.
- Prevent duplicate product connections and keep the list accurate after changes.
- Provide clear retry actions when linked products or plan members cannot be loaded.
- Improve delayed-access previews and give content-rule controls more breathing room.

## 1.4.2 - 2026-08-06

- Fixed automatic plan slugs for Polish accents and other international scripts.
- Made WordPress the single authority for editor previews and persisted slugs.
- Added collision-aware previews, empty-slug rejection and safe 100-byte bounds.

## 1.4.1 - 2026-07-26

- Prepared WordPress.org identity, dependency and package metadata.
- Replaced the remote Inter stylesheet with licensed local font subsets.
- Removed the legacy GitHub updater.
- Documented opt-in webhooks, authenticated REST access, privacy and build inputs.

## 1.4.0 - 2026-07-24

Eighteen Memberships commits landed in 72 hours. Apparently the reasonable
response to “the plugin could feel nicer” was to rebuild nearly every important
journey.

- **A redesigned membership workspace** — a clearer dashboard, consistent
  navigation, useful status summaries, better mobile layouts, and visible work
  that needs attention.
- **Guided setup** — new plan and content-protection builders turn large forms
  into short, reviewable steps with live summaries and friendlier search.
- **Better member care** — improved member discovery, profiles, bulk actions,
  activity history, Community context, and safer imports and exports.
- **Notification Studio** — branded lifecycle emails now have a visual editor,
  realistic previews, test sends, shared styles, and a per-message choice
  between built-in delivery, FluentCRM, or silence.
- **Healthier integrations** — FluentCRM and FluentCommunity status, drift, and
  recovery are visible in one workspace, with supported Pro capabilities
  appearing only when they can actually work.
- **More dependable access** — purchases, subscriptions, manual access, plan
  relationships, drip schedules, grace periods, and provider access now retain
  clearer ownership and recover more safely when external systems misbehave.
- **Production-ready connections** — guided API setup, one-time credentials,
  independent webhook endpoints, required testing before activation, delivery
  history, automatic retries, manual recovery, pausing, and cancellation.
- **Stronger release confidence** — expanded PHP, browser, JavaScript, runtime,
  documentation, route, workflow, and package checks now guard the release
  before a ZIP reaches users. Revolutionary concept, admittedly.

### Connection note

Existing external connections should open the new **Webhooks & API** guide
before updating. It walks through the safer credential flow and refreshed
setup without requiring a small archaeology degree.

## 1.3.1

- Added date-range filtering to drip and report insights, plus member search in the admin tools.
- Improved CSV imports with escaped-field handling and made content-protection messages more specific.

## 1.3.0

- Added membership terms for fixed end dates and annual or custom durations, with plan-editor support and lifecycle handling.

## 1.2.0

- Added monthly billing anchors, including calendar-day validation and month-end handling.
- Overdue anchored grants are paused instead of expired, so a late renewal can resume access.

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
