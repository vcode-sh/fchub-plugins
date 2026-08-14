# Multi-Currency Separator Contract Implementation Plan

> **For agentic workers:** Execute inline with test-driven development. Do not commit, push, tag, publish, or release; the project owner owns Git history.

**Goal:** Make every multi-currency price path follow FluentCart 1.6.1's Number Format contract: `decimal_separator` is the only live setting, and the thousand separator is always its opposite.

**Architecture:** `FrontendModule::buildFrontendConfig()` already maps base parse separators from `decimal_separator` (GitHub #142, implemented this session). Remaining work is locking the JS parser in CI, making display-currency Auto inherit that same pairing instead of guessing from symbol position, and stopping the PHP format helper from lying about cents versus major units.

**Tech Stack:** PHP 8.3, PHPUnit 13, Node.js test runner, FluentCart 1.6.1 `CurrencySettings` / `Helper::toDecimal`.

## Global Constraints

- Product copy, code, tests, and comments remain English.
- Do not apply the GitHub #142 reporter patch as written: it still derives thousands from the ghost `currency_separator` key and maps `'dot' => '.'`, which breaks US-format `1,234.56` matching.
- FluentCart tokens are `'dot'` and `'comma'` only. `space_comma` and `none_dot` are not store settings.
- `CurrencySettings::get()` may return `decimal_separator` as `'dot'`, `'comma'`, or the character `'.'` (empty-value default). Treat anything other than `'comma'` as US pairing, matching FluentCart.
- Per-currency separator overrides in multi-currency admin stay available. Auto (empty) means "same as the shop", not "guess from position".
- Do not bump `FCHUB_MC_VERSION` unless the owner asks for a release.

## Already done this session

`FrontendModule::buildFrontendConfig()` now pairs from `decimal_separator`. PHPUnit covers the #142 disagreement (`currency_separator: dot` + `decimal_separator: comma` → `','` / `'.'`) and the inverse stale-comma case. Full plugin suite: 349 tests, 745 assertions, green.

---

### Task 1: Lock the JS parser pairing in CI

**Files:**
- Create: `plugins/fchub-multi-currency/tests/js/base-price-parse.test.mjs`
- Modify: `.github/workflows/ci.yml` (new job next to `phpunit`, gated on multi-currency changes)

**What it does today:** `parseBasePrice` in `assets/js/currency-projection.js` already handles comma decimals when `baseDecSep === ","`. The 100x bug was PHP config, not this function. The extracted copy in `tests/js/projection-bugs.test.mjs` hardcodes `baseDecSep = "."` and never runs in GitHub Actions.

**Who depends:** `currency-projection.js` `parseBasePrice` / `basePriceRegex`; any future config regression.

**Where it lives after:** a parameterized Node test file plus a CI job. No production JS change unless a test fails.

**Interfaces:**
- Consumes: the same `parseBasePrice` algorithm as `currency-projection.js:170-188`.
- Produces: failing-then-passing Node tests for both FluentCart pairings; CI runs them.

- [ ] **Step 1: Write the failing-first parser tests**

Create `plugins/fchub-multi-currency/tests/js/base-price-parse.test.mjs` with a parameterized `parseBasePrice(text, { sign, code, dec, thou })`. Include the issue #142 numbers and the US thousands case the reporter patch would break:

```javascript
import { describe, it } from "node:test";
import assert from "node:assert/strict";

function parseBasePrice(text, { sign, code, dec }) {
	const stripRegex = new RegExp(
		`(${sign.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}|${code.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")})`,
		"g",
	);
	let cleaned = text.trim().replace(stripRegex, "").replace(/\u00a0/g, " ").replace(/\s/g, "");
	if (dec === ",") {
		cleaned = cleaned.replace(/\./g, "").replace(",", ".");
	} else {
		cleaned = cleaned.replace(/,/g, "");
	}
	return parseFloat(cleaned);
}

describe("FluentCart Number Format pairing", () => {
	it("parses Polish 20,00 zł as 20 when decimal_separator is comma", () => {
		const amount = parseBasePrice("20,00 zł", { sign: "zł", code: "PLN", dec: "," });
		assert.equal(amount, 20);
		assert.equal(+(amount * 0.232596).toFixed(2), 4.65);
	});

	it("parses 1.234,56 as 1234.56 when decimal_separator is comma", () => {
		assert.equal(parseBasePrice("1.234,56 zł", { sign: "zł", code: "PLN", dec: "," }), 1234.56);
	});

	it("parses 1,234.56 as 1234.56 when decimal_separator is dot", () => {
		assert.equal(parseBasePrice("$1,234.56", { sign: "$", code: "USD", dec: "." }), 1234.56);
	});

	it("does not treat 20,00 as 2000 when decimal_separator is comma", () => {
		assert.notEqual(parseBasePrice("20,00 zł", { sign: "zł", code: "PLN", dec: "," }), 2000);
	});
});
```

These tests document the parser contract. They will pass against the current JS (the bug was PHP). That is acceptable here: the PHP tests already failed first for #142. This task is a CI lock, not a new parser.

- [ ] **Step 2: Run the file locally**

```bash
cd plugins/fchub-multi-currency
node --test tests/js/base-price-parse.test.mjs
```

Expected: 4 passing.

- [ ] **Step 3: Add a CI job**

In `.github/workflows/ci.yml`, add a job that runs when `fchub-multi-currency` is in the PHP plugin change matrix (same `changes` output used by `phpunit`). Exact command:

```yaml
  multi-currency-js:
    name: Node tests (fchub-multi-currency)
    needs: changes
    if: contains(needs.changes.outputs.php_plugins, 'fchub-multi-currency')
    runs-on: ubuntu-latest
    timeout-minutes: 10
    steps:
      - uses: actions/checkout@v7
        with:
          persist-credentials: false
      - uses: actions/setup-node@v7
        with:
          node-version: '24'
      - name: Run projection parser tests
        working-directory: plugins/fchub-multi-currency
        run: node --test tests/js/*.test.mjs
```

Also run `tests/js/projection-bugs.test.mjs` in that glob so the existing suite finally has a gate.

- [ ] **Step 4: Verify the workflow file is valid YAML and the glob matches both test files**

```bash
ls plugins/fchub-multi-currency/tests/js/*.test.mjs
node --test plugins/fchub-multi-currency/tests/js/*.test.mjs
```

Expected: both files run; all tests pass.

---

### Task 2: Auto display separators inherit the shop pairing

**Files:**
- Modify: `plugins/fchub-multi-currency/tests/Unit/Bootstrap/FrontendModuleTest.php`
- Modify: `plugins/fchub-multi-currency/app/Bootstrap/Modules/FrontendModule.php:107-119`

**What it does:** `resolveDisplaySep()` uses a position heuristic when the display-currency row leaves separators empty (`Auto` in admin): `right` / `right_space` → European `','` / `'.'`, otherwise US `'.'` / `','`.

**Who depends:** `FrontendModule::buildFrontendConfig()` `displayDecSep` / `displayThousandSep`; `currency-projection.js` `formatNumber`; admin `el-select` Auto value `""`; `OptionStore::normalizeDisplayCurrencies()`.

**Where it lives after:** empty per-currency separators inherit the same FluentCart pairing used for base parse. Explicit `.` / `,` / space / `none` on a display currency still override.

This is a decision reduction, not a capability cut. Merchants who want different output separators still set them. Auto stops asking them to also pick a symbol position that happens to match European format.

- [ ] **Step 1: Write the failing tests**

Add to `FrontendModuleTest.php`:

```php
#[Test]
public function testDisplayAutoInheritsCommaDecimalShopPairingEvenWhenPositionIsLeft(): void
{
    $this->setOption('fchub_mc_settings', [
        'enabled' => 'yes',
        'base_currency' => 'PLN',
        'default_display_currency' => 'EUR',
        'display_currencies' => [
            [
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'decimals' => 2,
                'position' => 'left',
                'decimal_separator' => '',
                'thousand_separator' => '',
            ],
        ],
    ]);
    $this->setWpdbMockRow(null);
    CurrencySettings::setMock([
        'currency' => 'PLN',
        'decimal_separator' => 'comma',
        'currency_separator' => 'dot',
    ]);

    $config = FrontendModule::buildFrontendConfig();

    $this->assertSame(',', $config['displayDecSep']);
    $this->assertSame('.', $config['displayThousandSep']);
}

#[Test]
public function testDisplayExplicitSeparatorsOverrideShopPairing(): void
{
    $this->setOption('fchub_mc_settings', [
        'enabled' => 'yes',
        'base_currency' => 'PLN',
        'default_display_currency' => 'USD',
        'display_currencies' => [
            [
                'code' => 'USD',
                'name' => 'US Dollar',
                'symbol' => '$',
                'decimals' => 2,
                'position' => 'left',
                'decimal_separator' => '.',
                'thousand_separator' => ',',
            ],
        ],
    ]);
    $this->setWpdbMockRow(null);
    CurrencySettings::setMock([
        'currency' => 'PLN',
        'decimal_separator' => 'comma',
    ]);

    $config = FrontendModule::buildFrontendConfig();

    $this->assertSame('.', $config['displayDecSep']);
    $this->assertSame(',', $config['displayThousandSep']);
}
```

- [ ] **Step 2: Run tests and confirm the Auto case fails**

```bash
cd plugins/fchub-multi-currency
./vendor/bin/phpunit --filter testDisplayAutoInheritsCommaDecimalShopPairingEvenWhenPositionIsLeft
```

Expected: FAIL, `displayDecSep` is `'.'` because position is `left`.

- [ ] **Step 3: Change the Auto fallback to the shop pairing**

In `buildFrontendConfig()`, replace the position `match` fallbacks with the already-computed pairing:

```php
'displayDecSep'      => self::resolveDisplaySep(
    $context,
    $optionStore,
    'decimal_separator',
    $isCommaDecimal ? ',' : '.',
),
'displayThousandSep' => self::resolveDisplaySep(
    $context,
    $optionStore,
    'thousand_separator',
    $isCommaDecimal ? '.' : ',',
),
```

Leave `resolveDisplaySep()` itself unchanged: empty / missing still means fallback; `'none'` still means no thousands separator.

- [ ] **Step 4: Run the two new tests and the full PHPUnit suite**

```bash
./vendor/bin/phpunit tests/Unit/Bootstrap/FrontendModuleTest.php
./vendor/bin/phpunit
```

Expected: new tests pass; existing 349+ tests stay green.

---

### Task 3: Make the PHP format helper tell the truth about cents

**Files:**
- Modify: `plugins/fchub-multi-currency/tests/stubs/fluentcart-stubs.php` (`CurrencySettings::getPriceHtml`)
- Modify: `plugins/fchub-multi-currency/fchub-multi-currency.php` (docblock on `fchub_mc_format_price` / `fchub_mc_format_order_price`)
- Modify: `plugins/fchub-multi-currency/tests/Unit/FormatPriceTest.php`
- Modify: `plugins/fchub-multi-currency/tests/Unit/Domain/Enums/RoundingModeNoneTest.php`
- Modify: `plugins/fchub-multi-currency/tests/Unit/Integration/FluentCrmSmartCodesTest.php` (order-price cases only)
- Modify: `plugins/fchub-multi-currency/tests/bootstrap.php` (the duplicated `fchub_mc_format_price` helper, if its comments encode major units)

**What it does:** Live FluentCart `CurrencySettings::getPriceHtml($amount)` divides by 100. Wishlist passes `v.item_price` (cents). The public docblock claims `fchub_mc_format_price(9.99)`. The test stub does **not** divide by 100, so PHPUnit encodes the docstring lie and cannot catch a 100x in PHP-rendered prices.

**Who depends:** `fchub_mc_format_price`, `fchub_mc_format_order_price`, wishlist customer portal, FluentCRM smart-code tests that call the order helper.

**Where it lives after:** stub `getPriceHtml` matches FluentCart (cents in, formatted major units out). Callers and tests pass cents. Docblock examples use cents. No production arithmetic change unless a test proves a caller was passing major units.

Do **not** multiply by 100 inside `fchub_mc_format_price`. Wishlist is already on cents; multiplying would 100x the portal.

- [ ] **Step 1: Write a failing stub-honesty test**

Add to `FormatPriceTest.php`:

```php
#[Test]
public function testFormatPricePassesCentsThroughToFluentCartHtml(): void
{
    $this->setOption('fchub_mc_settings', [
        'enabled' => 'yes',
        'base_currency' => 'USD',
        'rounding_mode' => 'half_up',
        'display_currencies' => [
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'position' => 'left'],
        ],
    ]);
    $this->setWpdbMockRow([
        'base_currency' => 'USD',
        'quote_currency' => 'EUR',
        'rate' => '0.92000000',
        'provider' => 'manual',
        'fetched_at' => current_time('mysql'),
    ]);
    $_COOKIE['fchub_mc_currency'] = 'EUR';

    $optionStore = new \FChubMultiCurrency\Storage\OptionStore();
    $service = new \FChubMultiCurrency\Domain\Services\CurrencyContextService(
        \FChubMultiCurrency\Bootstrap\Modules\ContextModule::buildResolverChain($optionStore),
        $optionStore,
    );
    $service->resolve();

    // 10000 cents = $100.00 → × 0.92 = 9200 cents → "EUR 92.00"
    $result = \fchub_mc_format_price(10000.0);

    $this->assertStringContainsString('92.00', $result);
    $this->assertStringContainsString('EUR', $result);
}
```

- [ ] **Step 2: Change the stub to divide by 100, then run the test**

In `tests/stubs/fluentcart-stubs.php`:

```php
public static function getPriceHtml(float $price, string $currencyCode = 'USD'): string
{
    return sprintf('%s %s', $currencyCode, number_format($price / 100, 2, '.', ''));
}
```

Run:

```bash
./vendor/bin/phpunit tests/Unit/FormatPriceTest.php
```

Expected: the new test still fails (or the old `testFormatsConvertedPriceWithCachedContext` fails) until callers pass cents. That failure is the point.

- [ ] **Step 3: Update existing tests to pass cents**

Replace `fchub_mc_format_price(100.00)` with `fchub_mc_format_price(10000.0)` (and the 10.00 rounding case with 1000.0) in:

- `tests/Unit/FormatPriceTest.php`
- `tests/Unit/Domain/Enums/RoundingModeNoneTest.php`
- `tests/Unit/Integration/FluentCrmSmartCodesTest.php` (`fchub_mc_format_order_price` cases)

Keep `FluentCrmSmartCodes::resolveValue` on cents via `Helper::toDecimal` — that path is already correct.

- [ ] **Step 4: Fix the production docblock**

```php
 *   fchub_mc_format_price(999) → "€9.34" when the visitor rate is 0.934
 *
 * @param float $basePrice Price in the store's base currency, in cents
```

Same for `fchub_mc_format_order_price`.

- [ ] **Step 5: Run the full suite**

```bash
cd plugins/fchub-multi-currency
./vendor/bin/phpunit
```

Expected: green. Then confirm wishlist still compiles against the unchanged function signature (cents in). No wishlist code change.

---

## Out of scope

- Reporter's `baseThousandSep` map from `currency_separator` (`'dot' => '.'`).
- Deleting unused `PriceProjector` (tests-only, not on the storefront path).
- Switcher UX epics in `fchub-playground/todo/mc-switcher-2.md`.
- Version bump / GitHub issue close / release.
