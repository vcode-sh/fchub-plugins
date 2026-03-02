# Benchmarks

Benchmarks are goals inside a FluentCRM funnel. The funnel waits at a benchmark until the goal is met, then continues. Think of them as "wait until this happens" checkpoints.

## Has Active Membership
**Hook:** `fchub_memberships/grant_created`

Goal met when the contact has an active membership.

| Setting | Description |
|---------|-------------|
| **Plan filter** | Specific plans or any |
| **Match type** | `any` (has at least one selected plan) or `all` (has every selected plan) |

**Use for:** Waiting for a purchase to complete, checking if someone renewed after an expiry warning.

---

## Membership Expired
**Hook:** `fchub_memberships/grant_expired`

Goal met when a membership expires.

| Setting | Description |
|---------|-------------|
| **Plan filter** | Specific plans or any |

**Use for:** Branching funnels — "did they renew or did they expire?"

---

## Membership Paused
**Hook:** `fchub_memberships/grant_paused`

Goal met when a membership is paused.

| Setting | Description |
|---------|-------------|
| **Plan filter** | Specific plans or any |

**Use for:** Detecting when a member pauses — maybe to start a retention sequence.

---

## Membership Resumed
**Hook:** `fchub_memberships/grant_resumed`

Goal met when a paused membership becomes active again.

| Setting | Description |
|---------|-------------|
| **Plan filter** | Specific plans or any |

**Use for:** "Welcome back" sequences, confirming that a retention effort worked.

---

## Membership Revoked
**Hook:** `fchub_memberships/grant_revoked`

Goal met when a membership is revoked.

| Setting | Description |
|---------|-------------|
| **Plan filter** | Specific plans or any |

**Use for:** Detecting cancellation in a funnel flow, starting win-back sequences.

---

## Trial Converted
**Hook:** `fchub_memberships/trial_converted`

Goal met when a trial converts to a paid membership.

| Setting | Description |
|---------|-------------|
| **Plan filter** | Specific plans or any |

**Use for:** In a trial nurture funnel — "did they convert?" If yes, send different content than if they didn't.

---

## Payment Recovered
**Hook:** `fchub_memberships/grant_renewed`

Goal met when a payment is recovered after a failure. Checks that the linked subscription is back to active status (not still failing or past due).

| Setting | Description |
|---------|-------------|
| **Plan filter** | Specific plans or any |

**Use for:** Ending a dunning sequence. Once payment is recovered, stop sending "please update your card" emails.

---

## How Benchmarks Work in Practice

Benchmarks have two modes:

### Event-Driven
When the hook fires (e.g., `grant_created`), the benchmark checks if the contact matches the conditions. If yes, the goal is met and the funnel continues.

### Polling (assertCurrentGoalState)
FluentCRM periodically checks if the goal is already met for contacts waiting at the benchmark. This catches cases where the event happened before the contact reached the benchmark step.

For example: A contact enters a funnel at "Membership Expiring Soon" and the funnel takes 3 days of email sequences before reaching the "Has Active Membership" benchmark. If the member renewed on day 2, the polling check will catch it even though the `grant_created` event already fired.

## Example: Dunning Funnel with Recovery Benchmark

```
TRIGGER: Payment Failed
  → ACTION: Send email "Your payment failed. Update here: {{membership.payment_update_url}}"
  → Wait 3 days
  → BENCHMARK: Payment Recovered?
    → Yes (goal met): Send "Payment received! You're all set."
    → No (timeout): Send "Final notice — update your payment method"
  → Wait 4 days
  → BENCHMARK: Payment Recovered?
    → Yes: Send "Welcome back!"
    → No: ACTION: Pause Membership (reason: payment failed)
```
