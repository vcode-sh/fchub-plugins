# Actions

Actions are things a FluentCRM funnel can *do* to memberships. Use them inside automation funnels to grant, revoke, modify memberships, or create coupons.

## Grant Membership
**Action name:** `fchub_grant_membership`

Grants a membership plan to the contact.

| Setting | Description |
|---------|-------------|
| **Plan** | Which plan to grant (required) |
| **Validity Mode** | `plan_default` (use plan settings), `fixed_days`, or `custom_date` |
| **Duration Days** | Number of days (for fixed_days mode) |
| **Custom Expires At** | Specific date (for custom_date mode) |

**Example use:** Automatically grant a free "Community" plan to anyone who subscribes to your newsletter.

---

## Revoke Membership
**Action name:** `fchub_revoke_membership`

Revokes a membership plan from the contact.

| Setting | Description |
|---------|-------------|
| **Plan** | Which plan to revoke (required) |
| **Reason** | Text reason for the revocation |
| **Use Grace Period** | Whether to apply the plan's grace period |

---

## Pause Membership
**Action name:** `fchub_pause_membership`

Pauses a membership. The member keeps their grant but loses access until resumed.

| Setting | Description |
|---------|-------------|
| **Plan** | Which plan to pause (blank = all active plans) |
| **Reason** | Text reason for pausing |

---

## Resume Membership
**Action name:** `fchub_resume_membership`

Resumes a previously paused membership.

| Setting | Description |
|---------|-------------|
| **Plan** | Which plan to resume (blank = all paused plans) |

---

## Extend Membership
**Action name:** `fchub_extend_membership`

Adds extra time to an active membership.

| Setting | Description |
|---------|-------------|
| **Plan** | Which plan to extend (required) |
| **Extend Days** | How many days to add (required) |
| **Extend Mode** | `from_current_expiry` (add to existing date) or `from_now` (add from today) |

**Example use:** Reward long-term members with an extra month on their anniversary.

---

## Change Membership Plan
**Action name:** `fchub_change_membership_plan`

Switches a member from one plan to another.

| Setting | Description |
|---------|-------------|
| **From Plan** | Current plan (blank = any active plan) |
| **To Plan** | New plan (required) |
| **Keep Expiry** | Whether to keep the original expiry date |

**Example use:** Automatically upgrade members from "Basic" to "Pro" when they've been a member for 365 days.

---

## Create FluentCart Coupon
**Action name:** `fchub_create_fluentcart_coupon`

Generates a unique FluentCart coupon code and stores it on the contact. This is the magic that makes automated discount offers work.

| Setting | Description |
|---------|-------------|
| **Coupon Type** | `percentage` or `fixed` amount |
| **Amount** | Discount value (required) |
| **Expiry Days** | How many days the coupon is valid |
| **Prefix** | Code prefix (e.g., "SAVE" → "SAVE-A7X9K2") |
| **Max Uses** | Maximum redemptions (usually 1) |
| **Is Recurring** | Whether discount applies to recurring payments too |
| **Min Purchase** | Minimum order amount required |

### How It Works

1. The action generates a unique coupon code (with collision detection)
2. Creates the coupon in FluentCart's coupon system
3. Restricts the coupon to the subscriber's email
4. Stores the coupon details on the FluentCRM subscriber as meta:
   - `_fchub_last_coupon_code` — the code itself
   - `_fchub_last_coupon_amount` — the discount amount
   - `_fchub_last_coupon_type` — percentage or fixed
   - `_fchub_last_coupon_expires` — expiry date

### Using Coupon Smart Codes in Emails

After the coupon action runs, use these smart codes in subsequent emails:

- `{{membership.coupon_code}}` — the generated code (e.g., "SAVE-A7X9K2")
- `{{membership.coupon_amount}}` — formatted amount (e.g., "20%" or "10.00")
- `{{membership.coupon_expires}}` — formatted expiry date

### Example Funnel

```
TRIGGER: Membership Expiring Soon (14 days)
  → ACTION: Create Coupon (20% off, expires in 7 days)
  → ACTION: Send email:
    "Your membership expires in {{membership.days_remaining}} days.
     Use code {{membership.coupon_code}} to get {{membership.coupon_amount}} off
     your renewal before {{membership.coupon_expires}}.
     Renew now: {{membership.checkout_url}}"
```
