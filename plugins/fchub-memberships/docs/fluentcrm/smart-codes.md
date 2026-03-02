# Smart Codes

Smart codes are dynamic placeholders you drop into FluentCRM email templates. They get replaced with real data when the email sends.

All smart codes use the `{{membership.X}}` format. They show up in the FluentCRM email editor under the "Membership" group.

## Plan & Status

| Smart Code | What It Returns | Example Output |
|------------|----------------|----------------|
| `{{membership.plan_name}}` | Name of the member's active plan | "Pro Membership" |
| `{{membership.plan_slug}}` | Slug of the active plan | "pro-membership" |
| `{{membership.status}}` | Current grant status | "active" |
| `{{membership.all_plans}}` | Comma-separated list of all active plan names | "Basic, Pro, VIP" |

`plan_name` and `plan_slug` return the **most recent** active grant's plan. If you need all plans, use `all_plans`.

---

## Dates & Timing

| Smart Code | What It Returns | Example Output |
|------------|----------------|----------------|
| `{{membership.expires_at}}` | Expiration date (formatted per WordPress settings) | "January 15, 2027" |
| `{{membership.days_remaining}}` | Days until expiry | "14" |
| `{{membership.granted_at}}` | Date the membership was granted | "March 1, 2026" |
| `{{membership.member_since}}` | Date of the member's very first grant (any plan) | "June 10, 2025" |
| `{{membership.days_as_member}}` | Total days since their first-ever grant | "267" |
| `{{membership.days_since_expired}}` | Days since their last membership expired (empty if still active) | "12" |

`member_since` and `days_as_member` look across **all** grants (any plan, any status) to find the earliest join date. Great for loyalty messaging.

`days_since_expired` only returns a value when the member has **no** active grants and at least one expired grant.

---

## Trials

| Smart Code | What It Returns | Example Output |
|------------|----------------|----------------|
| `{{membership.trial_ends_at}}` | Trial end date | "March 15, 2026" |
| `{{membership.trial_days_remaining}}` | Days left in the trial | "5" |

These are empty if the member isn't on a trial.

---

## Renewals

| Smart Code | What It Returns | Example Output |
|------------|----------------|----------------|
| `{{membership.renewal_count}}` | Number of times the membership has renewed | "3" |

Returns "0" if no active grant is found.

---

## Content & Drip

| Smart Code | What It Returns | Example Output |
|------------|----------------|----------------|
| `{{membership.resources_count}}` | Total content items in the member's plan | "24" |
| `{{membership.drip_progress}}` | Human-readable drip progress | "8 of 24 items unlocked" |
| `{{membership.drip_percentage}}` | Drip completion as a number | "33" |

`drip_progress` gives you a friendly sentence. `drip_percentage` gives you just the number (no % sign) — useful for subject lines like "You're {{membership.drip_percentage}}% through the course!"

---

## Cancellation

| Smart Code | What It Returns | Example Output |
|------------|----------------|----------------|
| `{{membership.cancellation_reason}}` | Reason the membership was cancelled or revoked | "Refund processed" |

Checks grant meta for `cancellation_reason` and `revoke_reason`. Useful in exit survey or win-back emails.

---

## URLs

| Smart Code | What It Returns | Example Output |
|------------|----------------|----------------|
| `{{membership.account_url}}` | Member's account page | "https://yoursite.com/account/" |
| `{{membership.checkout_url}}` | Direct checkout link for their current plan | FluentCart instant checkout URL |
| `{{membership.upgrade_url}}` | Checkout link for the next plan tier | FluentCart instant checkout URL |
| `{{membership.payment_update_url}}` | Link to update payment method | FluentCart subscription portal URL |

`checkout_url` generates a FluentCart instant checkout link using the `?fct_cart_hash={variant_id}` format. Perfect for renewal emails.

`upgrade_url` finds the next plan in the upgrade path (if configured) and generates a checkout link for it.

`payment_update_url` links to the FluentCart subscription management portal where the member can update their card.

---

## Billing

| Smart Code | What It Returns | Example Output |
|------------|----------------|----------------|
| `{{membership.next_billing_date}}` | Next subscription billing date | "April 1, 2026" |

Pulls from the FluentCart subscription linked to the grant. Empty if there's no subscription (e.g., one-time purchase).

---

## Coupons

| Smart Code | What It Returns | Example Output |
|------------|----------------|----------------|
| `{{membership.coupon_code}}` | Last generated coupon code | "SAVE-A7X9K2" |
| `{{membership.coupon_amount}}` | Coupon discount (with % for percentage type) | "20%" or "10.00" |
| `{{membership.coupon_expires}}` | Coupon expiry date | "March 15, 2026" |

These only have values after the **Create FluentCart Coupon** action has run in the funnel. The coupon data is stored on the FluentCRM subscriber as meta:

- `_fchub_last_coupon_code`
- `_fchub_last_coupon_amount`
- `_fchub_last_coupon_type`
- `_fchub_last_coupon_expires`

Always place the coupon action **before** the email action that references these codes.

---

## Email Example

Here's a renewal reminder email using several smart codes:

```
Subject: {{membership.plan_name}} — {{membership.days_remaining}} days left

Hi {{contact.first_name}},

Your {{membership.plan_name}} membership expires on {{membership.expires_at}}.

You've been a member since {{membership.member_since}} —
that's {{membership.days_as_member}} days!

Use code {{membership.coupon_code}} to get {{membership.coupon_amount}} off
your renewal before {{membership.coupon_expires}}.

Renew now: {{membership.checkout_url}}
```

---

## How It Works Under the Hood

Smart codes are registered via the `fluent_crm_funnel_context_smart_codes` filter and parsed via the `fluent_crm/smartcode_group_callback_membership` callback.

The parser looks up the contact's `user_id`, fetches their latest active grant, and resolves the requested value. Results are cached per-request so multiple smart codes in the same email don't hit the database repeatedly.

Coupon codes are special — they're stored on the **subscriber** (not the user), so they work even if the contact doesn't have a WordPress user account yet.
