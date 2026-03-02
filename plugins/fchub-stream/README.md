# FCHub - Stream

Video streaming for [FluentCommunity](https://fluentcommunity.com). I built this because the WordPress media library treats video like an afterthought, and embedding YouTube links in a community portal feels like hosting a party in someone else's house.

## What it actually does

Lets your FluentCommunity members upload videos directly to Cloudflare Stream or Bunny.net. No media library. No server meltdowns. No "your upload exceeds the maximum file size" heartbreak.

- **Direct uploads** — videos go straight to Cloudflare Stream or Bunny.net. Your server never touches the file
- **TUS resumable uploads** — large files, flaky connections, no problem. Picks up where it left off
- **Portal integration** — video uploads in posts, comments, and courses. Native to FluentCommunity
- **Webhook processing** — provider pings your site when encoding finishes. Automatic, invisible, done
- **Embedded player** — responsive, adaptive bitrate, no third-party branding. Just the video
- **Two providers** — Cloudflare Stream for the enterprise crowd, Bunny.net for the budget-conscious. Both work. Pick one

## Requirements

- WordPress 6.5+
- PHP 8.3+
- [FluentCommunity](https://fluentcommunity.com) installed and active
- A Cloudflare Stream or Bunny.net account (the cloud bit is on you)

## Installation

1. Grab the ZIP from [Releases](../../releases)
2. Plugins → Add New → Upload Plugin
3. Activate
4. FCHub Stream → Settings
5. Pick your provider, paste your API keys
6. Upload a video. Watch it work. Try not to cry

## Configuration

### Cloudflare Stream

| Setting | Where to find it |
|---------|-----------------|
| Account ID | Cloudflare Dashboard → Stream |
| API Token | My Profile → API Tokens |

### Bunny.net

| Setting | Where to find it |
|---------|-----------------|
| API Key | Account Settings → API |
| Library ID | Stream → Video Library |

Set your webhook URL in the provider's dashboard:
```
https://yoursite.com/?fchub-stream-webhook=1
```

## Development

```bash
# PHP
composer install && ./vendor/bin/phpunit

# Admin app (Vue)
cd admin-app && npm install && npm run dev

# Portal app (Vue)
cd portal-app && npm install && npm run dev
```

Tests cover webhook validation, upload handling, and the kind of encoding edge cases that make you question your career choices.

## License

GPLv2 or later. Built by [Vibe Code](https://x.com/vcode_sh).
