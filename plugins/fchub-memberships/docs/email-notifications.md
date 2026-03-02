# Email Notifications

The plugin sends 8 types of email notifications. All emails are dispatched asynchronously via Action Scheduler so they never slow down the checkout or admin operations.

## Email Types

| Email | When it sends | Key info included |
|-------|--------------|-------------------|
| **Access Granted** | Member gets a plan | Plan name, what they can access |
| **Access Revoked** | Membership revoked | Plan name, reason |
| **Access Expiring** | X days before expiry | Plan name, expiry date, days remaining |
| **Membership Paused** | Membership paused | Plan name, reason |
| **Membership Resumed** | Membership resumed | Plan name |
| **Trial Expiring** | X days before trial ends | Plan name, trial end date, days remaining |
| **Trial Converted** | Trial converts to paid | Plan name |
| **Drip Content Unlocked** | New content becomes available | Content title, plan name |

## How Emails Work

Emails don't send directly when events happen. Instead:

1. An event occurs (grant created, membership paused, etc.)
2. The system schedules the email via **Action Scheduler**
3. Action Scheduler processes the queue in the background
4. The email goes out without blocking anything

This means emails might arrive a few seconds after the actual event, but it keeps everything snappy.

## Expiry Notifications

The **Access Expiring** email deserves special mention because it runs on a daily cron (`fchub_memberships_expiry_notify`). The cron:

1. Finds all active grants expiring within the configured notice window
2. Checks if a notification was already sent for each one
3. Fires the `fchub_memberships/grant_expiring_soon` hook (so FluentCRM funnels work)
4. Sends the expiring email if enabled
5. Records that the notification was sent (so it doesn't repeat)

The hook fires **regardless of whether emails are enabled**. This is intentional — your FluentCRM automations should always trigger even if you've disabled the basic email notifications.

## Configuring Emails

Email settings are managed in **FCHub Memberships > Settings**. You can enable/disable each email type independently.

For more sophisticated email sequences, use [FluentCRM automation](fluentcrm/README.md) instead of (or in addition to) these basic notifications.
