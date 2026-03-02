# FCHub - Memberships

A complete membership system for [FluentCart](https://fluentcart.com). Plans, access control, content drip, analytics — the whole "pay me monthly and I'll let you read my blog posts" experience, but actually well-built.

## What it does

Turns FluentCart into a full membership platform. Create plans, gate content, drip-schedule releases, track who's paying and who's ghosting. Integrates with FluentCRM for automation and FluentCommunity for community access. It's 15,000+ lines of code so you don't have to write them.

### Features

- **Membership plans** with trial periods, duration control, and pricing tiers
- **Content protection** — posts, pages, taxonomies, URLs, menus, comments, special pages
- **Content drip scheduling** — unlock content X days after signup or on a fixed date
- **Subscription lifecycle** — auto-grant, pause, resume, revoke, expire based on payment status
- **FluentCRM automation** — 15 triggers, 7 actions, benchmarks, filters, smart codes
- **FluentCommunity sync** — grant/revoke access to spaces and courses
- **LearnDash integration** — enroll/unenroll from courses based on membership
- **Import system** — CSV import with PMPro parser for migrations
- **Analytics & reports** — member stats, churn, revenue, content popularity
- **Admin dashboard** — Vue.js SPA with plan editor, member management, drip calendar
- **Frontend** — account page, shortcodes, Gutenberg blocks
- **Email notifications** — access granted, expiring, revoked, drip unlocked, trial events
- **Audit logging** — every grant, revoke, pause tracked with reasons
- **WP-CLI** — `wp fchub-memberships grant` for when you need to fix things at 2am

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [FluentCart](https://fluentcart.com) plugin (active)
- Node.js 20+ (development only)

## Installation

1. Download the latest release ZIP from [Releases](../../releases)
2. WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate — database tables are created automatically
4. FCHub Memberships menu appears in wp-admin sidebar
5. Create your first plan, set protection rules, profit

## Architecture

Built on FluentCart's integration feed system (`BaseIntegrationManager`). Fires on subscription events — activated, renewed, canceled, expired — and manages access grants accordingly.

### Key concepts

| Concept | What it is |
|---------|-----------|
| **Plan** | A membership product with content rules, trial config, and duration |
| **Grant** | A user's access record (active/paused/revoked/expired) |
| **Rule** | Which content (posts, taxonomies, community spaces) belongs to a plan |
| **Drip** | Delayed content unlock — X days after grant or on a fixed date |
| **Adapter** | Extensible interface for granting access (WP, LearnDash, FluentCRM, FluentCommunity) |
| **Feed** | FluentCart integration that triggers on order/subscription events |

### Database

8 custom tables: plans, grants, grant sources, plan rules, protection rules, drip schedules, audit log, event locks.

## Development

```bash
# Install PHP dependencies & run tests
composer install
./vendor/bin/phpunit

# Build admin Vue.js app
npm install
npm run build          # production build → assets/dist/
npm run dev            # dev server with HMR
```

The `assets/dist/` directory is committed so the plugin works without a build step. If you modify anything in `resources/`, rebuild before committing.

## License

GPLv2 or later. 15,000 lines of freedom.
