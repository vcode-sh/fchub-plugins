# Plans & Access Control

Plans are the core building block. A plan defines what content a member can access and for how long.

## Plan Settings

| Setting | What it does |
|---------|--------------|
| **Title** | The plan name members will see |
| **Slug** | URL-friendly identifier (auto-generated) |
| **Duration Type** | `lifetime` (never expires) or `fixed_days` (X days) |
| **Duration Days** | Number of days for fixed duration plans |
| **Trial Days** | Free trial period before payment required (0 = no trial) |
| **Grace Period Days** | Days to keep access after subscription cancellation |
| **Status** | `active`, `draft`, `scheduled`, `archived` |

## Plan Statuses

- **Active** — available for purchase and grants
- **Draft** — work in progress, not available
- **Scheduled** — will auto-activate on a set date (checked hourly via cron)
- **Archived** — no longer available, existing members keep access

## Content Rules

Each plan has one or more **content rules**. A rule connects the plan to specific content through a **provider** and **resource type**.

### Providers

| Provider | Resource Types | Requires |
|----------|---------------|----------|
| `wordpress_core` | Posts, pages, custom post types | Nothing (built-in) |
| `learndash` | Courses, lessons | LearnDash plugin |
| `fluent_community` | Spaces, courses | FluentCommunity plugin |
| `fluent_crm` | Contact segments | FluentCRM plugin |

### How Rules Work

When a user gets a plan, the system loops through every rule in that plan and creates a **grant** for each one. A grant is a record that says "User X has access to Resource Y until Date Z."

```
Plan: "Pro Membership"
├── Rule 1: WordPress → All posts in category "Premium"
├── Rule 2: LearnDash → Course "Advanced Marketing"
└── Rule 3: FluentCommunity → Space "VIP Lounge"

When User buys the plan:
├── Grant 1: User → Premium posts → active until 2026-03-01
├── Grant 2: User → Advanced Marketing course → active until 2026-03-01
└── Grant 3: User → VIP Lounge space → active until 2026-03-01
```

## Grant Lifecycle

Every grant goes through a lifecycle:

```
active → paused → active (resume)
active → expired (time ran out)
active → revoked (manually or subscription cancelled)
```

Valid statuses: `active`, `paused`, `revoked`, `expired`

### What Each Status Means

- **Active** — member has full access to the resource
- **Paused** — access temporarily blocked (subscription paused or manual)
- **Revoked** — access permanently removed (cancellation, refund, or manual)
- **Expired** — time-based access ran out

## Multiple Plans Per User

A user can hold multiple plans simultaneously. Each plan grants its own set of resources independently. If Plan A and Plan B both include the same resource, the user keeps access as long as either plan is active.

## Linking Plans to FluentCart Products

Plans don't sell themselves — you link them to FluentCart products through **integration feeds**. See [FluentCart Integration](fluentcart-integration.md) for details.
