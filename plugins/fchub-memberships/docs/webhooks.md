# Webhooks

The plugin can send webhook notifications to external services whenever membership events happen. Use this to integrate with Zapier, Make, custom APIs, or anything that accepts HTTP POST requests.

## Setup

Go to **FCHub Memberships > Settings** and configure:

| Setting | Description |
|---------|-------------|
| **Enable Webhooks** | Turn webhooks on/off |
| **Webhook URLs** | One URL per line. All URLs receive every event. |
| **Webhook Secret** | Shared secret for HMAC-SHA256 signature verification |

You can send to multiple URLs — just put each one on its own line.

---

## Events

Five membership lifecycle events fire webhooks:

| Event | When It Fires |
|-------|---------------|
| `grant_created` | Member gets access to a plan |
| `grant_revoked` | Membership is revoked |
| `grant_expired` | Membership reaches its expiry date |
| `grant_paused` | Membership is paused |
| `grant_resumed` | Paused membership becomes active again |

---

## Payload Format

Every webhook sends a JSON POST with this structure:

```json
{
  "event_type": "grant_created",
  "timestamp": "2026-03-01T14:30:00+00:00",
  "site_url": "https://yoursite.com",
  "data": {
    "user": {
      "id": 42,
      "email": "jane@example.com",
      "display_name": "Jane Smith"
    },
    "plan": {
      "id": 3,
      "title": "Pro Membership",
      "slug": "pro-membership"
    },
    ...
  }
}
```

The `data` object varies by event type:

### grant_created
```json
"data": {
  "user": { ... },
  "plan": { ... },
  "context": {
    "source_type": "order",
    "source_id": 456
  }
}
```

### grant_revoked
```json
"data": {
  "user": { ... },
  "plan": { ... },
  "reason": "Refund processed",
  "grants_affected": 3
}
```

### grant_expired / grant_resumed
```json
"data": {
  "user": { ... },
  "plan": { ... },
  "grant": {
    "id": 99,
    "status": "expired",
    "source_type": "subscription",
    "created_at": "2025-03-01 10:00:00",
    "expires_at": "2026-03-01 10:00:00"
  }
}
```

### grant_paused
```json
"data": {
  "user": { ... },
  "plan": { ... },
  "grant": { ... },
  "reason": "Payment failed"
}
```

---

## Security: HMAC Signature

If you configure a webhook secret, every request includes an `X-FCHub-Signature` header containing an HMAC-SHA256 hash of the request body.

**Headers sent with every webhook:**

| Header | Value |
|--------|-------|
| `Content-Type` | `application/json` |
| `X-FCHub-Signature` | HMAC-SHA256 hex digest |
| `X-FCHub-Event` | Event type (e.g., `grant_created`) |

### Verifying the Signature

On your receiving end, compute the HMAC-SHA256 of the raw request body using your shared secret, then compare it to the `X-FCHub-Signature` header.

**PHP example:**
```php
$payload = file_get_contents('php://input');
$secret = 'your-webhook-secret';
$expected = hash_hmac('sha256', $payload, $secret);
$received = $_SERVER['HTTP_X_FCHUB_SIGNATURE'] ?? '';

if (!hash_equals($expected, $received)) {
    http_response_code(403);
    die('Invalid signature');
}
```

**Node.js example:**
```javascript
const crypto = require('crypto');
const expected = crypto.createHmac('sha256', secret).update(body).digest('hex');
if (expected !== req.headers['x-fchub-signature']) {
  return res.status(403).send('Invalid signature');
}
```

---

## Delivery

Webhooks are dispatched **asynchronously** via Action Scheduler. This means:

- Events don't slow down the checkout or admin operations
- If Action Scheduler isn't available, webhooks fall back to synchronous dispatch
- Failed webhooks are logged but not retried automatically

---

## Testing

Use the **Send Test Webhook** button in settings. It sends a test payload to all configured URLs:

```json
{
  "event_type": "test",
  "timestamp": "2026-03-01T14:30:00+00:00",
  "site_url": "https://yoursite.com",
  "data": {
    "message": "This is a test webhook from FCHub Memberships"
  }
}
```

The test response shows you the HTTP status code from each URL so you can verify everything is connected before going live.
