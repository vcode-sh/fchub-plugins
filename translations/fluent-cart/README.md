# FluentCart Polish Translation

Community Polish (pl_PL) translation for [FluentCart](https://fluentcart.com). I did this because FluentCart didn't ship with Polish and my customers don't speak English. Shocking, I know.

## Status

~6,966 strings. ~96% translated. The remaining 4% is mostly edge-case admin labels nobody will ever see, but they haunt me.

## Files

| File | What it is |
|------|-----------|
| `fluent-cart.pot` | Source template — every translatable string from FluentCart |
| `pl_PL.po` | Polish translation (the one you edit) |
| `pl_PL.mo` | Compiled binary (the one WordPress reads) |

## Installation

Copy to your WordPress languages directory:

```bash
cp pl_PL.po /path/to/wp-content/languages/plugins/fluent-cart-pl_PL.po
cp pl_PL.mo /path/to/wp-content/languages/plugins/fluent-cart-pl_PL.mo
```

Or drop them straight into FluentCart:

```bash
cp pl_PL.po pl_PL.mo /path/to/wp-content/plugins/fluent-cart/language/
```

Set WordPress to Polski in Settings → General. Done.

## Contributing

1. Open `pl_PL.po` in [Poedit](https://poedit.net/) or whatever PO editor you tolerate
2. Find the empty `msgstr ""` entries
3. Translate them. Into actual Polish. Google Translate doesn't count
4. Compile: `msgfmt pl_PL.po -o pl_PL.mo`
5. PR it

### Adding a new language

1. Copy `fluent-cart.pot` to `{locale}.po` (e.g. `de_DE.po`)
2. Fill in the header — Language, Plural-Forms, the bureaucratic bits
3. Translate. Compile. PR

### When FluentCart updates

Regenerate the template:

```bash
wp i18n make-pot /path/to/fluent-cart fluent-cart.pot
```

Merge new strings into existing translations:

```bash
msgmerge -U pl_PL.po fluent-cart.pot
```

The untranslated count goes up. The cycle continues.

## License

Same as FluentCart. Built by [Vibe Code](https://x.com/vcode_sh).
