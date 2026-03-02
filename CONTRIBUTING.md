# Contributing to fchub-plugins

Oh, you want to contribute? Genuinely lovely. Come in. Wipe your feet.

This is an open source project by one person — [Vibe Code](https://x.com/vcode_sh). Contributions are welcome, but I reserve the right to have opinions about your code. Strong ones.

## Reporting Bugs

Use the bug report template when opening an issue. Include:

- Plugin name and version
- WordPress and FluentCart versions
- Steps to reproduce
- What you expected vs. what actually happened

"It's broken" is not a bug report. "It's broken and here's exactly how to break it" is.

## Feature Requests

Use the feature request template when opening an issue. Tell me **why**, not just **what**. "Add a button that does X" — why? What problem does it solve? Who benefits? Convince me like I'm a tired reviewer at 11pm. Because I probably am.

## Pull Requests

1. Fork the repo
2. Create a branch (`fix/thing-that-broke` or `feature/thing-that-matters`)
3. Make your changes
4. Open a PR against `main`

**One thing per PR.** Fix a bug? That's a PR. Add a feature? That's a PR. Fix a bug AND refactor three files AND rename a variable you didn't like? That's a therapy session, not a PR.

## Code Style

Follow what's already there. If the codebase does it one way, do it that way.

- **PHP**: PSR-12. Strict types where used. No clever tricks that require a PhD to read.
- **Vue** (fchub-memberships admin): Vue 3 Composition API. Single-file components. Keep it boring.
- **No unnecessary abstractions.** If you're creating a `FactoryManagerStrategyProviderInterface` for a function that runs once, I will find you.

## Commit Messages

Short. Imperative mood. Present tense.

```
Fix payment status not updating after callback     # yes
Fixed the thing that was broken sometimes maybe     # no
Refactor entire codebase for aesthetic reasons       # absolutely not
```

## Dev Setup

Each plugin is its own world. Navigate to `plugins/{slug}/` and:

### fchub-p24
```bash
composer install && ./vendor/bin/phpunit
```

### fchub-fakturownia
No build step. It's just PHP. Refreshingly simple.

### fchub-memberships
```bash
# PHP
composer install && ./vendor/bin/phpunit

# Vue admin app
npm install && npm run build    # production
npm install && npm run dev      # dev with HMR
```

### fchub-stream
```bash
# PHP
composer install && ./vendor/bin/phpunit

# Vue admin app
cd admin-app && npm install && npm run dev

# Vue portal app
cd portal-app && npm install && npm run dev
```

### wc-fc
No build step. Just PHP doing PHP things.

### Docker (optional)

I use a companion dev repo with volume mounts. Point your WordPress install's plugin directories at the `plugins/` folders and you're sorted. See the [README](README.md#docker) for the setup.

## What Makes a Good PR

- Tests, if the plugin has them (fchub-p24 and fchub-memberships do)
- Doesn't break existing functionality (CI will catch you, but still)
- Follows patterns already in the codebase
- Has a description that explains what and why
- Is small enough for a human to review without losing the will to live

## What Will Get Your PR Rejected

- Scope creep. "While I was in there, I also reorganised..." No. Stop.
- "Improvements" nobody asked for. If there's no issue for it, open one first.
- Ignoring existing patterns to impose your preferred architecture
- Missing tests for a plugin that has tests
- Tabs. Just kidding. ...Unless?

## Translations

Translation files live in `translations/`. Polish is ~96% done. PRs to finish it (or add other languages) are genuinely appreciated. That's not sarcasm. I know, shocking.

## Questions?

Open an issue. I don't bite. Much.

---

Built by [Vibe Code](https://x.com/vcode_sh). GPLv2 or later.
