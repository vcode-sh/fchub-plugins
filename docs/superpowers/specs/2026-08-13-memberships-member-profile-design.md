# Memberships Member Profile Design

## Goal

Make `#/members/{id}` answer the three questions that bring an administrator to
it — can this person get in, what should I change, and what already happened —
without the screen contradicting itself, the member list, or the database.

## The job

One sentence: **decide whether this member has the access they paid for, and fix
it here when they do not.**

Three confirmed reasons to open the screen: support triage, manual membership
administration, and audit. Customer value and revenue context are explicitly out
of scope; the source link exists because triage and audit need provenance, not
because the screen reports on sales.

## Current defects

These are findings from the code and from playground data, not preferences.

### The screen renders storage rows, not memberships

`GrantRepository::getByUserId()` returns one row per plan *rule*, because
`PlanGrantExecutionService` writes one grant row per resource the plan unlocks.
Playground user 1 holds one plan and two rows (`category` and `post`), so the
profile draws two identical cards and reports `Grant history: 2 total`. The
member list groups by `(user_id, plan_id)` and reports one. Same data, two
answers, and the profile's answer is the one nobody asked for.

### One card, two action scopes

`handleRevoke` and `handleExtend` send `plan_id`, so they act on every row of the
membership. `handlePause` and `handleResume` send `grant_id`, and
`GrantStatusService::pauseGrant()` updates exactly one row. Pausing one of two
cards therefore suspends the category and leaves the post open — a half-paused
membership the interface has no way to represent, reachable by one click on a
control that looks identical to the ones beside it.

### Dead event sources

`MemberController::activity()` reads `$grant['revoked_at']` and
`$grant['paused_at']` as top-level keys. Neither is a column;
`revoked_at` is never written at all, and `pauseGrant()` writes `paused_at`
inside `meta`. The `grant_revoked` and `grant_paused` branches have therefore
never produced an event. `MemberProfileGrantHistory` reads the same absent
`grant.revoked_at` for "Access ended" and silently falls back to `expires_at`,
so a revoked grant displays the date it would have expired had nobody revoked
it.

### Audit entries that describe nothing

Audit events render as `Created by user #1`. The record carries `actor_id`,
`actor_type`, `context`, `old_value` and `new_value`; none of it reaches the
reader.

### Deduplication on the wrong axis

Events are keyed `date|type`. Two distinct events in the same second collapse
into one, and duplicates originating from separate rows of the same membership
survive whenever their timestamps differ.

### Two surfaces for one drip timeline

`MemberProfileDripSchedule` renders `plans[].progress` from the profile payload
while `MemberProfileDripTimelineDrawer` fetches `/drip-timeline` for the same
plan. Two components, two data sources, one fact.

### Counters that answer nothing

The hero shows `Active access`, `Grant history` and `Activity` totals. None is
the question an administrator arrives with. `buildMemberProfileSummary()` also
computes `lifetimeCount`, which no template renders.

### Wasted queries

`show()` calls `PlanRepository::find()` inside a loop over grants and
`AuditLogRepository::getByEntity()` once per grant, then returns a 50-entry
`audit_log` array that the admin app never reads — it calls `/activity`
separately. `activity()` repeats both loops.

## Model

The screen speaks only of **memberships**. A membership is a plan held by a
member; grant rows are an implementation detail and appear only inside a card,
as the resources the plan unlocks. A grant with `plan_id NULL` — written by
`AccessGrantService::grantResource()` — is its own card labelled Direct grant,
because there the row genuinely is the unit.

Grouping key: `plan_id` when present, otherwise the grant id.

A membership's status is derived from its rows: active if any row is currently
active, otherwise paused, revoked, or expired in that order. This matches the
`access_status` expression already used by `GrantRepository::getAdminSummary()`,
so the profile and the list finally agree.

It adds one value that expression lacks. A row whose `starts_at` is in the future
is not expired, and the codebase already treats that state as distinct:
`GrantRepository::grantIsCurrentlyAccessible()` and
`MembershipAccountProjector::isCurrentAt()` both exclude it from current access.
The profile reports it as `scheduled`, which is what it is.

The member list learns the same word, because a count nobody can reach is not an
improvement. Three places were wrong in different directions:

