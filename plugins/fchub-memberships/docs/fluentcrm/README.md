# FluentCRM Automation

This is where it gets powerful. The plugin integrates deeply with FluentCRM's automation funnel system, giving you 15 triggers, 7 actions, 7 benchmarks, 26 smart codes, and 6 segment filters.

You can build sophisticated automated funnels for virtually any membership scenario — from simple welcome sequences to complex dunning flows with auto-generated coupons.

## What's Inside

| Doc | What it covers |
|-----|----------------|
| [Triggers](triggers.md) | 15 events that start or continue funnels |
| [Actions](actions.md) | 7 things funnels can do to memberships |
| [Benchmarks](benchmarks.md) | 7 goals that funnels can wait for |
| [Smart Codes](smart-codes.md) | 26 dynamic values for email templates |
| [Segment Filters](filters.md) | 6 ways to filter contacts by membership data |
| [Funnel Examples](funnel-examples.md) | Real-world funnel recipes |

## How It All Fits Together

FluentCRM funnels work with three building blocks:

1. **Triggers** start a funnel (e.g., "Membership Expiring Soon")
2. **Actions** do things inside the funnel (e.g., "Create Coupon", "Grant Membership")
3. **Benchmarks** are goals to wait for (e.g., "Payment Recovered", "Membership Resumed")

The plugin also provides **smart codes** — dynamic placeholders you use in email templates — and **segment filters** for targeting specific groups of contacts.

## Quick Example

Here's what a "win-back expired member" funnel looks like:

```
TRIGGER: Membership Expiring Soon (7 days before)
  → ACTION: Send email "Your membership expires in {{membership.days_remaining}} days"
  → Wait 4 days
  → BENCHMARK: Has Active Membership? (goal met = they renewed)
    → Yes: End funnel
    → No: Continue...
  → ACTION: Create FluentCart Coupon (20% off, 7-day expiry)
  → ACTION: Send email "Here's 20% off: {{membership.coupon_code}}"
  → Wait 7 days
  → BENCHMARK: Has Active Membership?
    → Yes: Send "Welcome back!"
    → No: Tag as "churned"
```

Every piece of this — the trigger, the coupon action, the smart codes, the benchmark — is provided by this plugin.

## Requirements

FluentCRM must be installed and activated. The plugin checks for the `FLUENTCRM` constant and only registers its automation components when FluentCRM is present.
