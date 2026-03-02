# FCHub - Fakturownia

Fakturownia invoice integration with KSeF 2.0 support for [FluentCart](https://fluentcart.com). Because the Polish tax office won't accept "vibes" as a valid invoice format.

## What it does

Auto-generates invoices on Fakturownia when orders come through FluentCart. Handles the full lifecycle — issue on payment, correct on refund, and ship to KSeF so the government is happy. Or at least, less angry.

### Features

- **Auto-invoice on order payment** — no manual copy-paste from spreadsheets
- **Correction invoices on refund** — because mistakes happen and so do chargebacks
- **KSeF 2.0 integration** — auto-submit invoices to Poland's national e-invoicing system
- **KSeF status tracking** — cron job monitors submission status, stores KSeF ID
- **NIP checkout field** — "Chce fakture na firme" toggle with NIP validation
- **Per-order invoice data** — stored in order meta, viewable in admin

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [FluentCart](https://fluentcart.com) plugin (active)
- [Fakturownia](https://fakturownia.pl) account

## Installation

1. Download the latest release ZIP from [Releases](../../releases)
2. WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate
4. FluentCart → Settings → Integrations → Fakturownia
5. Enter your Fakturownia domain and API token
6. Select your department
7. Toggle KSeF if you're ready for the future (it's mandatory anyway)

## Configuration

You'll need from your Fakturownia account:

| Setting | Where to find it |
|---------|-----------------|
| Domain | Your `{name}.fakturownia.pl` subdomain |
| API Token | Settings → API → Authorization tokens |
| Department ID | Settings → Company/department → select department |

### wp-config.php (optional)

```php
define('FCHUB_FAKTUROWNIA_DOMAIN', 'yourcompany');
define('FCHUB_FAKTUROWNIA_API_TOKEN', 'your_api_token');
define('FCHUB_FAKTUROWNIA_DEPARTMENT_ID', '123456');
```

## How it works

1. Customer places order, optionally ticks "Chce fakture na firme" and enters NIP
2. Order gets paid → plugin fires invoice creation on Fakturownia
3. If KSeF enabled → invoice auto-submitted to national system
4. Cron checks KSeF status → stores KSeF ID in order meta
5. Refund happens → correction invoice issued automatically

NIP is stored in `fct_order_addresses.meta` as `other_data.nip`. You're welcome, GDPR auditors.

## License

GPLv2 or later. Much like KSeF compliance — technically optional until it very much isn't.
