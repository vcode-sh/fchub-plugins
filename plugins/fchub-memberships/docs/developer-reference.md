# Developer Reference

Technical reference for extending, integrating with, or debugging FCHub Memberships.

## Action Hooks

The plugin fires actions at every stage of the membership lifecycle. Hook into these to build custom integrations or extend functionality.

### Grant Lifecycle

| Hook | Arguments | When It Fires |
|------|-----------|---------------|
| `fchub_memberships/grant_created` | `int $userId, int $planId, array $context` | User gets access to a plan |
| `fchub_memberships/grant_revoked` | `array $grants, int $planId, int $userId, string $reason` | Membership revoked |
| `fchub_memberships/grant_expired` | `array $grant` | Grant reaches expiry date |
| `fchub_memberships/grant_paused` | `array $grant, string $reason` | Membership paused |
| `fchub_memberships/grant_resumed` | `array $grant` | Paused membership reactivated |
| `fchub_memberships/grant_renewed` | `array $grant, int $planId, int $userId` | Subscription renewal extends grant |
| `fchub_memberships/grant_expiring_soon` | `array $grant, int $daysLeft` | Daily cron: grant expiring within notice window |
| `fchub_memberships/grant_anniversary` | `array $grant, int $milestoneDays` | Daily cron: grant reaches day milestone |

### Trial Lifecycle

| Hook | Arguments | When It Fires |
|------|-----------|---------------|
| `fchub_memberships/trial_started` | `array $grant` | Trial period begins |
| `fchub_memberships/trial_expired` | `array $grant` | Trial ends without payment |
| `fchub_memberships/trial_expiring_soon` | `array $grant, int $daysLeft` | Daily cron: trial ending soon |
| `fchub_memberships/trial_converted` | `array $grant` | Trial converts to paid |

### Plan Lifecycle

| Hook | Arguments | When It Fires |
|------|-----------|---------------|
| `fchub_memberships/plan_created` | `array $plan` | New plan created |
| `fchub_memberships/plan_updated` | `array $plan` | Plan settings updated |
| `fchub_memberships/plan_deleted` | `int $planId` | Plan deleted |
| `fchub_memberships/plan_replaced` | `array $oldGrant, array $newGrant` | Multi-membership: plan replaced |
| `fchub_memberships/plan_upgraded` | `array $oldGrant, array $newGrant` | Multi-membership: plan upgraded |
| `fchub_memberships/plan_status_scheduled_change` | `array $plan, string $newStatus` | Scheduled status change executed |

### Content & Drip

| Hook | Arguments | When It Fires |
|------|-----------|---------------|
| `fchub_memberships/drip_unlocked` | `array $notification, array $grant` | Drip content becomes available |
| `fchub_memberships/drip_milestone_reached` | `array $grant, int $percentage` | Drip completion crosses threshold |

### Payment

| Hook | Arguments | When It Fires |
|------|-----------|---------------|
| `fchub_memberships/payment_failed` | `array $grant, int $subscriptionId, array $event` | Payment failure on linked subscription |

---

## Database Tables

The plugin creates 8 custom tables (all prefixed with `wp_fchub_membership_`):

| Table | Purpose |
|-------|---------|
| `_plans` | Plan definitions (title, slug, status, settings) |
| `_plan_rules` | Content rules per plan (provider, resource type/ID, drip config) |
| `_grants` | User access records (the core of the system) |
| `_drip_notifications` | Scheduled drip unlock notifications |
| `_protection_rules` | Content protection settings (mode, redirect URL, teaser) |
| `_audit_log` | Audit trail for grant changes |
| `_stats_daily` | Daily aggregated metrics per plan |
| `_grant_meta` | Additional metadata on grants (milestones, etc.) |

### Key Grant Fields

| Field | Type | Description |
|-------|------|-------------|
| `user_id` | int | WordPress user ID |
| `plan_id` | int | Plan ID |
| `provider` | string | Access provider (wordpress_core, learndash, etc.) |
| `resource_type` | string | Resource type (post, page, course, space, etc.) |
| `resource_id` | string | Resource identifier |
| `source_type` | string | How grant was created (order, subscription, manual, trial) |
| `source_id` | int | FluentCart order or subscription ID |
| `grant_key` | string | Unique key (user:provider:type:id) |
| `status` | string | active, paused, expired, revoked |
| `expires_at` | datetime | When access expires (null = never) |
| `trial_ends_at` | datetime | When trial period ends |
| `drip_available_at` | datetime | When drip content unlocks |
| `renewal_count` | int | Times this grant has been renewed |
| `cancellation_effective_at` | datetime | Grace period end date |

---

## REST API

All endpoints use namespace `fchub-memberships/v1`.

### Admin Endpoints (require `manage_options`)

**Plans:** Standard CRUD at `/admin/plans/`
**Members:** Grant management at `/admin/members/` (includes export endpoints)
**Content:** Protection rules at `/admin/content/`
**Drip:** Drip schedules at `/admin/drip/`
**Settings:** Global config at `/admin/settings/`
**Reports:** See [Reports docs](reports.md) for the full list

