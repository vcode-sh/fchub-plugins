# Shortcodes & Gutenberg Blocks

The plugin provides 4 shortcodes and 2 Gutenberg blocks for displaying membership content on the frontend. The blocks reuse the same logic as the shortcodes — so they behave identically.

## Shortcodes

### [fchub_restrict]

A wrapping shortcode that shows its inner content only to members who have access. Everything between the opening and closing tags is hidden from non-members.

```
[fchub_restrict plan="pro"]
This content is only visible to Pro members.
[/fchub_restrict]
```

**Attributes:**

| Attribute | Default | Description |
|-----------|---------|-------------|
| `plan` | — | Comma-separated plan slugs. Member needs access to at least one. |
| `resource_type` | — | Resource type to check (e.g., `post`, `page`). Defaults to current post type. |
| `resource_id` | — | Resource ID to check. Defaults to current post ID. |
| `message` | (from settings) | Custom restriction message for non-members |
| `show_login` | `yes` | Show a login link for logged-out users |
| `drip_message` | (from settings) | Message when content is drip-locked. Use `{date}` for the unlock date. |

**How it works:**

1. If the user is not logged in → shows the restriction message + optional login link
2. If logged in → checks for an active grant matching the plan or resource
3. If the grant exists but is drip-locked → shows the drip message with the unlock date
4. If access is granted → renders the inner content normally

**Examples:**

```
<!-- Restrict to specific plans -->
[fchub_restrict plan="pro,vip"]
Premium content here.
[/fchub_restrict]

<!-- Restrict to a specific resource -->
[fchub_restrict resource_type="course" resource_id="42"]
Course materials here.
[/fchub_restrict]

<!-- Custom messages -->
[fchub_restrict plan="pro" message="Upgrade to Pro to see this." drip_message="Coming on {date}!"]
The good stuff.
[/fchub_restrict]
```

---

### [fchub_membership_status]

Shows the current user's active memberships. Returns nothing for logged-out users.

```
[fchub_membership_status]
[fchub_membership_status display="full"]
```

**Attributes:**

| Attribute | Default | Options | Description |
|-----------|---------|---------|-------------|
| `display` | `compact` | `compact`, `full` | Compact shows plan badges only. Full adds expiry dates and drip progress bars. |

**Compact mode** outputs a simple list of plan name badges.

**Full mode** adds:
- Expiry date (or "Lifetime access" if no expiry)
- Drip progress bar showing items unlocked vs total

If the user has no active memberships, it shows "You do not have any active memberships."

---

### [fchub_drip_progress]

Shows a progress bar for drip content in a specific plan.

```
[fchub_drip_progress plan="course-bundle"]
```

**Attributes:**

| Attribute | Required | Description |
|-----------|----------|-------------|
| `plan` | Yes | Plan slug to show progress for |

Returns nothing if:
- User is not logged in
- Plan slug is empty or invalid
- User has no active grants for this plan

The progress bar shows: `X of Y items unlocked (Z%)`

---

### [fchub_my_memberships]

A full membership account dashboard. This is the same view that appears in the FluentCart customer portal.

```
[fchub_my_memberships]
```

No attributes needed. Drop it on any page to give members a complete view of their memberships.

**What it shows:**

- **Active Memberships** section with:
  - Plan name badges
  - Expiry dates
  - Drip progress bars
  - Content library with links to unlocked items and dates for locked items
- **History** section with:
  - Past memberships (expired/revoked)
  - Status and date for each

Logged-out users see a login prompt.

---

## Gutenberg Blocks

Both blocks are server-side rendered and use the same logic as their shortcode equivalents.

### Restrict Block

Block name: `fchub-memberships/restrict`

The block editor version of `[fchub_restrict]`. Wrap any content with this block to restrict it to members.

**Block attributes:**

| Attribute | Type | Description |
|-----------|------|-------------|
| `plan_slugs` | string | Comma-separated plan slugs |
| `resource_type` | string | Resource type to check |
| `resource_id` | string | Resource ID to check |
| `restriction_message` | string | Custom message for non-members |

### Membership Status Block

Block name: `fchub-memberships/membership-status`

The block editor version of `[fchub_membership_status]`.

**Block attributes:**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `display` | string | `compact` | `compact` or `full` |

---

## FluentCart Customer Portal

The plugin adds a **Memberships** tab to the FluentCart customer portal automatically. No shortcode needed — it hooks into the portal's section system.

When FluentCart is active, members see a "Memberships" section in their account area that shows the same content as `[fchub_my_memberships]`.

This happens via:
- `fluent_cart/customer_portal/sections` filter — adds the tab
- `fluent_cart/customer_portal/render_section/memberships` action — renders the content

---

## Styling

All shortcodes and blocks enqueue `fchub-memberships-frontend` CSS (`assets/css/frontend.css`). The stylesheet only loads on pages that actually use these shortcodes or blocks.

**CSS classes you can target:**

| Class | Element |
|-------|---------|
| `.fchub-membership-restricted` | Restriction message wrapper |
| `.fchub-membership-restricted--drip` | Drip-locked message |
| `.fchub-membership-status` | Status shortcode wrapper |
| `.fchub-membership-status--compact` | Compact mode |
| `.fchub-membership-status--full` | Full mode |
| `.fchub-plan-badge` | Plan name badge |
| `.fchub-plan-expiry` | Expiry date text |
| `.fchub-drip-progress` | Progress bar wrapper |
| `.fchub-drip-progress-track` | Progress bar track |
| `.fchub-drip-progress-bar` | Progress bar fill |
| `.fchub-membership-account` | Full account wrapper |
| `.fchub-content-library` | Content items list |
| `.fchub-content-locked` | Locked content item |
| `.fchub-membership-history` | History section |

---

## Default Messages

Restriction messages are pulled from **Settings > Messages**. You can override them per-shortcode with the `message` and `drip_message` attributes.

| Message Type | Default |
|-------------|---------|
| Logged out | "This content is available to members only. Please log in to access it." |
| Restricted | "This content is restricted to members with an active plan." |
| Drip locked | "This content will be available on {date}." |
