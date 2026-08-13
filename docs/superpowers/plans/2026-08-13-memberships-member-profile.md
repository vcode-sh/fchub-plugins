# Memberships Member Profile Implementation Plan

> **For agentic workers:** Follow test-driven development. Do not commit, push, tag, or release; the repository owner owns Git history.

**Goal:** Make `#/members/{id}` speak in memberships rather than storage rows, act on the unit it displays, report provenance and provider truth, and merge its two disagreeing history surfaces into one.

**Design:** [`../specs/2026-08-13-memberships-member-profile-design.md`](../specs/2026-08-13-memberships-member-profile-design.md)

**Architecture:** Grouping, source resolution, and timeline composition become pure domain units under `app/Domain/Member/`, testable without a WordPress request. `MemberController` keeps routing and validation. Provider truth reuses `ProviderReconciliationService` through one new public classification method and one new read route; repair keeps using the existing reconciliation repair route. The access check reuses `GET /admin/content` and `GET /check-access` and adds no endpoint.

**Tech Stack:** PHP 8.3, WordPress REST API, FluentCart 1.6.x, Vue 3 Composition API, Element Plus, PHPUnit, Vitest, Playwright.

## Global Constraints

- All repository artifacts and product copy are English.
- The screen's only unit is the membership. Grant rows appear solely inside an expanded card as the resources a plan unlocks.
- No control may produce a partially paused membership.
- The screen never renders a link it cannot resolve.
- Live provider observation happens on explicit request, never on page load.
- `POST /admin/members/pause` and `/resume` keep their documented request shape.
- `wordpress_core` reports as `local_only` and `learndash` as `provider_uncertified`; neither is dressed up as a health verdict.
- No commits, pushes, tags, or releases.

## Simplicity Evidence

### Hero counters

- **What it does:** `buildMemberProfileSummary()` in `pages/Members/memberProfileUi.js` counts active grants, total grants, and activity events; `MemberProfileHero` renders three tiles.
- **Who depends on it:** `MemberProfile.vue` (`profileSummary`), `MemberProfileHero` props, `tests/admin/member-profile-ui.test.js`, `tests/admin-smoke/member-profile-page.spec.js`.
- **Where it now lives:** the active count becomes the hero verdict sentence; grant and activity totals become the timeline section header. `lifetimeCount` is deleted with no replacement — it was computed and never rendered.

### Standalone drip panel

- **What it does:** `MemberProfileDripSchedule.vue` renders per-plan drip progress from the `plans[].progress` field of the profile payload.
- **Who depends on it:** `MemberProfile.vue`, the `timeline` ref in `useMemberProfile.js`, the `progress` field produced by `MemberController::show()`.
- **Where it now lives:** inside the expanded membership card, loaded from the existing `GET /admin/members/{id}/drip-timeline`. One surface replaces two; `progress` leaves the profile payload.

### Drip timeline drawer

- **What it does:** `MemberProfileDripTimelineDrawer.vue` opens the same drip timeline in an overlay when a plan title is clicked.
- **Who depends on it:** `MemberProfile.vue`, `openDripDrawer()` and four refs in `useMemberProfile.js`.
- **Where it now lives:** the same expanded card. The `/drip-timeline` route is unchanged and still called.

### Separate grant history panel

- **What it does:** `MemberProfileGrantHistory.vue` lists every grant row with granted date, end date, and source.
- **Who depends on it:** `MemberProfile.vue`, `allGrants` in `useMemberProfile.js`.
- **Where it now lives:** merged into the timeline section, one entry per membership event rather than one row per storage row.

### `audit_log` in the profile payload

- **What it does:** `MemberController::show()` collects up to 50 audit entries per member and returns them.
- **Who depends on it:** nothing in the admin app; it calls `GET /admin/members/{id}/activity`. `GET /admin/members/{id}/audit-log` remains for external callers.
- **Where it now lives:** removed from the payload. The dedicated audit-log route is untouched.

## Task 1 — Membership grouping

Create `app/Domain/Member/MembershipGrouper.php`: pure, no WordPress request access.

Input: grant rows from `GrantRepository::getByUserId()` plus a plan-title map.
Output: memberships keyed by `plan_id`, or by grant id when `plan_id` is null.

Each membership carries: `key`, `plan_id`, `plan_title`, derived `status`, `starts_at`, `expires_at` (the latest across rows), `trial_ends_at`, `source_type`, `source_id`, `renewal_count`, `resources[]` (one entry per row with `provider`, `resource_type`, `resource_id`, row `status`, `drip_available_at`), and `grant_ids[]`.

