# FCHub - Przelewy24

Przelewy24 payment gateway for [FluentCart](https://fluentcart.com). I built this because FluentCart ships with Stripe and PayPal, and if you're selling in Poland that's about as useful as a chocolate teapot.

## What it actually does

Lets your FluentCart store accept payments through Przelewy24. Cards, bank transfers, BLIK — the whole Polish payment buffet. One-time purchases, recurring subscriptions, refunds. The boring stuff that makes money move.

- **One-time & recurring payments** — cards, BLIK, bank transfers via P24
- **Subscription billing** — automatic renewals with card-on-file, because chasing invoices is not a business model
- **IPN handling** — P24 pings your site, I process it, order gets marked paid. You were probably asleep
- **Refunds** — hit the button, money goes back. I don't judge
- **Sandbox mode** — test everything before real money is involved. Use it. Seriously
- **Multi-currency** — PLN obviously, but P24 supports others if you're feeling continental

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [FluentCart](https://fluentcart.com) installed and active
- A Przelewy24 merchant account (the one thing I can't automate for you)

## Installation

1. Grab the ZIP from [Releases](../../releases)
2. Plugins → Add New → Upload Plugin
3. Activate
4. FluentCart → Settings → Payment Methods → Przelewy24
5. Paste your credentials
6. Turn off sandbox when you trust yourself

## Configuration

From your [Przelewy24 panel](https://panel.przelewy24.pl):

| Setting | Where |
|---------|-------|
| Merchant ID | My data |
| CRC Key | My data → Configuration |
| Reports Key | My data → Configuration |

Set your IPN URL in the P24 panel:
```
https://yoursite.com/?fluent-cart=fct_payment_listener_ipn&method=przelewy24
```

### wp-config.php (optional, arguably paranoid)

Hardcode credentials so they're not sitting in the database:

```php
define('FCHUB_P24_LIVE_MERCHANT_ID', '123456');
define('FCHUB_P24_LIVE_CRC_KEY', 'your_crc_key');
define('FCHUB_P24_LIVE_REPORTS_KEY', 'your_reports_key');

// Sandbox
define('FCHUB_P24_TEST_MERCHANT_ID', '123456');
define('FCHUB_P24_TEST_CRC_KEY', 'your_test_crc_key');
define('FCHUB_P24_TEST_REPORTS_KEY', 'your_test_reports_key');
```

## Development

```bash
composer install
./vendor/bin/phpunit
```

Tests cover IPN validation, refund notifications, subscription renewals, and the kind of edge cases that only surface at 2am on a Friday.

## License

GPLv2 or later. Built by [Vibe Code](https://x.com/vcode_sh).
