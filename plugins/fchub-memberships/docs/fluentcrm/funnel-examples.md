# Funnel Examples

Real-world funnel recipes you can build with the plugin's triggers, actions, benchmarks, and smart codes. Copy these patterns and adapt them to your business.

---

## 1. Welcome & Onboarding

**Goal:** New members get a welcome sequence that guides them through your content.

```
TRIGGER: Membership Granted (any plan)
  → ACTION: Send email "Welcome to {{membership.plan_name}}!"
    Body: "You now have access to {{membership.resources_count}} resources.
           Start here: {{membership.account_url}}"
  → Wait 1 day
  → ACTION: Send email "Here's what you unlocked"
    Body: "Your drip progress: {{membership.drip_progress}}"
  → Wait 3 days
  → ACTION: Send email "How's it going?"
    Body: "You've been a member for {{membership.days_as_member}} days..."
```

**Triggers used:** Membership Granted
**Smart codes used:** plan_name, resources_count, account_url, drip_progress, days_as_member

---

## 2. Trial Nurture & Conversion

**Goal:** Guide trial members toward conversion before the trial ends.

```
TRIGGER: Trial Started (plan: "Pro Trial")
  → ACTION: Send email "Your 14-day trial has begun"
  → Wait 3 days
  → ACTION: Send email "3 things to try this week"
  → Wait 4 days
  → BENCHMARK: Trial Converted? (goal met = they paid)
    → Yes: Send "Welcome to the full membership!"
    → No: Continue...
  → ACTION: Send email "{{membership.trial_days_remaining}} days left"
    Body: "Your trial ends on {{membership.trial_ends_at}}.
           Upgrade now: {{membership.checkout_url}}"
  → Wait 3 days
  → BENCHMARK: Trial Converted?
    → Yes: End funnel
    → No: Continue...
  → ACTION: Create FluentCart Coupon (15% off, expires in 3 days)
  → ACTION: Send email "Last chance — 15% off"
    Body: "Use code {{membership.coupon_code}} before
           {{membership.coupon_expires}} to get {{membership.coupon_amount}} off.
           Checkout: {{membership.checkout_url}}"
```

**Key components:**
- Trigger: Trial Started
- Benchmark: Trial Converted (checks if payment was received)
- Action: Create FluentCart Coupon
- Smart codes: trial_days_remaining, trial_ends_at, checkout_url, coupon_code, coupon_amount, coupon_expires

---

## 3. Renewal Reminder with Coupon

**Goal:** Remind members before their membership expires. If they don't renew, offer a discount.

```
TRIGGER: Membership Expiring Soon (14-30 days left)
  → ACTION: Send email "Your membership renews soon"
    Body: "{{membership.plan_name}} expires on {{membership.expires_at}}.
           Next billing date: {{membership.next_billing_date}}"
  → Wait 7 days
  → BENCHMARK: Has Active Membership?
    → Yes: End funnel (they renewed)
    → No: Continue...
  → ACTION: Create FluentCart Coupon (20% off, 7-day expiry, prefix: "RENEW")
  → ACTION: Send email "20% off your renewal"
    Body: "Use code {{membership.coupon_code}} to get {{membership.coupon_amount}} off.
           Renew before {{membership.coupon_expires}}: {{membership.checkout_url}}"
  → Wait 7 days
  → BENCHMARK: Has Active Membership?
    → Yes: Send "Welcome back!"
    → No: Tag as "churned"
```

---

## 4. Dunning (Payment Recovery)

**Goal:** When a recurring payment fails, nudge the member to update their card before you pause access.

```
TRIGGER: Payment Failed (any plan, run multiple = yes)
  → ACTION: Send email "Your payment failed"
    Body: "We couldn't process your {{membership.plan_name}} payment.
           Update your card here: {{membership.payment_update_url}}"
  → Wait 3 days
  → BENCHMARK: Payment Recovered?
    → Yes: Send "Payment received! You're all set."
    → No: Continue...
  → ACTION: Send email "Second notice"
    Body: "Your {{membership.plan_name}} access will be paused soon.
           Update payment: {{membership.payment_update_url}}"
  → Wait 4 days
  → BENCHMARK: Payment Recovered?
    → Yes: Send "Welcome back!"
    → No: Continue...
  → ACTION: Pause Membership (reason: "Payment failed")
  → ACTION: Send email "Access paused"
    Body: "Your {{membership.plan_name}} has been paused due to payment issues.
           Fix it here: {{membership.payment_update_url}}"
```

**Key components:**
- Trigger: Payment Failed (runs on every failure)
- Benchmark: Payment Recovered (checks subscription is back to active)
- Action: Pause Membership
- Smart code: payment_update_url

---

## 5. Win-Back Expired Members

