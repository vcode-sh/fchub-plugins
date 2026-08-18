# Translations

How fchub-multi-currency ships languages, and the rules for adding one.
The merchant-facing version of this guide lives at
`web-docs/content/docs/fchub-multi-currency/translations.mdx`; this one adds
the engineering contract behind it.

## Architecture

- **Text domain**: `fchub-multi-currency`, loaded on `init` before the
  FluentCart guard, so the missing-dependency notice translates too.
  Delivery is `languages/` inside the plugin — self-hosted plugins get no
  wordpress.org language packs, the ZIP is the channel.
- **PHP strings** go through `__()` / `esc_html__()` / `_n_noop()` with the
  literal domain. PHPCS enforces `WordPress.WP.I18n` with that domain, so a
  wrong or missing domain fails `composer lint`.
- **Admin SPA and block editor** read strings through `wp.i18n`
  (`__`, `_n`, `sprintf`). Every JS handle declares the `wp-i18n` dependency
  and registers `wp_set_script_translations(..., FCHUB_MC_PATH . 'languages')`.
  Translations arrive as JED `.json` files, one per script, named by the md5
  of the script path — generated, never hand-written.
- **Frontend visitor strings never load wp-i18n.** They ship pre-translated
  from PHP inside the frontend config (`presentationTemplates`), because the
  head bootstrap must stay dependency-free. The site locale is a store fact,
  so translated strings in cached HTML keep pages byte-identical per visitor.
- **Plurals on the frontend** are native for every language: the payload
  carries every plural form per time unit (`timeUnits`) plus
  `timePluralRule` — the site locale's gettext rule precomputed as a
  201-entry form-index table (n = 0..200; counts past 200 repeat the
  101..200 block, since gettext rules depend only on n, n%10, n%100). Built
  by core's `Plural_Forms` parser in `CurrencyContextPresentation`; a
  malformed header falls back to the English rule; configs cached before the
  table shipped fall back to the two-form pick in `currency-context.js`.

## Adding a language

From `plugins/fchub-multi-currency/`:

1. `cp languages/fchub-multi-currency.pot languages/fchub-multi-currency-{locale}.po`
   — exact WordPress locale (`pl_PL`, `de_DE`).
2. Set the `Plural-Forms` header for the language. It is load-bearing: it
   drives both `.mo` selection and the `timePluralRule` table.
3. Translate every `msgstr`, and every `msgstr[n]` on plural entries.
4. `composer i18n:build` — runs `wp i18n make-mo languages` and
   `wp i18n make-json languages --no-purge`. Requires WP-CLI on the host.
5. Verify live: site language for the storefront (switcher labels, freshness
   badge at 2 and 5 units old, checkout notice), profile language for the
   admin panel.

## Translation rules

- Placeholders stay verbatim: `%s`, `%1$s`, `%2$s`, and the checkout tokens
  `{base_currency}`, `{display_currency}`, `{rate}`. Reorder with positional
  forms; never translate or drop them.
- Brand names (FluentCart, FluentCRM, FluentCommunity) and currency codes
  are not translated.
- Prefer typographic quotes over ASCII `"` — some admin strings land in HTML
  attributes.
- Sentences interpolate currency codes, not nouns; grammatical case belongs
  to the surrounding translated words (`w walucie %s`).
- Site-specific overrides that must survive plugin updates go to
  `wp-content/languages/plugins/`, which WordPress checks first.

## Keeping the catalogue current

- `composer i18n:pot` regenerates `languages/fchub-multi-currency.pot`
  (excludes vendor, node_modules, tests, test-results, admin/lib, docs).
  Run it whenever user-facing strings change, then merge into each `.po`
  (Poedit: Translation → Update from POT file) and `composer i18n:build`.
- Untranslated entries fall back to English; nothing breaks.

## What is not covered by gettext

- Merchant-saved content: a customised checkout disclosure text and the
  currency names/symbols stored in settings are content, not defaults.
- FluentCart's currency catalogue (the full name list in the admin picker)
  belongs to FluentCart's own text domain.

## Guardrails in the test suite

- `WordPressOrgPackageTest::translationsLoadFromTheLanguagesDirectory` —
  textdomain loading and `Domain Path` stay wired.
- `WordPressOrgPackageTest::potCataloguesEveryStringSurface` — the POT keeps
  a sentinel from every extraction surface (visitor PHP, admin REST, admin
  SPA JS, editor JS, block.json); a surface falling out of extraction fails
  the suite.
- `AdminMenuTest` / `BlocksModuleTest` — every JS handle keeps its `wp-i18n`
  dependency and script-translations registration.
- `admin-ui-runtime.test.mjs` ("every admin surface string flows through
  wp.i18n") — loads the SPA with a marking translator and asserts per
  component that strings pass through it.
- `CurrencyContextPresentationPluralsTest` and the Polish lane in
  `rate-badge.test.mjs` — the plural table and the browser's form picker,
  including the mod-100 periodicity and the legacy fallback.
- The presentation fixture (`tests/js/presentation-fixture.json`) is the
  PHP↔JS parity contract; regenerate with
  `php tests/js/generate-presentation-fixture.php` after changing
  `CurrencyContextPresentation`. It is generated under the default locale —
  if regeneration output changes after loading a locale, fix the run
  environment, not the fixture.
