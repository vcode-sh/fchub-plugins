# FCHub - Memberships

A complete membership system for [FluentCart](https://fluentcart.com). I wrote 15,000+ lines of PHP and Vue so you can charge people monthly to read your blog posts. The gig economy, but for content.

## What it actually does

Turns FluentCart into a membership platform. Create plans, gate content behind them, drip-schedule releases like you're running a Netflix series, and track who's paying vs who's ghosting. Hooks into FluentCRM for automations and FluentCommunity for community access. It's overkill and I'm not sorry.

- **Membership plans** — trials, durations, pricing tiers. The "pick your poison" setup
- **Content protection** — posts, pages, taxonomies, URLs, menus, comments, special pages. If WordPress renders it, I can gate it
- **Content drip** — unlock stuff X days after signup or on a fixed date. Artificial scarcity, meet automation
- **Subscription lifecycle** — auto-grant on payment, pause on failure, revoke on cancel, expire on schedule. I handle the drama so you don't have to
- **FluentCRM automation** — 15 triggers, 7 actions, benchmarks, filters, smart codes. Enough to build funnels that would make a SaaS bro weep
- **FluentCommunity sync** — grant/revoke access to spaces and courses based on membership
- **LearnDash** — enroll/unenroll from courses. Because LMS + memberships is apparently a thing
- **CSV import** — bulk import members, PMPro migration parser included. I've been through that hell so you don't have to
- **Analytics** — member stats, churn, revenue, content popularity. Numbers that either comfort you or keep you up at night
- **Admin SPA** — Vue.js dashboard with plan editor, member management, drip calendar. Looks decent. Does the job
- **Frontend** — account page, shortcodes, Gutenberg blocks
- **Emails** — access granted, expiring, revoked, drip unlocked, trial events. Automated nagging, basically
- **Audit log** — every grant, revoke, pause tracked with reasons. CYA as a feature
- **WP-CLI** — `wp fchub-memberships grant` for when the admin UI is too many clicks at 2am

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [FluentCart](https://fluentcart.com) installed and active
- Node.js 20+ (dev only, for building the Vue app)

## Installation

1. ZIP from [Releases](../../releases)
2. Plugins → Add New → Upload Plugin
3. Activate — tables create themselves. You're welcome
4. FCHub Memberships appears in the sidebar
5. Create a plan. Gate some content. Start charging

## Architecture

Built on FluentCart's integration feed system (`BaseIntegrationManager`). Listens to subscription events — activated, renewed, canceled, expired — and manages access grants accordingly. Basically an event-driven state machine with a pretty UI on top.

| Concept | What it is |
|---------|-----------|
| **Plan** | Membership product — content rules, trial config, duration |
| **Grant** | A user's access record. Active, paused, revoked, or expired. Life stages of a paying customer |
| **Rule** | Which content belongs to which plan. Posts, taxonomies, community spaces |
| **Drip** | Delayed unlock — X days after grant or fixed date. Patience as a feature |
| **Adapter** | Extensible interface for granting access. WP, LearnDash, FluentCRM, FluentCommunity |
| **Feed** | FluentCart integration trigger on order/subscription events |

8 custom database tables. Plans, grants, grant sources, plan rules, protection rules, drip schedules, audit log, event locks.

## Development

```bash
# PHP
composer install && ./vendor/bin/phpunit

# Vue admin
npm install
npm run dev     # HMR
npm run build   # production → assets/dist/
```

`assets/dist/` is committed so the plugin works without a build step. If you touch `resources/`, rebuild before you commit or the CI will be upset with you.

## License

GPLv2 or later. 15,000 lines of freedom and regret. Built by [Vibe Code](https://x.com/vcode_sh).
