# FCHub - Przelewy24

Przelewy24 payment gateway for [FluentCart](https://fluentcart.com). Because your Polish customers deserve better than "bank transfer, please wait 3 business days."

## What it does

Plugs Przelewy24 into FluentCart so you can actually accept payments in Poland like a civilised online store. One-time payments, recurring subscriptions, refunds — the full stack of "taking people's money professionally."

### Features

- **One-time & recurring payments** via Przelewy24
- **Subscription billing** with automatic renewals (card-on-file)
- **IPN (Instant Payment Notification)** handling — because polling is for amateurs
- **Refund support** — for when your product doesn't spark joy
- **Sandbox mode** — break things safely before you break things in production
- **Multi-currency** — PLN obviously, but P24 supports more

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [FluentCart](https://fluentcart.com) plugin (active)
- Przelewy24 merchant account

## Installation

1. Download the latest release ZIP from [Releases](../../releases)
2. WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate
4. FluentCart → Settings → Payment Methods → Przelewy24
5. Enter your Merchant ID, CRC Key, and API Key
6. Toggle sandbox mode off when you're feeling brave

## Configuration

You'll need from your Przelewy24 panel:

| Setting | Where to find it |
|---------|-----------------|
| Merchant ID | P24 Panel → My data |
| CRC Key | P24 Panel → My data → Configuration |
| Reports Key | P24 Panel → My data → Configuration |

Set your IPN URL in P24 panel to:
```
https://yoursite.com/?fluent-cart=fct_payment_listener_ipn&method=przelewy24
```

### wp-config.php (optional)

Hardcode credentials instead of storing in DB:

```php
define('FCHUB_P24_LIVE_MERCHANT_ID', '123456');
define('FCHUB_P24_LIVE_CRC_KEY', 'your_crc_key');
define('FCHUB_P24_LIVE_REPORTS_KEY', 'your_reports_key');

define('FCHUB_P24_TEST_MERCHANT_ID', '123456');
define('FCHUB_P24_TEST_CRC_KEY', 'your_test_crc_key');
define('FCHUB_P24_TEST_REPORTS_KEY', 'your_test_reports_key');
```

## Development

```bash
# Run tests
composer install
./vendor/bin/phpunit
```

## License

GPLv2 or later. The "or later" is doing a lot of heavy lifting there.
