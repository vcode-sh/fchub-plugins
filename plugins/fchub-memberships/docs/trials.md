# Trials

Let members try before they buy. Set a trial period on any plan and the system handles the rest — tracking, conversion, expiration, and notifications.

## How Trials Work

1. Set `trial_days` on a plan (e.g., 14)
2. When a member gets the plan, their grant includes a `trial_ends_at` date
3. During the trial, they have full access to all plan content
4. When the trial ends:
   - If they've paid (subscription active) → trial converts to full membership
   - If they haven't paid → trial expires and access is revoked

## Trial Events

The system fires hooks at each stage of the trial lifecycle:

| Event | Hook | When |
|-------|------|------|
| Trial started | `fchub_memberships/trial_started` | Grant created with trial period |
| Trial expiring soon | `fchub_memberships/trial_expiring_soon` | Daily cron, X days before trial ends |
| Trial converted | `fchub_memberships/trial_converted` | Payment received during trial |
| Trial expired | `fchub_memberships/trial_expired` | Trial ended without payment |

## Trial Expiring Notifications

The daily cron job (`fchub_memberships_trial_check`) runs `TrialLifecycleService::sendTrialExpiringNotifications()`. It:

1. Finds all active grants with `trial_ends_at` approaching within the notice window
2. Fires `fchub_memberships/trial_expiring_soon` for each one (so FluentCRM funnels can respond)
3. Optionally sends a trial-expiring email

The hook fires regardless of whether emails are enabled — this way your FluentCRM automations always work.

## Building Trial Funnels in FluentCRM

With the **TrialExpiringSoonTrigger**, you can build funnels like:

```
Trial expiring in 3 days
  → Send email: "Your trial ends soon!"
  → Wait 2 days
  → Check: Still in trial?
  → Yes → Send email: "Last day! Here's 20% off" + auto-generate coupon
  → No (converted) → Send email: "Welcome aboard!"
```

### Smart Codes for Trials

Use these in your FluentCRM emails:

- `{{membership.trial_ends_at}}` — formatted trial end date
- `{{membership.trial_days_remaining}}` — number of days left in trial
- `{{membership.checkout_url}}` — direct checkout link for the plan
- `{{membership.coupon_code}}` — auto-generated coupon (if you used the coupon action)

## Trial Eligibility

The system tracks whether a user has already used a trial for a specific plan. This prevents people from repeatedly signing up for free trials.
