# FCHub Memberships - Documentation

A full-featured membership system for FluentCart. Sell access to content, courses, communities — with trials, drip scheduling, subscription lifecycle management, and deep FluentCRM automation.

## What's Inside


| Doc                                                 | What it covers                                      |
| --------------------------------------------------- | --------------------------------------------------- |
| [Getting Started](getting-started.md)               | Installation, first plan, first sale                |
| [Plans & Access Control](plans-and-access.md)       | Creating plans, linking content, protection modes   |
| [FluentCart Integration](fluentcart-integration.md) | How orders and subscriptions trigger memberships    |
| [Trials](trials.md)                                 | Trial periods, conversion, expiration handling      |
| [Drip Content](drip-content.md)                     | Delayed content unlock schedules                    |
| [Content Protection](content-protection.md)         | How posts, pages, and taxonomies get restricted     |
| [Email Notifications](email-notifications.md)       | All 8 email types and when they fire                |
| [Shortcodes & Blocks](shortcodes-and-blocks.md)     | 4 shortcodes, 2 Gutenberg blocks, account portal    |
| [FluentCRM Automation](fluentcrm/README.md)         | Triggers, actions, benchmarks, smart codes, filters |
| [Webhooks](webhooks.md)                             | Outgoing webhook events and payload format          |
| [Reports & Analytics](reports.md)                   | Revenue, churn, member stats, content popularity    |
| [Developer Reference](developer-reference.md)       | Hooks, adapters, CLI, REST API                      |


## Quick Overview

The plugin works like this:

1. You create **membership plans** and attach content rules to them (posts, pages, courses, community spaces)
2. You link plans to **FluentCart products** via integration feeds
3. When someone buys the product, they get **access granted** automatically
4. The system handles the full lifecycle — renewals, pausing, cancellation, expiration, grace periods
5. Everything syncs with **FluentCRM** (tags, automations) and **FluentCommunity** (spaces, badges)

## Requirements

- WordPress 6.8+
- PHP 8.1+
- FluentCart 1.3.9+ (required)
- FluentCRM (optional — for automation features)
- FluentCommunity (optional — for community integration)
- LearnDash (optional — for course access control)