Status derivation, matching `GrantRepository::getAdminSummary()`: active when any row is active, within `starts_at`, and not past `expires_at`; otherwise paused, revoked, expired, in that order.

**Verify:** new `tests/Unit/Domain/Member/MembershipGrouperTest.php` covering two rows of one plan collapsing to one membership, null-plan rows staying separate, mixed row states resolving in precedence order, and `expires_at` taking the latest row value. Run `./vendor/bin/phpunit --filter MembershipGrouper`.

## Task 2 — Source resolution

Create `app/Domain/Member/GrantSourceResolver.php`.

Input: `source_type`, `source_id`, and the membership's audit records.
Output: `label`, `url` or null, and for subscriptions `subscription_status`, `next_billing_date`, `canceled_at`.

- `order` → `admin.php?page=fluent-cart#/orders/{id}/view` when the order exists.
- `subscription` → read `fct_subscriptions` for status, `next_billing_date`, `canceled_at`, and link the `parent_order_id` order.
- `manual` → actor name and date from the creating audit record.
- `trial`, `import`, unknown types, and unresolvable ids → label only, `url` null.

Reads go through a small injected gateway so tests do not need FluentCart tables.

**Verify:** `tests/Unit/Domain/Member/GrantSourceResolverTest.php` covering a resolvable order, a missing order, a cancelled subscription with a future `next_billing_date`, a manual grant naming its actor, and an unknown source type. No case may return a URL it did not resolve.

## Task 3 — Timeline composition

Create `app/Domain/Member/MemberTimelineComposer.php`.

Input: memberships, audit records, drip notifications.
Output: events with `date`, `type`, `membership_key`, `plan_title`, human `description`, and `metadata`.

- Deduplicate on `(membership_key, type, date)`.
- Descriptions read the audit record: action, `actor_type`, resolved actor name, `context`, and the changed field from `old_value`/`new_value` — `Extended to 31 December by tomrobak`, not `Updated by user #1`.
- Revocation and pause dates come from audit records and `meta.paused_at`. Delete the `revoked_at` and `paused_at` top-level reads in `MemberController::activity()`; neither key has ever existed.

Add `AuditLogRepository::getByEntityIds(string $entityType, array $entityIds, int $limit)` — one query for all of a member's grants.

**Verify:** `tests/Unit/Domain/Member/MemberTimelineComposerTest.php` covering two rows of one membership collapsing to one event, two genuinely distinct events in the same second both surviving, a revocation dated from its audit record, and a described extension. Plus `tests/Unit/Storage/AuditLogRepositoryBatchTest.php` for the batch read.

## Task 4 — Membership-scoped pause and resume

`GrantStatusService::pauseGrant()` and `resumeGrant()` resolve the given grant to its membership and act on every row of it, reporting a single aggregate result. Rows already in the target state are skipped rather than failing the operation.

Request shape is unchanged: `grant_id` in the body.

**Verify:** extend `tests/Unit/Http/Controllers/MemberMutationRestTest.php` — pausing one row of a two-row membership pauses both, resuming does the same, and a membership already paused stays idempotent. Existing single-row cases must keep passing.

## Task 5 — Profile and activity endpoints

Rewrite `MemberController::show()` to return `user` and `memberships` composed by Tasks 1–2. Remove `audit_log` and `progress`. Replace the per-grant `PlanRepository::find()` with `findMany()` and the per-grant audit read with `getByEntityIds()`.

Rewrite `MemberController::activity()` to delegate to `MemberTimelineComposer`, keeping its pagination contract.

**Verify:** `MemberControllerContractTest` asserts the new payload shape, the absence of `audit_log` and `progress`, and a query count that does not grow with grant count. `MemberControllerActivityExportTest` is updated for composed events.

## Task 6 — Per-user provider state

Add `ProviderReconciliationService::classifyForUser(int $userId): array`, reusing the existing private `classify()`. Add `EntitlementEdgeRepository::getActiveByUser(int $userId)`.

Register `GET /admin/members/{user_id}/provider-state`, read-only, admin permission, no idempotency header. It returns per resource: `provider`, `resource_type`, `resource_id`, `classification`, `repair_action`, and the membership key it belongs to.

Repair stays on `POST /admin/provider-reconciliation/repair`.

**Verify:** `tests/Unit/Domain/Reconciliation/ProviderStateForUserTest.php` — `wordpress_core` classifies `local_only`, `learndash` classifies `provider_uncertified`, a drifted FluentCommunity resource classifies with a repair action, and a user with no edges returns an empty list rather than an error.

