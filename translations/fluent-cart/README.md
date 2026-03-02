# FluentCart Polish Translation

Community Polish (pl_PL) translation for [FluentCart](https://fluentcart.com). Because even e-commerce deserves to swear in the right language.

## Status

| Metric | Value |
|--------|-------|
| Total strings | ~6,966 |
| Translated | ~96% |
| Source POT | FluentCart 1.3.9 |

## Files

| File | Purpose |
|------|---------|
| `fluent-cart.pot` | Source template — all extractable strings from FluentCart |
| `pl_PL.po` | Polish translation (editable) |
| `pl_PL.mo` | Compiled binary (generated from .po) |

## Installation

Copy the translation files to your WordPress languages directory:

```bash
cp pl_PL.po /path/to/wp-content/languages/plugins/fluent-cart-pl_PL.po
cp pl_PL.mo /path/to/wp-content/languages/plugins/fluent-cart-pl_PL.mo
```

Or place them directly in FluentCart's language folder:

```bash
cp pl_PL.po pl_PL.mo /path/to/wp-content/plugins/fluent-cart/language/
```

Then set your WordPress language to Polski in Settings → General.

## Contributing

1. Edit `pl_PL.po` with [Poedit](https://poedit.net/) or any PO editor
2. Look for untranslated strings (`msgstr ""`)
3. Compile to .mo: `msgfmt pl_PL.po -o pl_PL.mo`
4. Submit a PR

### Adding a new language

1. Copy `fluent-cart.pot` to `{locale}.po` (e.g. `de_DE.po`)
2. Fill in the header (Language, Plural-Forms, etc.)
3. Translate, compile, PR

## Regenerating the POT

If FluentCart updates and adds new strings, regenerate the template:

```bash
wp i18n make-pot /path/to/fluent-cart fluent-cart.pot
```

Then merge new strings into existing .po files:

```bash
msgmerge -U pl_PL.po fluent-cart.pot
```