**Goal:** When a member expires and doesn't renew, send a win-back sequence with an escalating offer.

```
TRIGGER: Membership Expired (plan: "Pro")
  → Wait 1 day
  → ACTION: Send email "We miss you"
    Body: "Your {{membership.plan_name}} membership expired
           {{membership.days_since_expired}} day(s) ago.
           Rejoin: {{membership.checkout_url}}"
  → Wait 6 days
  → BENCHMARK: Has Active Membership?
    → Yes: End funnel
    → No: Continue...
  → ACTION: Create FluentCart Coupon (10% off, 7-day expiry)
  → ACTION: Send email "10% off to come back"
  → Wait 7 days
  → BENCHMARK: Has Active Membership?
    → Yes: Send "Welcome back!"
    → No: Continue...
  → ACTION: Create FluentCart Coupon (25% off, 3-day expiry)
  → ACTION: Send email "Our best offer — 25% off"
    Body: "Use {{membership.coupon_code}} for {{membership.coupon_amount}} off.
           Expires {{membership.coupon_expires}}. Last chance!"
  → Wait 3 days
  → BENCHMARK: Has Active Membership?
    → Yes: Send "Welcome back!"
    → No: Tag as "lost"
```

---

## 6. Loyalty & Anniversary

**Goal:** Celebrate long-term members on milestones and reward them.

```
TRIGGER: Membership Anniversary (milestones: 90, 365, 730 days)
  → IF milestone = 90 days:
      → Send "3 months! Here's a small thank you."
  → IF milestone = 365 days:
      → ACTION: Create FluentCart Coupon (15% off, 14-day expiry, prefix: "ANNIV")
      → Send "Happy 1-year anniversary!"
        Body: "You've been a {{membership.plan_name}} member since
               {{membership.member_since}}. Here's {{membership.coupon_amount}}
               off your next purchase: {{membership.coupon_code}}"
  → IF milestone = 730 days:
      → ACTION: Change Membership Plan (from: "Pro", to: "VIP", keep expiry: yes)
      → Send "You've been upgraded to VIP!"
```

**Key components:**
- Trigger: Membership Anniversary (multi-milestone)
- Action: Create FluentCart Coupon
- Action: Change Membership Plan (auto-upgrade after 2 years)

---

## 7. Drip Content Engagement

**Goal:** Notify members as they progress through dripped content and celebrate completion.

```
TRIGGER: Drip Content Unlocked (plan: "Course Bundle")
  → ACTION: Send email "New content available"
    Body: "A new lesson has been unlocked!
           Progress: {{membership.drip_progress}}
           ({{membership.drip_percentage}}% complete)"

TRIGGER: Drip Milestone Reached (75%, plan: "Course Bundle")
  → ACTION: Send email "Almost there!"
    Body: "You're {{membership.drip_percentage}}% through the course.
           Keep going — the last section is the best part."

TRIGGER: Drip Milestone Reached (100%, plan: "Course Bundle")
  → ACTION: Send email "Congratulations!"
    Body: "You've completed the entire course.
           Here's what's next: {{membership.upgrade_url}}"
```

---

## 8. Pause & Retention

**Goal:** When a member pauses, try to bring them back. Different sequences for different pause reasons.

```
FUNNEL A — Voluntary pause:
TRIGGER: Membership Paused (reason: "Subscription Paused")
  → ACTION: Send email "We'll keep your spot"
    Body: "Your {{membership.plan_name}} is paused. Resume anytime."
  → Wait 14 days
  → BENCHMARK: Membership Resumed?
    → Yes: Send "Welcome back!"
    → No: Continue...
  → ACTION: Create FluentCart Coupon (20% off, 7-day expiry)
  → ACTION: Send email "Come back with 20% off"
  → Wait 7 days
  → BENCHMARK: Membership Resumed?
    → Yes: Send "Great to have you back!"
    → No: ACTION: Revoke Membership (reason: "Pause timeout")

FUNNEL B — Payment-related pause:
TRIGGER: Membership Paused (reason: "Payment Failed")
  → ACTION: Send email "Your payment needs attention"
    Body: "Update your card: {{membership.payment_update_url}}"
  → Wait 3 days
  → BENCHMARK: Membership Resumed?
    → Yes: End funnel
    → No: Send "Final notice before we remove access"
```

The **Membership Paused** trigger's reason filter lets you build completely separate funnels for different situations.

---

## Tips

- **Always use `run_multiple = yes`** on triggers for recurring events (Payment Failed, Drip Unlocked, Membership Renewed).
- **Place coupon actions before the email** that references coupon smart codes.
- **Benchmarks check periodically** via polling — even if the event happened while the contact was between steps, the benchmark will catch it.
- **Test with a real contact** before going live. FluentCRM's funnel test mode sends to a single contact so you can verify smart codes render correctly.