## Task 7 — Member profile API client and composable

`api/members.js` gains `providerState(userId)`. `api/index.js` exports `accessCheck` and `content`, which the profile now needs.

Rewrite `composables/members/useMemberProfile.js` around memberships: `memberships`, `verdict`, per-card expansion state, drip loaded on expand, provider state loaded on request, extend presets computed from current expiry. Remove `timeline`, the four drip-drawer refs, and `allGrants`.

Add `composables/members/useMemberAccessCheck.js`: protected-content search through `content.list({ search })`, evaluation through `accessCheck.check()`, reason-to-sentence mapping.

`pages/Members/memberProfileUi.js` loses `buildMemberProfileSummary` and gains `buildMemberVerdict(memberships)` and `describeAccessReason(result)`.

**Verify:** rewritten `tests/admin/member-profile-composables.test.js` and `member-profile-ui.test.js` — verdict text for no access, one membership, several memberships with the earliest expiry named, subscription renewal appearing in the verdict, extend presets computed from expiry rather than today, and each evaluator reason mapping to its sentence.

## Task 8 — Member profile components

- `MemberProfileHero.vue` — avatar from `avatar_url`, name linking to `user-edit.php`, verdict sentence, unchanged actions.
- `MembershipCard.vue` (new) — status, term, source link, provider strip, actions, expandable body holding resources, drip, and Check providers.
- `MemberProfileAccessPanel.vue` — iterates memberships, keeps its empty state.
- `MemberProfileTimeline.vue` (new) — merged history and activity with a type filter and the existing load-more.
- `MemberAccessCheckPanel.vue` (new) — content picker, verdict sentence, and a plain statement of what the check does not cover.
- `MemberProfileExtendDialog.vue` — `+1 month`, `+1 year`, custom date.
- Delete `MemberProfileDripSchedule.vue`, `MemberProfileDripTimelineDrawer.vue`, `MemberProfileGrantHistory.vue`, `MemberProfileActivityPanel.vue`.

Every file stays under the 280-line cap. Existing panel styling, spacing, and responsive breakpoints carry over; this is not a visual redesign of the workspace shell.

**Verify:** `npm run test` passes; the profile renders one card for playground user 1.

## Implementation status — 2026-08-13

Tasks 1–9 are implemented in the working tree. Verification: 1,951 PHPUnit tests
with 12,175 assertions, 377 Vitest tests across 47 files, 104 Playwright smoke
tests including nine rewritten member-profile checks, and a clean production
build. The FluentCart MCP documentation checks still pass.

Verified against real playground data (member 1, one plan, two rule rows): the
endpoint returns one membership carrying both grant ids and two resources, a
resolved FluentCart order link, and no `audit_log` or `plans` keys.

Three things the plan did not anticipate, all found during implementation and
all recorded in the design:

- The member list reported an unstarted membership under **Ended access**, no
  filter could reach it, and `getMembers()` derived the row status with
  `MIN(g.status)` — an alphabetical minimum that called the same membership
  `active`. Summary, filter, and row now share one `accessStatusCase()`
  expression in the profile's precedence. Verified against real MySQL with
  synthetic rows supplied through a read-only `UNION ALL`: two future-dated
  memberships classify as `scheduled`, including one whose sibling row is
  paused, and the existing expired membership is unchanged.

- `extendExpiry()` wrote no audit record, so extensions were invisible to the
  audit surface the screen exists to serve. It now records `extended` with the
  old and new expiry.
- Memberships predating the audit log returned an empty timeline. Where no audit
  record covers it, the timeline falls back to the dates the grant row itself
  stores — `created_at`, and `expires_at` for an expired membership. Revocation
  dates are never derived, because only the audit log holds them.

Package rebuild, commit, tag, and release remain owner actions outside this plan.

## Task 9 — Documentation and verification

Update `web-docs/content/docs/fchub-memberships/developer-reference.mdx`: pause and resume act on the membership containing the given grant; the profile payload no longer carries `audit_log` or `progress`; the new provider-state route is listed.

Add a `CHANGELOG.md` entry for the pause/resume behaviour change and the payload change.

**Verify:**

```bash
cd plugins/fchub-memberships && composer install && ./vendor/bin/phpunit
cd plugins/fchub-memberships && npm run test
cd plugins/fchub-memberships && npm run build
cd plugins/fchub-memberships && npm run test:smoke -- member-profile-page
```

The build is run once, after source verification.

## Out of scope

Revenue and customer-value panels. URL-based access checking and the extraction of protection decisions into a request-independent service. Member list, plans, and content screens. Any Git operation.
