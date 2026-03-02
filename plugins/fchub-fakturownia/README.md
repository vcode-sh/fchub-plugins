# FCHub - Fakturownia

Fakturownia invoice integration with KSeF 2.0 support for [FluentCart](https://fluentcart.com). I built this because the Polish tax office doesn't accept "I'll send the invoice later, promise" as a valid accounting strategy.

## What it actually does

Order comes in, invoice goes out. Automatically. To [Fakturownia](https://fakturownia.pl). If KSeF is enabled, it ships the invoice straight to Poland's national e-invoicing system too. Refund happens — correction invoice, done. No spreadsheets. No copy-paste. No existential dread at tax time.

- **Auto-invoice on payment** — order paid, invoice created. I don't wait for you to remember
- **Correction invoices on refund** — because the tax office likes symmetry
- **KSeF 2.0** — auto-submit to the national system. It's mandatory anyway, might as well automate it
- **KSeF status tracking** — cron monitors submission, stores the KSeF ID when it lands
- **NIP at checkout** — "Chce fakture na firme" toggle + NIP field. The B2B customer experience nobody asked for but everyone needs
- **Order meta** — invoice ID, KSeF status, KSeF number — all stored, all visible in admin

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [FluentCart](https://fluentcart.com) installed and active
- [Fakturownia](https://fakturownia.pl) account

## Installation

1. ZIP from [Releases](../../releases)
2. Plugins → Add New → Upload Plugin
3. Activate
4. FluentCart → Settings → Integrations → Fakturownia
5. Paste domain, API token, pick department
6. Toggle KSeF. Or don't. The government will make you eventually

## Configuration

From your Fakturownia account:

| Setting | Where |
|---------|-------|
| Domain | Your `{name}.fakturownia.pl` subdomain |
| API Token | Settings → API → Authorization tokens |
| Department ID | Settings → Company/department |

### wp-config.php (optional)

```php
define('FCHUB_FAKTUROWNIA_DOMAIN', 'yourcompany');
define('FCHUB_FAKTUROWNIA_API_TOKEN', 'your_api_token');
define('FCHUB_FAKTUROWNIA_DEPARTMENT_ID', '123456');
```

## How it works

1. Customer orders. Optionally ticks "Chce fakture na firme" and enters NIP
2. Payment confirmed → invoice created on Fakturownia
3. KSeF enabled → invoice auto-submitted to the national system
4. Cron checks back → stores KSeF ID in order meta
5. Refund → correction invoice. Automatically. Like magic, but with tax implications

NIP lives in `fct_order_addresses.meta` as `other_data.nip`. GDPR auditors, you're welcome.

## License

GPLv2 or later. Much like KSeF compliance — technically optional until it very suddenly isn't. Built by [Vibe Code](https://x.com/vcode_sh).