- `getAdminSummary()` had no scheduled branch, so an unstarted membership fell
  through to `expired` and was counted under **Ended access**.
- `buildAdminMemberWhere()` had no scheduled filter. Such a membership was
  excluded from Active (correctly) and from Expired (its `status` column reads
  `active`), so no filter could reach it.
- `getMembers()` selected `MIN(g.status)` — an alphabetical minimum over a
  status column, which returned `active` for an unstarted membership and
  `expired` for a paused-and-expired one. The list row and the summary tile
  above it could disagree about the same membership.

All three now derive status from one `accessStatusCase()` expression in the same
precedence the profile uses: active, scheduled, paused, revoked, expired. The
summary gains a `scheduled` total, the filter gains the matching option, and the
list row reports what the membership is rather than which status sorts first.

The Scheduled tile appears only when the count is non-zero. Scheduled access is
rare; a permanent tile reading zero is a decision cost with no reader.

## Screen

### Hero — verdict

Avatar from the `avatar_url` the endpoint already returns, display name linking
to `user-edit.php`, email, registration date. Beneath it a single sentence:

- `Active in 2 plans · earliest ends 12 September`
- `No access · last expired 3 March`

Where a membership originates from a subscription, the sentence carries the
renewal fact, because "active today, cancelled subscription" is the most common
shape of a support ticket that has not happened yet.

The three counters are removed. Their content moves: the active count into this
sentence, the history and activity counts into the timeline section header.
`lifetimeCount` is deleted outright — no template consumed it.

Actions stay as they are: Grant access, Revoke all.

### Memberships

One card per membership, carrying:

- status and term (`Active until 12 September`, `Lifetime`,
  `Paused since 3 March`)
- provenance as a link — `Order #123` resolves to
  `admin.php?page=fluent-cart#/orders/123/view`. Subscription-sourced
  memberships additionally show subscription status and next billing date read
  from `fct_subscriptions`, and link to the parent order. Manual grants name the
  actor and date. When a source cannot be resolved, the identifier renders as
  plain text; the screen never fabricates a link.
- a provider strip, described below
- actions: Pause, Resume, Extend, Revoke — every one of them scoped to the whole
  membership

Expanding a card discloses the resources the plan unlocks with their per-row
state, the drip timeline for that plan loaded from the existing
`/drip-timeline` route, and a Check providers control.

Extend offers `+1 month`, `+1 year`, and a custom date, all computed from the
current expiry rather than from today, so extending an unexpired membership
never shortens it.

### Provider state

Read on demand only. Live observation means real calls into FluentCommunity and
FluentCRM, and a page load is not a reason to make them.

`ProviderReconciliationService` already classifies a resource and names its
repair action. It gains one public method that classifies every edge belonging
to a user, reusing the same private `classify()`. The screen reads it through a
new read-only route and repairs through the existing
`/admin/provider-reconciliation/repair`, so no new mutation surface, no new
idempotency contract.

The strip states what is actually knowable. `classify()` returns `local_only`
for `wordpress_core` and `provider_uncertified` for `learndash`, so those render
as exactly that rather than as a reassuring tick. Only FluentCommunity and
FluentCRM can report drift, and only they get a verdict.

### Timeline

Grant history and Activity merge into one section with a type filter.

Events are keyed by `(membership, type, date)` so rows of the same membership
collapse and genuinely distinct events in the same second survive.

Descriptions are written for a reader: `Extended to 31 December by tomrobak`,
built from the audit record's `actor_id`, `actor_type`, `old_value` and
`new_value`. Revocation and pause timestamps come from the audit log and from
`meta.paused_at`, which is where they actually live; the dead top-level reads
are removed rather than kept as decoration.

Extending never wrote an audit record at all, so an extension left no trace
between a grant and its new expiry. `GrantMaintenanceService::extendExpiry()`
now records `extended` with the old and new dates.

Memberships older than the audit log — imports, and anything created before a
change was audited — would otherwise show an empty history. Where no audit
record covers it, the timeline falls back to the dates the grant row itself
stores: `created_at` becomes the grant event and, for an expired membership,
`expires_at` becomes the expiry. Both are recorded columns, so this reports
rather than reconstructs. A revocation date exists only in the audit log and is
never derived; a membership revoked without an audit record shows when it
started and nothing more.

