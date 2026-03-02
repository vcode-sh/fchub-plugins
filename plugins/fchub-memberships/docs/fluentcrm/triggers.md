# Triggers

Triggers are events that start a FluentCRM funnel or continue one that's waiting. The plugin provides 15 triggers covering every stage of the membership lifecycle.

## Membership Lifecycle Triggers

### Membership Granted
**Hook:** `fchub_memberships/grant_created`

Fires when a member gets access to a plan — whether from a purchase, manual grant, or subscription activation.

**Conditions:**
- Plan filter (specific plans or any)
- Source type filter (order, subscription, manual, trial)
- Run multiple (restart funnel for repeat events)

**Use for:** Welcome sequences, onboarding emails, tagging new members.

---

### Membership Revoked
**Hook:** `fchub_memberships/grant_revoked`

Fires when a membership is revoked — cancellation, refund, or manual removal.

**Conditions:**
- Plan filter
- Run multiple

**Use for:** Exit surveys, win-back sequences, cleanup automations.

---

### Membership Expired
**Hook:** `fchub_memberships/grant_expired`

Fires when a membership reaches its expiry date.

**Conditions:**
- Plan filter
- Run multiple

**Use for:** Renewal reminders, re-engagement sequences.

---

### Membership Paused
**Hook:** `fchub_memberships/grant_paused`

Fires when a membership is paused. Includes **reason filtering** so you can build different funnels for different pause reasons.

**Conditions:**
- Plan filter
- **Pause reasons** (multi-select):
  - Subscription Cancelled
  - Subscription Paused
  - Payment Failed
  - Manual / Admin
- Run multiple

**Use for:** Dunning (payment failed), retention (voluntary pause), notification sequences.

**Example:** Build one funnel for "paused because payment failed" (dunning) and a different funnel for "paused by customer" (retention offer).

---

### Membership Resumed
**Hook:** `fchub_memberships/grant_resumed`

Fires when a paused membership becomes active again.

**Conditions:**
- Plan filter
- Run multiple

**Use for:** "Welcome back" emails, re-engagement.

---

### Membership Renewed
**Hook:** `fchub_memberships/grant_renewed`

Fires when a subscription renewal extends the membership.

**Conditions:**
- Plan filter
- Minimum renewal count (e.g., only fire after 3+ renewals)
- Run multiple (defaults to yes)

**Use for:** Loyalty rewards, anniversary recognition, "thank you for staying" emails.

---

## Expiry & Anniversary Triggers

### Membership Expiring Soon
**Hook:** `fchub_memberships/grant_expiring_soon`

Fires from the daily cron when a membership is approaching its expiry date. You can set a days range to control exactly when it triggers.

**Conditions:**
- Plan filter
- **Min days left** (e.g., don't fire if less than 3 days)
- **Max days left** (e.g., only fire within 30 days of expiry)
- Run multiple

**Use for:** Renewal reminders, discount offers before expiry, "your access is ending" sequences.

---

### Membership Anniversary
**Hook:** `fchub_memberships/grant_anniversary`

Fires on membership milestone days — 30, 60, 90, 180, 365, or 730 days since the member first joined.

**Conditions:**
- Plan filter
- **Milestone days** (multi-select: 30, 60, 90, 180, 365, 730, or custom)
- Run multiple (defaults to yes)

The system tracks which milestones have fired per grant so it never sends duplicates.

**Use for:** Loyalty rewards, anniversary discounts, engagement recognition.

---

## Trial Triggers

### Trial Started
**Hook:** `fchub_memberships/trial_started`

Fires when a member begins a trial period.

**Conditions:**
- Plan filter
- Run multiple

**Use for:** Trial onboarding sequences, "make the most of your trial" emails.

---

### Trial Expiring Soon
**Hook:** `fchub_memberships/trial_expiring_soon`

Fires from the daily cron when a trial is about to end.

**Conditions:**
- Plan filter
- Min days left / Max days left
- Run multiple

**Use for:** "Your trial ends in 3 days" urgency emails, trial-to-paid conversion offers.

---

### Trial Converted
**Hook:** `fchub_memberships/trial_converted`

Fires when a trial converts to a paid membership (payment received during trial).

**Conditions:**
- Plan filter
- Run multiple

**Use for:** "Welcome to full membership" emails, upsell sequences.

---

### Trial Expired
**Hook:** `fchub_memberships/trial_expired`

Fires when a trial ends without payment.

**Conditions:**
- Plan filter
- Run multiple

**Use for:** Win-back offers, "we miss you" sequences, discount codes.

---

## Content Triggers

### Drip Content Unlocked
**Hook:** `fchub_memberships/drip_unlocked`

Fires when a drip-scheduled piece of content becomes available to a member.

**Conditions:**
- Plan filter
- Run multiple (defaults to yes)

**Use for:** "New content available" notifications, engagement tracking.

---

### Drip Milestone Reached
**Hook:** `fchub_memberships/drip_milestone_reached`

Fires when a member's drip completion crosses a threshold.

**Conditions:**
- Plan filter
- **Milestone percentages** (multi-select: 25%, 50%, 75%, 100%)
- Run multiple (defaults to yes)

Milestones are tracked per grant — once 50% fires, it won't fire again unless the member starts a new grant.

**Use for:** Course progress celebrations, completion certificates, engagement gamification.

---

## Payment Trigger

### Payment Failed
**Hook:** `fchub_memberships/payment_failed`

Fires when a payment failure is detected on a subscription linked to a membership. Catches both `order_payment_failed` and `subscription_failing` events from FluentCart.

**Conditions:**
- Plan filter
- Run multiple (defaults to yes — you want this for repeated failures)

**Use for:** Dunning sequences. This is the starting point for "your payment failed, please update your card" email flows. Pair with the `{{membership.payment_update_url}}` smart code.

---

## Common Condition Fields

Most triggers share these condition fields:

| Field | What it does |
|-------|-------------|
| **plan_ids** | Only fire for specific plans (blank = any plan) |
| **run_multiple** | If yes, restarts the funnel even if the contact is already in it |

Some triggers have additional conditions specific to their use case (days range, pause reasons, milestone thresholds, etc.).
