# Segment Filters

Segment filters let you target (or exclude) contacts based on their membership data. Use them in FluentCRM segments, conditional funnel steps, or when sending broadcasts.

All filters appear under the **Memberships** group in FluentCRM's advanced filter UI.

---

## Has Membership Plan

Filter contacts who have (or don't have) an active membership for specific plans.

| Setting | Options |
|---------|---------|
| **Operator** | "has" or "does not have" |
| **Plans** | Select one or more plans (multi-select) |

**Examples:**
- Has "Pro" plan → target active Pro members
- Does not have "Basic" plan → exclude Basic members from an upgrade campaign
- Has any plan (select none) → anyone with at least one active membership

---

## Membership Status

Filter contacts by their grant status.

| Setting | Options |
|---------|---------|
| **Operator** | "is" or "is not" |
| **Status** | Active, Paused, Expired, or Revoked |

**Examples:**
- Status is "Paused" → send a "we miss you" email
- Status is not "Active" → target everyone who's lapsed

---

## Days Until Expiry

Filter contacts based on how many days are left on their membership.

| Setting | Options |
|---------|---------|
| **Operator** | =, !=, >, <, >=, <= |
| **Value** | Number of days |

**Examples:**
- Days until expiry <= 7 → members expiring this week
- Days until expiry > 30 → members with plenty of time left

Only matches contacts with active memberships that have an expiry date set.

---

## Renewal Count

Filter contacts based on how many times they've renewed.

| Setting | Options |
|---------|---------|
| **Operator** | =, !=, >, <, >=, <= |
| **Value** | Number of renewals |

**Examples:**
- Renewal count >= 3 → loyal, long-term members
- Renewal count = 0 → first-time members who haven't renewed yet

---

## Member Duration (Days)

Filter contacts based on how long they've been a member.

| Setting | Options |
|---------|---------|
| **Operator** | =, !=, >, <, >=, <= |
| **Value** | Number of days since their grant was created |

**Examples:**
- Member duration >= 365 → members for over a year
- Member duration < 30 → brand new members (good for onboarding segments)

---

## In Trial

Filter contacts based on whether they're currently in a trial period.

| Setting | Options |
|---------|---------|
| **Operator** | "Yes" or "No" |

**Examples:**
- In trial = Yes → target trial members with conversion offers
- In trial = No → exclude trial members from premium content broadcasts

A contact is "in trial" when they have an active grant with a `trial_ends_at` date in the future.

---

## Combining Filters

You can combine multiple filters in a single segment. FluentCRM applies them with AND logic.

**Example segment: "Loyal members expiring soon"**
- Has Membership Plan: "Pro"
- Renewal Count >= 2
- Days Until Expiry <= 14

This targets Pro members who've renewed at least twice and are within two weeks of expiry — perfect for a VIP renewal offer.

**Example segment: "Trial members to nurture"**
- In Trial: Yes
- Member Duration > 3

Contacts who've been in their trial for more than 3 days — past the initial excitement, time to show them value.