### Access check

The screen answers "why can this member not see X" by evaluating a protected
resource, not a pasted URL.

A URL check would have to reproduce a decision that lives inside request-bound
handlers — `UrlProtection::checkUrlProtection()`,
`ContentProtection::templateRedirect()`, menu and taxonomy protection — and any
reproduction drifts from the original. A tool that answers confidently and
wrongly is worse than no tool.

Instead the administrator picks protected content through the existing
`GET /admin/content` search, and the answer comes from
`AccessEvaluator::evaluate()` — the same evaluator the front end uses. Its reason
codes map directly to sentences:

| Reason | Sentence |
| --- | --- |
| `admin_bypass` | Can see it, as an administrator — this tells you nothing about ordinary members |
| `plan_grant` / `direct_grant` / `wildcard_grant` | Can see it, through *plan* |
| `drip_locked` | Not yet — drip unlocks it on *date* |
| `membership_paused` | No — the membership is paused |
| `no_grant` | No — no membership covers this |

The panel states its boundary in the interface: it covers rule-backed resources,
not URL patterns or menus.

This section needs no new endpoint.

## Server changes

`GET /admin/members/{user_id}` returns memberships instead of raw rows. It stops
returning `audit_log`, which the app never read, and `progress`, now loaded on
demand by the drip route it duplicated. `PlanRepository::findMany()` replaces the
per-grant `find()`; a new `AuditLogRepository::getByEntityIds()` replaces the
per-grant audit read.

`GET /admin/members/{user_id}/activity` returns composed timeline events with
correct keys, real descriptions, and membership-level deduplication.

`POST /admin/members/pause` and `/resume` keep their documented request shape.
They still accept `grant_id`; they now resolve it to its membership and act on
every row. External callers keep working and stop producing half-paused
memberships. This is a behavioural change to a documented endpoint and belongs in
the changelog and the developer reference.

`GET /admin/members/{user_id}/provider-state` is the only new route, and it is a
read.

New domain units, each pure and testable without a WordPress request:

- `MembershipGrouper` — grant rows to memberships
- `GrantSourceResolver` — source type and id to label, link, and subscription facts
- `MemberTimelineComposer` — grants and audit records to described events

`MemberController` keeps routing and validation and hands the rest to these.

## Success criteria

- Playground user 1 renders one membership card, and the profile agrees with the
  member list.
- A membership whose `starts_at` is in the future reports as scheduled on the
  profile, in the list row, in the summary, and through the status filter — and
  is counted under none of active, paused, or ended.
- Pausing a membership pauses every row it contains; the interface cannot reach a
  partially paused membership.
- A revoked membership shows the date it was revoked, not the date it would have
  expired.
- An order-sourced membership links to its FluentCart order; an unresolvable
  source renders without a link.
- Provider state is fetched only when asked for, reports `local_only` and
  `provider_uncertified` as themselves, and repairs through the existing route.
- The access check reports the evaluator's reason for a chosen protected
  resource, and states in the interface what it does not cover.
- `show()` issues a constant number of queries regardless of grant count.
- PHPUnit, Vitest, the admin build, and the member profile smoke lane pass.

## Test intent

New coverage: grouping rows into memberships including the `plan_id NULL` case;
membership status derivation across mixed row states; pause covering every row;
source resolution when the order is absent; timeline deduplication keeping
same-second distinct events; evaluator reasons for paused and drip-locked
resources; extension computed from current expiry rather than today.

Rewritten: `member-profile-ui.test.js` (its subject `buildMemberProfileSummary`
is gone), `member-profile-composables.test.js`, and the member profile smoke
spec.

Deleted: assertions covering the standalone drip panel and drip drawer, whose
subject moves inside the membership card.

## Non-goals

Revenue and customer-value reporting. URL-based access checking, and the
extraction of protection decisions into a request-independent service that it
would require. Redesign of the plans or content screens. The member list keeps
its layout; only its access-status derivation, summary, and filter change, and
only so that it and the profile stop contradicting each other.
