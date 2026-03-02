# FluentCart Integration

This is how purchases turn into memberships. The plugin hooks into FluentCart's integration feed system to automatically grant and revoke access based on order and subscription events.

## How It Works

```
Customer buys product → FluentCart processes order → Integration feed fires → Membership granted
```

The plugin registers as a FluentCart integration provider called `memberships`. You create **feeds** that tell it which plan to grant when a specific product is purchased.

## Setting Up a Feed

1. Go to a FluentCart product → **Integrations** tab
2. Add a new integration feed
3. Select **Memberships** as the provider
4. Configure:

| Setting | What it does |
|---------|--------------|
| **Plan** | Which membership plan to grant |
| **Validity Mode** | How long access lasts (see below) |
| **Validity Days** | Number of days (for fixed duration mode) |
| **Grace Period** | Days to keep access after cancellation |
| **Watch Revocation** | Whether to revoke on cancel/refund |
| **Cancel Behavior** | `wait_validity` (wait for expiry) or `immediate` |
| **Auto Create User** | Create a WordPress user if one doesn't exist |

## Validity Modes

### Lifetime
Access never expires. Good for one-time purchases where you want permanent access.

### Fixed Duration
Access lasts exactly X days from the purchase date. Good for time-limited memberships that aren't tied to a subscription.

### Mirror Subscription
Access follows the subscription's billing cycle. Expiry date = next billing date. When the subscription renews, the membership automatically extends. This is the mode you want for recurring memberships.

## Event Triggers

The feed fires on these FluentCart events:

| Event | What happens |
|-------|-------------|
| `order_paid_done` | Grant access (primary trigger) |
| `subscription_renewed` | Extend expiry to next billing date |
| `subscription_cancelled` | Start grace period or revoke immediately |
| `subscription_paused` | Pause the membership |
| `subscription_resumed` | Resume the membership |
| `order_fully_refunded` | Revoke access |
| `order_canceled` | Revoke access |

## Subscription Lifecycle

For subscription-linked memberships, the full lifecycle looks like this:

```
Purchase → Grant access (mirror subscription expiry)
    ↓
Monthly renewal → Extend expiry to next billing date
    ↓
Payment fails → Payment failed event fires (FluentCRM funnels can respond)
    ↓
Payment recovered → Membership continues normally
    ↓
Subscription cancelled → Grace period starts
    ↓
Grace period ends → Access revoked
```

### Grace Periods

When a subscription is cancelled, you can give the member a grace period — extra days where they keep access before it's revoked. This gives them time to reconsider or resolve payment issues.

- Grace period = 0: access revoked immediately on cancellation
- Grace period > 0: access stays active for X more days, then auto-revokes via cron

## Global vs Product Feeds

Feeds can be scoped two ways:

- **Product-level** — attached to a specific FluentCart product, only fires for that product
- **Global** — runs for all products/orders (useful if you want to grant a base membership to every customer)

## Feed Priority

The memberships integration runs at priority 12, which means it fires after FluentCart's core integrations (which run at 10-11). This ensures the order is fully processed before membership grants happen.
