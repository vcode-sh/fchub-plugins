# Getting Started

## Installation

1. Upload the `fchub-memberships` folder to `wp-content/plugins/`
2. Activate the plugin in WordPress
3. The plugin creates its database tables automatically on activation

That's it. No config files to edit, no API keys to set up.

## Your First Membership Plan

Head to **FCHub Memberships > Plans** in the WordPress admin.

### Create a Plan

1. Click **Add New Plan**
2. Give it a name (e.g., "Pro Membership")
3. Set the duration:
   - **Lifetime** — access never expires
   - **Fixed Days** — access for X days from purchase
4. Optionally set a **trial period** (e.g., 14 days free)
5. Optionally set a **grace period** (days to keep access after subscription cancellation)
6. Save the plan

### Add Content Rules

Content rules define what the plan unlocks. Each rule specifies a content type and the specific items:

- **WordPress Content** — Posts, pages, or any custom post type
- **LearnDash** — Courses and lessons (if LearnDash is installed)
- **FluentCommunity** — Spaces and courses (if FluentCommunity is installed)

You can add as many rules as you want to a single plan.

### Link to a FluentCart Product

This is how purchases actually trigger membership access:

1. Go to a FluentCart product
2. In the **Integrations** tab, add a new feed
3. Choose **Memberships** as the provider
4. Select your plan
5. Choose the **validity mode**:
   - **Lifetime** — no expiration
   - **Fixed Duration** — X days from purchase
   - **Mirror Subscription** — access follows the subscription billing cycle
6. Save the feed

### Test It

1. Buy the product (use test mode if you have a payment gateway in test)
2. Check **FCHub Memberships > Members** — you should see the new member
3. Visit the protected content as that user — it should be accessible

## What Happens Automatically

Once you've set things up, the system handles everything:

- **New purchase** — access granted, emails sent, FluentCRM tags applied
- **Subscription renewal** — expiry extended to next billing date
- **Subscription paused** — membership paused, access blocked
- **Subscription resumed** — membership resumed, access restored
- **Subscription cancelled** — grace period starts (or immediate revocation)
- **Payment failed** — events fire for FluentCRM dunning funnels
- **Trial expires** — auto-converts or revokes based on payment status

## Next Steps

- [Set up content protection](content-protection.md) to restrict access to specific pages
- [Configure drip content](drip-content.md) to release content on a schedule
- [Build FluentCRM funnels](fluentcrm/README.md) for automated email sequences
- [Enable webhooks](webhooks.md) to notify external systems