### Frontend Endpoints

**Access Check:** `GET /access/check` — check if current user has access to a resource
**Account:** Member-facing account endpoints at `/account/`

### Member Export

Two endpoints for exporting member data:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/admin/members/export` | GET | Export filtered member list (by status, plan_id). Returns user info, plan, dates. |
| `/admin/members/bulk-export` | POST | Export specific users by ID. Accepts `user_ids[]` in JSON body. |

Both return JSON arrays. The WP-CLI `export-members` command outputs CSV or JSON files directly.

### Dynamic Options

`/options/plans` — Plan dropdown options for UI selects
`/options/content` — Content type/ID options

---

## WP-CLI Commands

All commands are under `wp fchub-membership`.

### list-grants
List grants for a user.
```
wp fchub-membership list-grants --member=<id|email> [--status=<status>] [--plan=<slug>] [--format=table|json]
```

### grant
Grant a plan to a user.
```
wp fchub-membership grant --member=<id|email> --plan=<slug> [--expires=YYYY-MM-DD] [--source=manual]
```

### revoke
Revoke a plan from a user.
```
wp fchub-membership revoke --member=<id|email> --plan=<slug> [--reason="text"]
```

### revoke-by-order
Revoke all grants linked to a FluentCart order.
```
wp fchub-membership revoke-by-order --order=<id> [--dry-run]
```

### check
Check if a user has access to a plan or resource.
```
wp fchub-membership check --member=<id|email> --plan=<slug>
wp fchub-membership check --member=<id|email> --resource-type=post --resource-id=100
```

### backfill
Create grants from historical orders for a product. Useful when adding membership integration to a product that already has sales.
```
wp fchub-membership backfill --product=<id> [--dry-run] [--limit=100]
```

### sync
Sync grants for an integration feed or plan. Detects and revokes orphaned grants (grants for rules that no longer exist in the plan).
```
wp fchub-membership sync --feed=<id> [--dry-run]
wp fchub-membership sync --plan=<slug> [--dry-run]
```

### expire-check
Find and expire overdue grants.
```
wp fchub-membership expire-check [--dry-run]
```

### drip-process
Process pending drip notifications manually.
```
wp fchub-membership drip-process [--dry-run]
```

### purge-expired
Delete expired/revoked grants older than a threshold. Good for database cleanup.
```
wp fchub-membership purge-expired [--older-than=90] [--dry-run]
```

### debug
Debug access evaluation for a user and URL. Shows protection rules, matching grants, drip status — everything the access engine considers.
```
wp fchub-membership debug --member=<id|email> --url=/premium-course/
```

### stats
Show membership statistics in the terminal.
```
wp fchub-membership stats [--plan=<slug>] [--period=30d] [--format=table|json]
```

### export-members
Export plan members to a file.
```
wp fchub-membership export-members --plan=<slug> --format=csv|json --output=/path/to/file
```

---

## Access Adapters

The plugin uses an adapter pattern for granting access to different systems. Each adapter implements `AccessAdapterInterface`:

| Adapter | What It Controls |
|---------|-----------------|
| **WordPress Core** | Posts, pages, custom post types, taxonomies |
| **LearnDash** | Courses, lessons, topics |
| **FluentCRM** | Tags, lists (adds/removes based on membership status) |
| **FluentCommunity** | Community spaces, courses (if FluentLMS module is enabled) |

### Creating a Custom Adapter

Implement `AccessAdapterInterface` with these methods:

- `getProviderKey(): string` — unique identifier (e.g., "my_plugin")
- `getResourceTypes(): array` — list of resource types you handle
- `grantAccess(int $userId, string $resourceType, string $resourceId): void`
- `revokeAccess(int $userId, string $resourceType, string $resourceId): void`
- `hasAccess(int $userId, string $resourceType, string $resourceId): bool`

Register your adapter using the `fchub_memberships/resource_types` action.

---

## Cron Jobs

| Schedule | Hook | Purpose |
|----------|------|---------|
| Every 5 minutes | `fchub_memberships_validity_check` | Check FluentCart subscription validity |
| Hourly | `fchub_memberships_drip_process` | Process drip notifications |
| Hourly | `fchub_memberships_plan_schedule` | Execute scheduled plan status changes |
| Daily | `fchub_memberships_expiry_notify` | Send expiring-soon emails + fire hooks |
| Daily | `fchub_memberships_daily_stats` | Aggregate stats for reports |
| Daily | `fchub_memberships_trial_check` | Check trial expirations + conversions |
| Weekly | `fchub_memberships_audit_cleanup` | Clean audit log entries older than 90 days |

All cron jobs are registered on plugin activation. Use `wp cron event list` to verify they're scheduled.

---

## FluentCart Integration

The plugin registers as a FluentCart integration using the feed system:

- **Integration key:** `memberships`
- **Product-level feeds:** Stored in `fct_order_integration_feeds` with `integration_key = 'memberships'`
- **Hooks listened:** FluentCart order and subscription events at priority 20

See [FluentCart Integration](fluentcart-integration.md) for the full event lifecycle.
