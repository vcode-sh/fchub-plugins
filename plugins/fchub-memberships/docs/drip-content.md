# Drip Content

Instead of unlocking everything at once, drip content lets you release it on a schedule. Perfect for courses, onboarding sequences, or any content you want to pace.

## Drip Types

### Delayed
Content unlocks X days after the member joins. Every member gets the same pacing relative to their start date.

Example: "Lesson 2 unlocks 7 days after purchase"

### Fixed Date
Content unlocks on a specific calendar date. Everyone gets it at the same time regardless of when they joined.

Example: "Black Friday bonus unlocks on November 29, 2026"

## How It Works

1. Add content rules to your plan as usual
2. For each rule, set a **drip schedule** (delayed or fixed date)
3. When a member gets the plan, grants are created with `drip_available_at` dates
4. The hourly cron job (`fchub_memberships_drip_process`) checks for unlockable content
5. When content unlocks, the system:
   - Updates the grant to make it accessible
   - Fires `fchub_memberships/drip_unlocked` hook
   - Sends a "new content available" email (if enabled)

## Drip Progress Tracking

The system tracks how much drip content each member has unlocked. You can use this in your FluentCRM automations:

### Smart Codes
- `{{membership.drip_progress}}` — text like "3 of 5 items unlocked"
- `{{membership.drip_percentage}}` — number like "60" (for 60% complete)

### Drip Milestone Trigger
The **DripMilestoneTrigger** fires when a member hits specific completion thresholds: 25%, 50%, 75%, or 100%.

Use it to build engagement funnels:

```
Member hits 50% drip completion
  → Send email: "You're halfway through! Keep going!"
  → Wait for 100% milestone
  → Send email: "Congratulations, you've completed the course!"
```

The system tracks which milestones have already fired (in grant meta) so it never sends duplicates.

## Drip + Trials

Drip schedules work with trials too. If a plan has a 14-day trial and a drip rule set to "unlock after 7 days", the content will unlock on day 7 of the trial — the member doesn't need to have paid yet.
