# FCHub Memberships

Version: `1.4.0`

FCHub Memberships is a complete membership workspace for FluentCart with:

- guided plan and content-protection workflows
- member management and lifecycle automation
- drip scheduling, trials, and Notification Studio
- FluentCRM and FluentCommunity integrations
- provider health, reporting, webhooks, and API connections

## Requirements

- WordPress 6.7 or later

## Development

The tracked source includes local development tooling:

- PHPUnit for PHP tests
- Vite for the admin app build

## External membership writes

External systems authenticate through WordPress Application Passwords. Give
the integration user only `manage_fchub_memberships`, then send an
`Idempotency-Key` header with every membership mutation, FluentCRM reconcile
apply, and provider repair request. Reusing the same key with the same request
replays its stored response; changing the operation or payload returns a
conflict. Dry-run reconciliation, health, OPTIONS, and other read-only routes
do not require the header.

Completed HTTP responses replay without rerunning the mutation. If a process
crashes after the domain mutation but before receipt completion, the expired
receipt can be reclaimed and the domain mutation can run again. Recovery is
therefore domain-idempotent at-least-once execution, never an exactly-once
guarantee wearing a nicer hat.

Same-origin WordPress admin requests may omit the header for compatibility,
although a supplied key is still honoured. FCHub never parses or stores the
Application Password itself. Creating and rotating those credentials remains
standard WordPress administration, as civilisation intended.

## Versioning

Current plugin version: `1.4.0`
