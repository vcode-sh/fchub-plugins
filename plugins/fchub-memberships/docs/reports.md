# Reports & Analytics

The plugin tracks membership metrics and provides reports through both the admin dashboard (REST API) and WP-CLI.

## Dashboard Overview

The overview gives you a quick snapshot:

| Metric | What It Shows |
|--------|---------------|
| **Active Members** | Total members with active grants right now |
| **Active Plans** | Number of published plans |
| **Protected Content** | Number of protection rules in place |
| **Grants This Month** | New grants created this month |
| **New This Month** | New unique members this month |
| **Churned This Month** | Members who left this month |
| **Churn Rate** | Percentage of members lost this month |

---

## Member Stats

### Members Over Time
A timeline of active member counts. Uses daily snapshots from the stats cron, so you get actual historical data — not just a current count.

Supports periods: `30d`, `6m`, `12m`.

### Plan Distribution
How members are spread across your plans. Shows unique active members per plan.

---

## Revenue

### Revenue Per Plan
Monthly revenue broken down by plan. Joins grants to FluentCart orders to calculate how much each plan earns.

### Monthly Recurring Revenue (MRR)
Sums the recurring amounts from all active subscriptions linked to membership grants. Normalizes to monthly: yearly subscriptions are divided by 12, quarterly by 3.

### Average Revenue Per Member (ARPM)
Total revenue from membership orders divided by active member count.

### Lifetime Value (LTV) Per Plan
Average total revenue per member for each plan. Tells you which plans generate the most value over time.

---

## Churn

### Current Churn Rate
`churned members / active members at period start * 100`

The default period is 30 days. "Churned" means the member's grant moved to expired or revoked status.

### Churn Over Time
Monthly churn rates from the daily stats table. Shows the trend so you can spot if things are getting better or worse.

### Retention Cohort
Groups members by the month they joined and tracks what percentage remain active in each subsequent month.

**Reading the cohort table:** If the "2025-06" cohort shows 85% retention at month 3, that means 85% of members who joined in June 2025 were still active 3 months later.

This is probably the most powerful report — it tells you exactly where members drop off.

---

## Content Popularity

### Most Accessed Content
Resources ranked by how many active members have access. Shows you what's popular.

### Least Accessed Content
Protected resources with the fewest members. Could mean the content needs promotion, or it's too niche.

### Drip Completion Rates
For plans with drip content: what percentage of members have unlocked everything. Low completion rates might mean your drip schedule is too aggressive or the content needs improvement.

### Content Plan Overlap
Content that appears in multiple plans. Helpful for understanding your content strategy and avoiding redundancy.

---

## Renewal & Trial Reports

### Renewal Rate
- Overall renewal percentage across all members
- Average renewals per member (for those who've renewed at least once)
- Breakdown by plan
- Monthly renewal volume over the last 12 months

### Trial Conversion
- Overall trial-to-paid conversion rate
- Total trials, conversions, and drops
- Conversion rate by plan

---

## Expiring Soon

Lists members whose grants expire within a specified number of days. Useful for proactive outreach beyond what the automated emails cover.

Default: 7 days, 10 results. Both configurable via the API.

---

## Daily Stats Aggregation

A daily cron job (`fchub_memberships_daily_stats`) snapshots key metrics into the `wp_fchub_membership_stats_daily` table:

- Active count per plan
- New members per plan
- Churned members per plan
- Revenue per plan

Plus a totals row (plan_id = 0) for site-wide numbers.

This is what powers the historical charts. The cron runs once daily and stores:

| Column | What It Tracks |
|--------|---------------|
| `stat_date` | The date |
| `plan_id` | Plan ID (0 = totals) |
| `active_count` | Active members on that day |
| `new_count` | New members that day |
| `churned_count` | Churned members that day |
| `revenue` | Revenue from new orders that day |

---

## REST API Endpoints

All report endpoints require `manage_options` capability and use the `fchub-memberships/v1` namespace.

| Endpoint | Parameters | Returns |
|----------|------------|---------|
| `GET /admin/reports/overview` | — | Dashboard snapshot |
| `GET /admin/reports/members-over-time` | `period` (default: 12m) | Timeline data |
| `GET /admin/reports/plan-distribution` | — | Members per plan |
| `GET /admin/reports/churn` | `period` (default: 12m) | Churn rate + monthly trend |
| `GET /admin/reports/retention-cohort` | `months` (default: 6) | Cohort retention table |
| `GET /admin/reports/revenue` | `period` (default: 12m) | Revenue per plan, MRR, ARPM, LTV |
| `GET /admin/reports/content-popularity` | — | Most/least accessed, drip rates, overlap |
| `GET /admin/reports/expiring-soon` | `days` (default: 7), `limit` (default: 10) | Expiring grants list |
| `GET /admin/reports/renewal-rate` | — | Renewal metrics |
| `GET /admin/reports/trial-conversion` | — | Trial conversion metrics |
