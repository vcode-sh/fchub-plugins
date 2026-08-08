# Product Mapping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a shop owner link WooCommerce products to FluentCart products they already built by hand, so historical orders attach to the real catalogue instead of creating a duplicate one.

**Architecture:** Decisions are drafted in a new staging table (`wp_cartshift_product_map`) and promoted into `wp_cartshift_id_map` as `created_by_migration = 0` rows at run start. Because every migrator already resolves product references through `IdMapRepository::getFcId()`, orders, subscriptions and coupons inherit the mapping with no changes. Only `ProductMigrator` is modified, and only to honour the skip list.

**Tech Stack:** PHP 8.3 (PSR-12, `declare(strict_types=1)`, no Composer autoloader in production), PHPUnit 13, Vue 3 Composition API, Vitest 4 + `@vue/test-utils`, Vite 8.

**Spec:** `docs/superpowers/specs/2026-08-08-product-mapping-design.md`

## Global Constraints

- **Never run `git commit` or `git push`.** Per `CLAUDE.md`, only the project owner commits. Every task ends by staging with `git add`; the owner commits.
- **All code, comments and docs in English.** No Polish.
- **PHP 8.3**, PSR-12, `declare(strict_types=1);` at the top of every new PHP file, `defined('ABSPATH') || exit;` immediately after the namespace.
- **Classes are `final`** unless something extends them. Constructor properties are `private readonly` and promoted.
- **No Composer autoloader at runtime** — CartShift uses `spl_autoload_register` mapping `CartShift\*` to `app/`. A new class file must sit at the path its namespace implies.
- **Every new PHP test** extends `CartShift\Tests\Unit\PluginTestCase` and lives under `tests/Unit/` mirroring the `app/` path.
- **PHP tests run:** `./vendor/bin/phpunit` from `plugins/cartshift`.
- **JS tests run:** `npm test` from `plugins/cartshift`.
- **The two DB version constants must stay in step:** `CARTSHIFT_DB_VERSION` in `cartshift.php` and `Migrations::CURRENT_VERSION` in `app/Support/Migrations.php`. Nothing enforces it.
- **`$wpdb` is stubbed in tests** via `$GLOBALS['_cartshift_test_*']` callbacks — see `tests/Unit/Storage/IdMapRepositoryTest.php` for the established pattern. `PluginTestCase` clears every `_cartshift_test_*` global between tests automatically.
- **REST responses are wrapped:** `new WP_REST_Response(['data' => …])`. The Vue `useApi()` helper unwraps `data` automatically.
- **`useApi()` signature is `api(method, endpoint, body)`** — query strings go in `endpoint`, there is no separate params argument.

---

## File Structure

**New — PHP**

| File | Responsibility |
|---|---|
| `app/Domain/Mapping/ProductMapDecision.php` | Immutable value object: one owner decision (`link` / `create` / `skip`) |
| `app/Storage/ProductMapRepository.php` | CRUD on `wp_cartshift_product_map`. Knows SQL, knows nothing about matching |
| `app/Domain/Mapping/ProductMatcher.php` | Pure scoring: Woo product + candidates → band and ranked candidates. No database |
| `app/Domain/Mapping/VariantResolver.php` | Pure: Woo variations + FC variants → variant map and orphans. No database |
| `app/Domain/Migration/MappingPromoter.php` | Staging table → ID map at run start. Owns the skip list and dead-link degradation |
| `app/Http/Controllers/MappingController.php` | REST: rows, decide, bulk, clear |

**New — Vue**

| File | Responsibility |
|---|---|
| `src/composables/useMapping.js` | Mapping state, decisions, bulk actions, run mode |
| `src/components/MapRow.vue` | One product row: Woo side, FC side, three actions |
| `src/components/MapScreen.vue` | Bands, bulk buttons, summary tiles, Continue |

**Modified**

| File | Change |
|---|---|
| `cartshift.php` | `CARTSHIFT_DB_VERSION` `'5'` → `'6'` |
| `app/Support/Migrations.php` | `v6()`, `VERSIONS`, `CURRENT_VERSION`, `dropAll()` |
| `app/Migrator/ProductMigrator.php` | `excludeProductIds()` setter honoured by `countTotal()` and `fetchProductIdPage()` |
| `app/Modules/Migration/MigrationModule.php` | Register `ProductMapRepository`, `MappingPromoter`, the `MappingController` route entry; feed the skip list to `ProductMigrator` |
| `app/Http/Controllers/PreflightController.php` | Report `fc_product_count` so the step can auto-skip |
| `src/App.vue` | The `map` screen |

`uninstall.php` and `app/Support/Constants.php` are **not** modified — uninstall delegates to `Migrations::dropAll()`, and `Constants` names only FluentCart's tables.

---

### Task 1: Schema — the staging table

**Files:**
- Modify: `app/Support/Migrations.php` (`VERSIONS` at :26, `CURRENT_VERSION` at :14, `dropAll()` at :56)
- Modify: `cartshift.php:24`
- Test: `tests/Unit/Support/MigrationsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: table `{prefix}cartshift_product_map` with columns `id`, `wc_id`, `wc_type`, `decision`, `fc_post_id`, `band`, `variant_map`, `decided_at`, and `UNIQUE KEY wc_product_unique (wc_id)`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Support/MigrationsTest.php`:

```php
public function testV6CreatesTheProductMapTable(): void
{
    $statements = [];

    $GLOBALS['_cartshift_test_dbdelta_callback'] = static function (string $sql) use (&$statements): array {
        $statements[] = $sql;
        return [];
    };

    update_option('cartshift_db_version', '5');

    Migrations::run();

    $joined = implode("\n", $statements);

    $this->assertStringContainsString('cartshift_product_map', $joined);
    $this->assertStringContainsString('wc_id BIGINT UNSIGNED NOT NULL', $joined);
    $this->assertStringContainsString('decision VARCHAR(10) NOT NULL', $joined);
    $this->assertStringContainsString('variant_map LONGTEXT NULL', $joined);
    $this->assertStringContainsString('UNIQUE KEY wc_product_unique (wc_id)', $joined);
    $this->assertSame('6', get_option('cartshift_db_version'));
}

public function testV6IsNotReRunWhenAlreadyAtSix(): void
{
    update_option('cartshift_db_version', '6');

    $ran = false;
    $GLOBALS['_cartshift_test_dbdelta_callback'] = static function () use (&$ran): array {
        $ran = true;
        return [];
    };

    Migrations::run();

    $this->assertFalse($ran, 'A migration at the current version must not re-run.');
}

public function testTheTwoVersionConstantsAgree(): void
{
    // Deliberately reads the real cartshift.php as text rather than using the
    // ambient CARTSHIFT_DB_VERSION constant. phpunit.xml bootstraps
    // tests/stubs/test-bootstrap.php, which defines its own CARTSHIFT_DB_VERSION,
    // and nothing in tests/ ever requires the real plugin file — so the constant
    // resolves to the stub, and an assertion against it would compare the stub to
    // Migrations while both real constants drifted apart unnoticed. Reading the
    // source text checks the two values that actually ship, without executing the
    // plugin bootstrap's side effects.
    $source = file_get_contents(dirname(__DIR__, 3) . '/cartshift.php');

    $this->assertIsString($source, 'cartshift.php must be readable.');

    $matched = preg_match(
        "/define\('CARTSHIFT_DB_VERSION',\s*'([^']+)'\)/",
        $source,
        $matches,
    );

    // A version-agreement test that silently passes when it cannot find the
    // constant is the same bug it exists to prevent.
    $this->assertSame(1, $matched, 'CARTSHIFT_DB_VERSION not found in cartshift.php.');

    $this->assertSame(
        $matches[1],
        Migrations::currentVersion(),
        'cartshift.php and Migrations declare the DB version independently; they must match.',
    );
}
```

- [ ] **Step 2: Add the dbDelta test hook and the version accessor**

The stub's `dbDelta()` currently does nothing observable. In `tests/stubs/test-bootstrap.php`, find the `dbDelta` function stub and make it honour a callback:

```php
function dbDelta(string $sql): array
{
    if (isset($GLOBALS['_cartshift_test_dbdelta_callback'])) {
        return ($GLOBALS['_cartshift_test_dbdelta_callback'])($sql);
    }

    return [];
}
```

If no `dbDelta` stub exists yet, add the function above to the stub file.

In `app/Support/Migrations.php`, expose the constant so the agreement test can read it:

```php
public static function currentVersion(): string
{
    return self::CURRENT_VERSION;
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `./vendor/bin/phpunit --filter 'MigrationsTest'`
Expected: FAIL — `testV6CreatesTheProductMapTable` finds no `cartshift_product_map`, and the version-agreement test reports `'5'` against `'6'` once the constant moves.

- [ ] **Step 4: Write the migration**

In `app/Support/Migrations.php`, bump the version constant:

```php
private const string CURRENT_VERSION = '6';
```

Add to the `VERSIONS` map:

```php
private const array VERSIONS = [
    '1' => 'v1',
    '2' => 'v2',
    '3' => 'v3',
    '4' => 'v4',
    '5' => 'v5',
    '6' => 'v6',
];
```

Add the migration method, after `v5()`:

```php
/**
 * v6: the product mapping staging table.
 *
 * Deliberately not the ID map. A row in the ID map is a fact the migration
 * resolves against; a row here is an intention the owner is still free to
 * change. Keeping them apart is what stops a half-finished mapping session
 * altering the next run, and what stops `reset` — which clears run state —
 * from destroying decisions that were never part of a run.
 */
private static function v6(): void
{
    global $wpdb;

    $charset = $wpdb->get_charset_collate();
    $table   = $wpdb->prefix . 'cartshift_product_map';

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        wc_id BIGINT UNSIGNED NOT NULL,
        wc_type VARCHAR(20) NOT NULL DEFAULT '',
        decision VARCHAR(10) NOT NULL,
        fc_post_id BIGINT UNSIGNED NULL,
        band VARCHAR(10) NOT NULL DEFAULT 'none',
        variant_map LONGTEXT NULL,
        decided_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY wc_product_unique (wc_id),
        KEY decision_lookup (decision)
    ) {$charset};";

    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    dbDelta($sql);
}
```

Extend `dropAll()` so uninstall removes it:

```php
public static function dropAll(): void
{
    global $wpdb;

    $idMapTable     = $wpdb->prefix . 'cartshift_id_map';
    $logTable       = $wpdb->prefix . 'cartshift_migration_log';
    $productMapTable = $wpdb->prefix . 'cartshift_product_map';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query("DROP TABLE IF EXISTS {$idMapTable}");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query("DROP TABLE IF EXISTS {$logTable}");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query("DROP TABLE IF EXISTS {$productMapTable}");

    delete_option(self::DB_VERSION_OPTION);
    delete_option('cartshift_migration_state');
}
```

In `cartshift.php`, line 24:

```php
define('CARTSHIFT_DB_VERSION', '6');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/phpunit --filter 'MigrationsTest'`
Expected: PASS

- [ ] **Step 6: Run the whole suite for regressions**

Run: `./vendor/bin/phpunit`
Expected: PASS — no existing test asserts the old version number, but `ConstantsTest` and `MigrationsTest` both touch this area.

- [ ] **Step 7: Stage**

```bash
git add app/Support/Migrations.php cartshift.php tests/Unit/Support/MigrationsTest.php tests/stubs/test-bootstrap.php
```

---

### Task 2: ProductMapDecision and ProductMapRepository

**Files:**
- Create: `app/Domain/Mapping/ProductMapDecision.php`
- Create: `app/Storage/ProductMapRepository.php`
- Test: `tests/Unit/Storage/ProductMapRepositoryTest.php`

**Interfaces:**
- Consumes: the table from Task 1.
- Produces:
  - `ProductMapDecision::link(int $wcId, string $wcType, int $fcPostId, string $band, array $variantMap, array $orphans = []): self` — `$orphans` is `list<array{id: int, sku: string, name: string}>`
  - `->orphans(): list<array{id: int, sku: string, name: string}>`, `->variantEnvelope(): array{map: …, orphans: …}`
  - `ProductMapDecision::create(int $wcId, string $wcType, string $band): self`
  - `ProductMapDecision::skip(int $wcId, string $wcType, string $band): self`
  - `ProductMapDecision::fromRow(object $row): self`
  - `->wcId(): int`, `->wcType(): string`, `->decision(): string`, `->fcPostId(): ?int`, `->band(): string`, `->variantMap(): array<int,int>`, `->isLink(): bool`, `->isSkip(): bool`, `->toArray(): array`
  - Constants `ProductMapDecision::LINK|CREATE|SKIP` = `'link'|'create'|'skip'`
  - `ProductMapRepository::save(ProductMapDecision $d): void`, `->saveMany(array $ds): void`, `->get(int $wcId): ?ProductMapDecision`, `->all(): array`, `->linked(): array`, `->skippedProductIds(): array`, `->clear(): void`, `->count(): int`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Storage/ProductMapRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Storage;

use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Storage\ProductMapRepository;
use CartShift\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The staging table is the owner's draft, not the migration's record. These
 * tests pin the two properties that follow from that: one decision per Woo
 * product (last write wins), and a variant map that survives the round trip
 * as integers rather than JSON's strings.
 */
final class ProductMapRepositoryTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_product_map_rows'] = [];

        // A fake honouring the UNIQUE(wc_id) the schema declares, so "last write
        // wins" is tested rather than assumed.
        $GLOBALS['_cartshift_test_insert_callback'] = static function (string $table, array $data): int {
            if (!str_contains($table, 'cartshift_product_map')) {
                return 1;
            }

            $GLOBALS['_cartshift_test_product_map_rows'][(int) $data['wc_id']] = $data;

            return 1;
        };

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query): array {
            if (!str_contains($query, 'cartshift_product_map')) {
                return [];
            }

            $rows = [];

            foreach ($GLOBALS['_cartshift_test_product_map_rows'] as $row) {
                if (str_contains($query, "decision = 'link'") && $row['decision'] !== 'link') {
                    continue;
                }

                if (str_contains($query, "decision = 'skip'") && $row['decision'] !== 'skip') {
                    continue;
                }

                $rows[] = (object) $row;
            }

            return $rows;
        };
    }

    public function testALinkDecisionRoundTrips(): void
    {
        $repo = new ProductMapRepository();

        $repo->save(ProductMapDecision::link(42, 'variable', 900, 'strong', [11 => 501, 12 => 502]));

        $all = $repo->all();

        $this->assertCount(1, $all);
        $this->assertSame(42, $all[0]->wcId());
        $this->assertSame('link', $all[0]->decision());
        $this->assertSame(900, $all[0]->fcPostId());
        $this->assertSame([11 => 501, 12 => 502], $all[0]->variantMap());
    }

    public function testTheVariantMapSurvivesAsIntegers(): void
    {
        $repo = new ProductMapRepository();

        $repo->save(ProductMapDecision::link(42, 'variable', 900, 'strong', [11 => 501]));

        $map = $repo->all()[0]->variantMap();

        // json_decode hands back string keys and would quietly poison every
        // getFcId() lookup built from them.
        $this->assertSame([11 => 501], $map);
        $this->assertIsInt(array_key_first($map));
        $this->assertIsInt($map[11]);
    }

    public function testTheLastDecisionForAProductWins(): void
    {
        $repo = new ProductMapRepository();

        $repo->save(ProductMapDecision::link(42, 'simple', 900, 'strong', []));
        $repo->save(ProductMapDecision::skip(42, 'simple', 'strong'));

        $all = $repo->all();

        $this->assertCount(1, $all);
        $this->assertSame('skip', $all[0]->decision());
        $this->assertNull($all[0]->fcPostId());
    }

    public function testSkippedProductIdsReturnsOnlySkips(): void
    {
        $repo = new ProductMapRepository();

        $repo->save(ProductMapDecision::skip(1, 'simple', 'none'));
        $repo->save(ProductMapDecision::create(2, 'simple', 'none'));
        $repo->save(ProductMapDecision::skip(3, 'simple', 'none'));

        $this->assertSame([1, 3], $repo->skippedProductIds());
    }

    public function testOrphanVariationsRoundTripAlongsideTheMap(): void
    {
        $repo = new ProductMapRepository();

        $repo->save(ProductMapDecision::link(
            42,
            'variable',
            900,
            'likely',
            [11 => 501],
            [['id' => 13, 'sku' => 'TS-XL', 'name' => 'XL']],
        ));

        $decision = $repo->all()[0];

        $this->assertSame([11 => 501], $decision->variantMap());
        $this->assertSame(
            [['id' => 13, 'sku' => 'TS-XL', 'name' => 'XL']],
            $decision->orphans(),
            'Promotion needs the orphan list to know which variants to add.',
        );
    }

    public function testALegacyBareVariantMapStillDecodes(): void
    {
        // Rows written before the envelope existed hold the bare map.
        $repo = new ProductMapRepository();

        $GLOBALS['_cartshift_test_product_map_rows'][42] = [
            'wc_id'       => 42,
            'wc_type'     => 'variable',
            'decision'    => 'link',
            'fc_post_id'  => 900,
            'band'        => 'strong',
            'variant_map' => '{"11":501}',
        ];

        $decision = $repo->all()[0];

        $this->assertSame([11 => 501], $decision->variantMap());
        $this->assertSame([], $decision->orphans());
    }

    /**
     * @return list<array{0: int|null}>
     */
    public static function absentTargets(): array
    {
        // fromRow() treats null and <= 0 as "no target", and they are distinct
        // branches — a row written by a client that sent 0 rather than omitting
        // the field must degrade the same way.
        return [[null], [0]];
    }

    #[DataProvider('absentTargets')]
    public function testALinkWithNoTargetIsReadBackAsCreate(int|null $fcPostId): void
    {
        $GLOBALS['_cartshift_test_product_map_rows'][42] = [
            'wc_id'       => 42,
            'wc_type'     => 'simple',
            'decision'    => 'link',
            'fc_post_id'  => $fcPostId,
            'band'        => 'strong',
            'variant_map' => null,
        ];

        $decision = (new ProductMapRepository())->all()[0];

        $this->assertSame(
            'create',
            $decision->decision(),
            'A link with nowhere to point must degrade to create; promotion would otherwise write an ID map row aimed at nothing.',
        );
        $this->assertNull($decision->fcPostId());
    }

    public function testATargetlessLinkIsExcludedFromLinked(): void
    {
        $GLOBALS['_cartshift_test_product_map_rows'][42] = [
            'wc_id'       => 42,
            'wc_type'     => 'simple',
            'decision'    => 'link',
            'fc_post_id'  => null,
            'band'        => 'strong',
            'variant_map' => null,
        ];

        $this->assertSame(
            [],
            (new ProductMapRepository())->linked(),
            'linked() re-filters after mapping rather than trusting the SQL decision column, precisely so a degraded row cannot reach promotion.',
        );
    }

    public function testACreateDecisionCarriesNoFcProduct(): void
    {
        $repo = new ProductMapRepository();

        $repo->save(ProductMapDecision::create(7, 'simple', 'none'));

        $this->assertNull($repo->all()[0]->fcPostId());
        $this->assertSame([], $repo->all()[0]->variantMap());
    }
}
```

- [ ] **Step 2: Add the insert test hook**

In `tests/stubs/test-bootstrap.php`, the `wpdb::insert()` method (around line 890) records into `_cartshift_test_queries`. Make it delegate when a callback is present, leaving existing behaviour otherwise:

```php
public function insert(string $table, array $data, ?array $format = null): int|false
{
    $GLOBALS['_cartshift_test_queries'][] = ['insert', $table, $data];

    if (isset($GLOBALS['_cartshift_test_insert_callback'])) {
        return ($GLOBALS['_cartshift_test_insert_callback'])($table, $data);
    }

    return 1;
}
```

Apply the same delegation to `replace()` if the stub has one; if it does not, add:

```php
public function replace(string $table, array $data, ?array $format = null): int|false
{
    return $this->insert($table, $data, $format);
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `./vendor/bin/phpunit --filter 'ProductMapRepositoryTest'`
Expected: FAIL — `Class "CartShift\Domain\Mapping\ProductMapDecision" not found`.

- [ ] **Step 4: Write the value object**

Create `app/Domain/Mapping/ProductMapDecision.php`:

```php
<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

/**
 * One decision the shop owner made about one WooCommerce product.
 *
 * Immutable and dumb, in the same spirit as MigrationScope: it holds a choice,
 * it does not act on one. Promotion into the ID map is MappingPromoter's job,
 * matching is ProductMatcher's, and this is what travels between them.
 */
final class ProductMapDecision
{
    public const string LINK   = 'link';
    public const string CREATE = 'create';
    public const string SKIP   = 'skip';

    /**
     * @param array<int, int>                                 $variantMap Woo variation ID => FC variation ID
     * @param list<array{id: int, sku: string, name: string}> $orphans    Woo variations with no FC counterpart
     */
    private function __construct(
        private readonly int $wcId,
        private readonly string $wcType,
        private readonly string $decision,
        private readonly ?int $fcPostId,
        private readonly string $band,
        private readonly array $variantMap,
        private readonly array $orphans,
    ) {
    }

    /**
     * @param array<int, int>                                 $variantMap
     * @param list<array{id: int, sku: string, name: string}> $orphans Woo variations
     *        with no counterpart, carried so promotion can add them to the FC
     *        product. Optional, so a caller that has not resolved variants yet
     *        still gets a usable decision.
     */
    public static function link(
        int $wcId,
        string $wcType,
        int $fcPostId,
        string $band,
        array $variantMap,
        array $orphans = [],
    ): self {
        return new self(
            $wcId,
            $wcType,
            self::LINK,
            $fcPostId,
            $band,
            self::normalizeMap($variantMap),
            self::normalizeOrphans($orphans),
        );
    }

    public static function create(int $wcId, string $wcType, string $band): self
    {
        return new self($wcId, $wcType, self::CREATE, null, $band, [], []);
    }

    public static function skip(int $wcId, string $wcType, string $band): self
    {
        return new self($wcId, $wcType, self::SKIP, null, $band, [], []);
    }

    /**
     * Rebuild from a database row.
     *
     * A `link` row missing its fc_post_id is downgraded to `create` rather than
     * returned as a link with nowhere to point — the alternative is a promotion
     * that writes an ID map row aimed at nothing.
     */
    public static function fromRow(object $row): self
    {
        $decision = (string) $row->decision;
        $fcPostId = $row->fc_post_id !== null ? (int) $row->fc_post_id : null;

        if ($decision === self::LINK && ($fcPostId === null || $fcPostId <= 0)) {
            $decision = self::CREATE;
            $fcPostId = null;
        }

        $variantMap = [];
        $orphans    = [];

        if ($decision === self::LINK && is_string($row->variant_map ?? null)) {
            $decoded = json_decode($row->variant_map, true);

            if (is_array($decoded)) {
                // Two shapes live in this column: the legacy bare map, and the
                // envelope that also carries orphans. Reading both means an
                // upgrade does not have to rewrite existing rows.
                $variantMap = is_array($decoded['map'] ?? null)
                    ? self::normalizeMap($decoded['map'])
                    : self::normalizeMap($decoded);

                $orphans = is_array($decoded['orphans'] ?? null)
                    ? self::normalizeOrphans($decoded['orphans'])
                    : [];
            }
        }

        return new self(
            (int) $row->wc_id,
            (string) ($row->wc_type ?? ''),
            $decision,
            $fcPostId,
            (string) ($row->band ?? 'none'),
            $variantMap,
            $orphans,
        );
    }

    public function wcId(): int
    {
        return $this->wcId;
    }

    public function wcType(): string
    {
        return $this->wcType;
    }

    public function decision(): string
    {
        return $this->decision;
    }

    public function fcPostId(): ?int
    {
        return $this->fcPostId;
    }

    public function band(): string
    {
        return $this->band;
    }

    /** @return array<int, int> */
    public function variantMap(): array
    {
        return $this->variantMap;
    }

    /**
     * Woo variations this link has no FluentCart counterpart for.
     *
     * MappingPromoter creates one FC variant per entry, flagged
     * created_by_migration so rollback removes them again.
     *
     * @return list<array{id: int, sku: string, name: string}>
     */
    public function orphans(): array
    {
        return $this->orphans;
    }

    public function isLink(): bool
    {
        return $this->decision === self::LINK;
    }

    public function isSkip(): bool
    {
        return $this->decision === self::SKIP;
    }

    /**
     * The persisted and wire shape. Keys are contract — the repository, the
     * controller and the Vue app all read exactly these.
     *
     * @return array{wc_id: int, wc_type: string, decision: string, fc_post_id: int|null, band: string, variant_map: array<int, int>, orphans: list<array{id: int, sku: string, name: string}>}
     */
    public function toArray(): array
    {
        return [
            'wc_id'       => $this->wcId,
            'wc_type'     => $this->wcType,
            'decision'    => $this->decision,
            'fc_post_id'  => $this->fcPostId,
            'band'        => $this->band,
            'variant_map' => $this->variantMap,
            'orphans'     => $this->orphans,
        ];
    }

    /**
     * What the repository writes into the `variant_map` column.
     *
     * An envelope rather than the bare map, because promotion needs the orphan
     * list too and adding a column for it would mean a second schema version
     * for the same feature.
     *
     * @return array{map: array<int, int>, orphans: list<array{id: int, sku: string, name: string}>}
     */
    public function variantEnvelope(): array
    {
        return ['map' => $this->variantMap, 'orphans' => $this->orphans];
    }

    /**
     * Force both sides of the map to integers.
     *
     * json_decode returns string keys for a JSON object, and a string key here
     * poisons every getFcId() built from it — the lookup would compare '11'
     * against 11 in a table whose wc_id column is a string.
     *
     * @param array<array-key, mixed> $map
     * @return array<int, int>
     */
    private static function normalizeMap(array $map): array
    {
        $out = [];

        foreach ($map as $wooId => $fcId) {
            $wooId = (int) $wooId;
            $fcId  = (int) $fcId;

            if ($wooId > 0 && $fcId > 0) {
                $out[$wooId] = $fcId;
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $orphans
     * @return list<array{id: int, sku: string, name: string}>
     */
    private static function normalizeOrphans(array $orphans): array
    {
        $out = [];

        foreach ($orphans as $orphan) {
            if (!is_array($orphan)) {
                continue;
            }

            $id = (int) ($orphan['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $out[] = [
                'id'   => $id,
                'sku'  => (string) ($orphan['sku'] ?? ''),
                'name' => (string) ($orphan['name'] ?? ''),
            ];
        }

        return $out;
    }
}
```

- [ ] **Step 5: Write the repository**

Create `app/Storage/ProductMapRepository.php`:

```php
<?php

declare(strict_types=1);

namespace CartShift\Storage;

use CartShift\Domain\Mapping\ProductMapDecision;

defined('ABSPATH') || exit;

/**
 * The owner's mapping decisions, kept apart from the ID map on purpose.
 *
 * See Migrations::v6() for why. In short: this table holds intentions, the ID
 * map holds facts, and `reset` is allowed to destroy the latter.
 */
final class ProductMapRepository
{
    private readonly string $table;

    public function __construct()
    {
        global $wpdb;

        $this->table = $wpdb->prefix . 'cartshift_product_map';
    }

    /**
     * Upsert one decision.
     *
     * REPLACE rather than INSERT ... ON DUPLICATE KEY UPDATE because the table
     * has exactly one unique key and no foreign keys pointing at its surrogate
     * id, so losing the row's id on rewrite costs nothing.
     */
    public function save(ProductMapDecision $decision): void
    {
        global $wpdb;

        $wpdb->replace(
            $this->table,
            [
                'wc_id'       => $decision->wcId(),
                'wc_type'     => $decision->wcType(),
                'decision'    => $decision->decision(),
                'fc_post_id'  => $decision->fcPostId(),
                'band'        => $decision->band(),
                'variant_map' => $decision->isLink()
                    ? (string) wp_json_encode($decision->variantEnvelope())
                    : null,
                'decided_at'  => gmdate('Y-m-d H:i:s'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s'],
        );
    }

    /**
     * @param list<ProductMapDecision> $decisions
     */
    public function saveMany(array $decisions): void
    {
        foreach ($decisions as $decision) {
            $this->save($decision);
        }
    }

    public function get(int $wcId): ?ProductMapDecision
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE wc_id = %d LIMIT 1",
            $wcId,
        ));

        if (empty($rows)) {
            return null;
        }

        return ProductMapDecision::fromRow($rows[0]);
    }

    /** @return list<ProductMapDecision> */
    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY wc_id ASC");

        return array_map(ProductMapDecision::fromRow(...), $rows ?: []);
    }

    /** @return list<ProductMapDecision> */
    public function linked(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE decision = 'link' ORDER BY wc_id ASC",
        );

        // fromRow() downgrades a link with no target to create, so filter after
        // mapping rather than trusting the column.
        return array_values(array_filter(
            array_map(ProductMapDecision::fromRow(...), $rows ?: []),
            static fn (ProductMapDecision $d): bool => $d->isLink(),
        ));
    }

    /** @return list<int> */
    public function skippedProductIds(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE decision = 'skip' ORDER BY wc_id ASC",
        );

        $ids = array_map(
            static fn (object $row): int => (int) $row->wc_id,
            $rows ?: [],
        );

        sort($ids);

        return $ids;
    }

    public function count(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
    }

    public function clear(): void
    {
        global $wpdb;

        $wpdb->query("TRUNCATE TABLE {$this->table}");
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/phpunit --filter 'ProductMapRepositoryTest'`
Expected: PASS

- [ ] **Step 7: Run the whole suite**

Run: `./vendor/bin/phpunit`
Expected: PASS. The `insert()` stub change is additive — existing tests set no `_cartshift_test_insert_callback` and keep the old return.

- [ ] **Step 8: Stage**

```bash
git add app/Domain/Mapping/ProductMapDecision.php app/Storage/ProductMapRepository.php tests/Unit/Storage/ProductMapRepositoryTest.php tests/stubs/test-bootstrap.php
```

---

### Task 3: ProductMatcher

**Files:**
- Create: `app/Domain/Mapping/ProductMatcher.php`
- Test: `tests/Unit/Domain/Mapping/ProductMatcherTest.php`

**Interfaces:**
- Consumes: nothing. Pure functions over arrays — no `$wpdb`, no WooCommerce, no FluentCart.
- Produces:
  - Constants `ProductMatcher::BAND_STRONG|BAND_LIKELY|BAND_WEAK|BAND_NONE` = `'strong'|'likely'|'weak'|'none'`
  - `match(array $woo, array $candidates): array` returning `array{band: string, candidate_id: int|null, score: float, ranked: list<array{id: int, score: float}>}`
  - Input shapes: `$woo` is `array{name: string, sku: string, price: float, variation_count: int}`; each candidate is `array{id: int, name: string, sku: string, price: float, variation_count: int}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Domain/Mapping/ProductMatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\ProductMatcher;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * SKU-first matching was rejected in design because most WooCommerce shops
 * leave SKUs blank, which turns a SKU-keyed matcher into a screen of 300 rows
 * saying "no candidate". Title similarity therefore carries the score and SKU
 * is a bonus. testABlankSkuCatalogueStillMatches is the test that pins that
 * decision — if it ever goes green by accident, the design has drifted back.
 */
final class ProductMatcherTest extends PluginTestCase
{
    private function woo(string $name, string $sku = '', float $price = 10.0, int $variations = 1): array
    {
        return ['name' => $name, 'sku' => $sku, 'price' => $price, 'variation_count' => $variations];
    }

    private function candidate(int $id, string $name, string $sku = '', float $price = 10.0, int $variations = 1): array
    {
        return ['id' => $id, 'name' => $name, 'sku' => $sku, 'price' => $price, 'variation_count' => $variations];
    }

    public function testAnIdenticalSkuIsStrong(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('Pro Licence Annual', 'PRO-1'),
            [$this->candidate(900, 'Pro Licence', 'PRO-1')],
        );

        $this->assertSame(ProductMatcher::BAND_STRONG, $result['band']);
        $this->assertSame(900, $result['candidate_id']);
    }

    public function testAnIdenticalTitleAndPriceIsStrongWithoutAnySku(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('Blue Hoodie', '', 49.0),
            [$this->candidate(901, 'Blue Hoodie', '', 49.0)],
        );

        $this->assertSame(ProductMatcher::BAND_STRONG, $result['band']);
        $this->assertSame(901, $result['candidate_id']);
    }

    public function testABlankSkuCatalogueStillMatches(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('Classic T-Shirt', '', 19.0),
            [$this->candidate(902, 'Classic T Shirt', '', 25.0)],
        );

        $this->assertNotSame(
            ProductMatcher::BAND_NONE,
            $result['band'],
            'A blank-SKU shop is the common case; it must not degrade to no candidate.',
        );
        $this->assertSame(902, $result['candidate_id']);
    }

    public function testAnUnrelatedNameIsNoCandidate(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('Gift Card'),
            [$this->candidate(903, 'Enterprise Support Retainer')],
        );

        $this->assertSame(ProductMatcher::BAND_NONE, $result['band']);
        $this->assertNull($result['candidate_id']);
    }

    public function testAnEmptyCandidateListIsNoCandidate(): void
    {
        $result = (new ProductMatcher())->match($this->woo('Anything'), []);

        $this->assertSame(ProductMatcher::BAND_NONE, $result['band']);
        $this->assertNull($result['candidate_id']);
        $this->assertSame([], $result['ranked']);
    }

    public function testTheBestCandidateWinsAndRankingIsDescending(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('Blue Hoodie', '', 49.0),
            [
                $this->candidate(910, 'Red Hoodie', '', 49.0),
                $this->candidate(911, 'Blue Hoodie', '', 49.0),
                $this->candidate(912, 'Hoodie', '', 49.0),
            ],
        );

        $this->assertSame(911, $result['candidate_id']);
        $this->assertCount(3, $result['ranked']);
        $this->assertSame(911, $result['ranked'][0]['id']);
        $this->assertGreaterThanOrEqual($result['ranked'][1]['score'], $result['ranked'][0]['score']);
        $this->assertGreaterThanOrEqual($result['ranked'][2]['score'], $result['ranked'][1]['score']);
    }

    public function testAnEmptySkuOnBothSidesIsNotTreatedAsAMatch(): void
    {
        $blank = (new ProductMatcher())->match(
            $this->woo('Widget A', ''),
            [$this->candidate(920, 'Widget B', '')],
        );

        $matched = (new ProductMatcher())->match(
            $this->woo('Widget A', 'W-A'),
            [$this->candidate(921, 'Widget B', 'W-A')],
        );

        $this->assertLessThan(
            $matched['score'],
            $blank['score'],
            'Two blank SKUs are an absence of evidence, not evidence of identity.',
        );
    }

    public function testCaseAndPunctuationDoNotBlockAMatch(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('PRO LICENCE — ANNUAL!', '', 99.0),
            [$this->candidate(930, 'pro licence annual', '', 99.0)],
        );

        $this->assertSame(ProductMatcher::BAND_STRONG, $result['band']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit --filter 'ProductMatcherTest'`
Expected: FAIL — `Class "CartShift\Domain\Mapping\ProductMatcher" not found`.

- [ ] **Step 3: Write the matcher**

Create `app/Domain/Mapping/ProductMatcher.php`:

```php
<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

/**
 * Ranks FluentCart candidates for one WooCommerce product.
 *
 * A suggestion engine, never a decision engine. Nothing here commits a link —
 * the owner does, on the mapping screen, which is why there is no "auto-accept
 * above N" threshold anywhere in this class.
 *
 * Title similarity carries the score and SKU is a bonus, not a gate. That is
 * the opposite of the obvious design and it is deliberate: real WooCommerce
 * shops mostly leave SKUs blank, so a SKU-keyed matcher reports "no candidate"
 * for an entire catalogue and hands the owner 300 manual searches.
 */
final class ProductMatcher
{
    public const string BAND_STRONG = 'strong';
    public const string BAND_LIKELY = 'likely';
    public const string BAND_WEAK   = 'weak';
    public const string BAND_NONE   = 'none';

    private const float SKU_BONUS       = 0.35;
    private const float PRICE_BONUS     = 0.15;
    private const float VARIATION_BONUS = 0.05;

    private const float TITLE_NEAR_IDENTICAL = 0.95;
    private const float TITLE_STRONG_FLOOR   = 0.50;
    private const float TITLE_LIKELY         = 0.70;
    private const float TITLE_WEAK           = 0.40;

    /**
     * @param array{name: string, sku: string, price: float, variation_count: int}                    $woo
     * @param list<array{id: int, name: string, sku: string, price: float, variation_count: int}>      $candidates
     *
     * @return array{band: string, candidate_id: int|null, score: float, ranked: list<array{id: int, score: float}>}
     */
    public function match(array $woo, array $candidates): array
    {
        if ($candidates === []) {
            return ['band' => self::BAND_NONE, 'candidate_id' => null, 'score' => 0.0, 'ranked' => []];
        }

        $ranked = [];
        $best   = null;

        foreach ($candidates as $candidate) {
            $titleSimilarity = self::titleSimilarity($woo['name'], $candidate['name']);
            $skuEqual        = self::skuEqual($woo['sku'], $candidate['sku']);
            $priceEqual      = self::priceEqual($woo['price'], $candidate['price']);
            $variationEqual  = $woo['variation_count'] === $candidate['variation_count'];

            $score = $titleSimilarity
                + ($skuEqual ? self::SKU_BONUS : 0.0)
                + ($priceEqual ? self::PRICE_BONUS : 0.0)
                + ($variationEqual ? self::VARIATION_BONUS : 0.0);

            $ranked[] = ['id' => (int) $candidate['id'], 'score' => round($score, 4)];

            if ($best === null || $score > $best['score']) {
                $best = [
                    'id'          => (int) $candidate['id'],
                    'score'       => $score,
                    'title'       => $titleSimilarity,
                    'sku_equal'   => $skuEqual,
                    'price_equal' => $priceEqual,
                ];
            }
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $band = self::band($best);

        return [
            'band'         => $band,
            'candidate_id' => $band === self::BAND_NONE ? null : $best['id'],
            'score'        => round($best['score'], 4),
            'ranked'       => $ranked,
        ];
    }

    /**
     * @param array{id: int, score: float, title: float, sku_equal: bool, price_equal: bool} $best
     */
    private static function band(array $best): string
    {
        // A matching SKU is a deliberate act by whoever set it, but it is not on
        // its own enough — two products can share a lazily copied SKU. Pairing it
        // with a title floor costs nothing and rules that out.
        if ($best['sku_equal'] && $best['title'] >= self::TITLE_STRONG_FLOOR) {
            return self::BAND_STRONG;
        }

        if ($best['title'] >= self::TITLE_NEAR_IDENTICAL && $best['price_equal']) {
            return self::BAND_STRONG;
        }

        if ($best['title'] >= self::TITLE_LIKELY) {
            return self::BAND_LIKELY;
        }

        if ($best['title'] >= self::TITLE_WEAK) {
            return self::BAND_WEAK;
        }

        return self::BAND_NONE;
    }

    /**
     * Similarity of two product names, 0.0 to 1.0.
     *
     * Normalised first, because "PRO LICENCE — ANNUAL!" and "pro licence annual"
     * are the same product typed by two different people on two different days.
     */
    private static function titleSimilarity(string $a, string $b): float
    {
        $a = self::normalizeTitle($a);
        $b = self::normalizeTitle($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        similar_text($a, $b, $percent);

        return round($percent / 100, 4);
    }

    /**
     * Lower-case, strip punctuation, collapse whitespace.
     *
     * Unicode-aware: an em dash and a hyphen are both separators, and a shop
     * whose product names carry accents must not be reduced to noise.
     */
    private static function normalizeTitle(string $title): string
    {
        $title = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);

        $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? '';

        return trim(preg_replace('/\s+/', ' ', $title) ?? '');
    }

    /**
     * Two SKUs match only when both are present.
     *
     * Blank equals blank is an absence of evidence, and treating it as identity
     * would hand every unSKU'd product in the shop a spurious strong match
     * against every other one.
     */
    private static function skuEqual(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);

        return $a !== '' && $b !== '' && strcasecmp($a, $b) === 0;
    }

    private static function priceEqual(float $a, float $b): bool
    {
        return abs($a - $b) < 0.005;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/phpunit --filter 'ProductMatcherTest'`
Expected: PASS

- [ ] **Step 5: Stage**

```bash
git add app/Domain/Mapping/ProductMatcher.php tests/Unit/Domain/Mapping/ProductMatcherTest.php
```

---

### Task 4: VariantResolver

**Files:**
- Create: `app/Domain/Mapping/VariantResolver.php`
- Test: `tests/Unit/Domain/Mapping/VariantResolverTest.php`

**Interfaces:**
- Consumes: nothing. Pure.
- Produces: `resolve(array $wooVariations, array $fcVariants): array` returning `array{map: array<int, int>, orphans: list<int>}`. Both inputs are `list<array{id: int, sku: string, name: string}>` in display order.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Domain/Mapping/VariantResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\VariantResolver;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * FluentCart line items reference a product AND a variation row, so a link that
 * leaves variants unresolved produces orders whose line items point at nothing.
 * An unmatched Woo variation is reported as an orphan rather than silently
 * re-pointed at a sibling — putting "XL" revenue on the "L" row would break
 * FluentCart's per-variant reporting permanently and invisibly.
 */
final class VariantResolverTest extends PluginTestCase
{
    private function v(int $id, string $sku, string $name): array
    {
        return ['id' => $id, 'sku' => $sku, 'name' => $name];
    }

    public function testSkuBeatsNameAndPosition(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, 'TS-L', 'Large')],
            [$this->v(501, 'TS-XL', 'Large'), $this->v(502, 'TS-L', 'Enormous')],
        );

        $this->assertSame([11 => 502], $result['map']);
        $this->assertSame([], $result['orphans']);
    }

    public function testNameBeatsPositionWhenNoSkuMatches(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, '', 'Large')],
            [$this->v(501, '', 'Small'), $this->v(502, '', 'Large')],
        );

        $this->assertSame([11 => 502], $result['map']);
    }

    public function testPositionIsTheLastResort(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, '', 'Alpha'), $this->v(12, '', 'Beta')],
            [$this->v(501, '', 'One'), $this->v(502, '', 'Two')],
        );

        $this->assertSame([11 => 501, 12 => 502], $result['map']);
        $this->assertSame([], $result['orphans']);
    }

    public function testAnUnmatchedWooVariationIsAnOrphan(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, '', 'Small'), $this->v(12, '', 'Large'), $this->v(13, '', 'XL')],
            [$this->v(501, '', 'Small'), $this->v(502, '', 'Large')],
        );

        $this->assertSame([11 => 501, 12 => 502], $result['map']);
        $this->assertSame([13], $result['orphans']);
    }

    public function testAnFcVariantIsNeverClaimedTwice(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, 'DUP', 'Large'), $this->v(12, 'DUP', 'Large')],
            [$this->v(501, 'DUP', 'Large')],
        );

        $this->assertSame([11 => 501], $result['map']);
        $this->assertSame([12], $result['orphans']);
    }

    public function testABlankSkuNeverMatchesABlankSku(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, '', 'Only')],
            [$this->v(501, '', 'Different')],
        );

        // Falls through to position, not to "both blank therefore equal".
        $this->assertSame([11 => 501], $result['map']);
    }

    public function testNoFcVariantsMakesEveryWooVariationAnOrphan(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, '', 'Small'), $this->v(12, '', 'Large')],
            [],
        );

        $this->assertSame([], $result['map']);
        $this->assertSame([11, 12], $result['orphans']);
    }

    public function testASimpleProductMapsItsSinglePseudoVariation(): void
    {
        // A Woo simple product is passed as one pseudo-variation keyed by the
        // product ID — mirroring how ProductMigrator stores ENTITY_VARIATION
        // for simple products.
        $result = (new VariantResolver())->resolve(
            [$this->v(42, '', 'Default')],
            [$this->v(777, '', 'Default')],
        );

        $this->assertSame([42 => 777], $result['map']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit --filter 'VariantResolverTest'`
Expected: FAIL — `Class "CartShift\Domain\Mapping\VariantResolver" not found`.

- [ ] **Step 3: Write the resolver**

Create `app/Domain/Mapping/VariantResolver.php`:

```php
<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

/**
 * Pairs a Woo product's variations with a linked FluentCart product's variants.
 *
 * Three passes, strongest signal first, each FC variant claimable once:
 * SKU, then normalised name, then position. Anything left over is an orphan,
 * which the caller turns into a variant it adds to the FC product — visibly,
 * and flagged created_by_migration so rollback takes it back out.
 *
 * A Woo simple product arrives here as a single pseudo-variation keyed by the
 * product ID, because that is exactly how ProductMigrator stores its
 * ENTITY_VARIATION row for simple products.
 */
final class VariantResolver
{
    /**
     * @param list<array{id: int, sku: string, name: string}> $wooVariations Display order.
     * @param list<array{id: int, sku: string, name: string}> $fcVariants    Display order.
     *
     * @return array{map: array<int, int>, orphans: list<int>}
     */
    public function resolve(array $wooVariations, array $fcVariants): array
    {
        $map     = [];
        $claimed = [];

        $remaining = $wooVariations;

        $remaining = $this->pass(
            $remaining,
            $fcVariants,
            $map,
            $claimed,
            function (array $woo, array $fc): bool {
                $a = trim($woo['sku']);
                $b = trim($fc['sku']);

                // Both must be present. Blank equals blank is an absence of
                // evidence, and would pair the first two unSKU'd variants of
                // unrelated sizes.
                return $a !== '' && $b !== '' && strcasecmp($a, $b) === 0;
            },
        );

        $remaining = $this->pass(
            $remaining,
            $fcVariants,
            $map,
            $claimed,
            fn (array $woo, array $fc): bool
                => self::normalizeName($woo['name']) !== ''
                && self::normalizeName($woo['name']) === self::normalizeName($fc['name']),
        );

        // Position: the nth unclaimed FC variant for the nth unmatched Woo
        // variation, in the order the shop displays them.
        foreach ($remaining as $index => $woo) {
            $free = null;

            foreach ($fcVariants as $fc) {
                if (!isset($claimed[$fc['id']])) {
                    $free = $fc;
                    break;
                }
            }

            if ($free === null) {
                continue;
            }

            $map[$woo['id']]       = (int) $free['id'];
            $claimed[$free['id']]  = true;
            unset($remaining[$index]);
        }

        $orphans = array_values(array_map(
            static fn (array $woo): int => (int) $woo['id'],
            $remaining,
        ));

        ksort($map);

        return ['map' => $map, 'orphans' => $orphans];
    }

    /**
     * Run one matching pass, mutating $map and $claimed, returning what is left.
     *
     * @param list<array{id: int, sku: string, name: string}> $remaining
     * @param list<array{id: int, sku: string, name: string}> $fcVariants
     * @param array<int, int>                                 $map
     * @param array<int, bool>                                $claimed
     * @param callable(array, array): bool                    $matches
     *
     * @return array<int, array{id: int, sku: string, name: string}>
     */
    private function pass(
        array $remaining,
        array $fcVariants,
        array &$map,
        array &$claimed,
        callable $matches,
    ): array {
        foreach ($remaining as $index => $woo) {
            foreach ($fcVariants as $fc) {
                if (isset($claimed[$fc['id']]) || !$matches($woo, $fc)) {
                    continue;
                }

                $map[(int) $woo['id']] = (int) $fc['id'];
                $claimed[$fc['id']]    = true;
                unset($remaining[$index]);

                break;
            }
        }

        return $remaining;
    }

    private static function normalizeName(string $name): string
    {
        $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);

        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? '';

        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/phpunit --filter 'VariantResolverTest'`
Expected: PASS

- [ ] **Step 5: Stage**

```bash
git add app/Domain/Mapping/VariantResolver.php tests/Unit/Domain/Mapping/VariantResolverTest.php
```

---

### Task 5: MappingPromoter

**Files:**
- Create: `app/Domain/Migration/MappingPromoter.php`
- Test: `tests/Unit/Domain/Migration/MappingPromoterTest.php`

**Interfaces:**
- Consumes: `ProductMapRepository::linked()` and `::skippedProductIds()` (Task 2), `IdMapRepository::store()` and `::getFcId()`, `Constants::ENTITY_PRODUCT` and `::ENTITY_VARIATION`.
- Produces: `MappingPromoter::__construct(ProductMapRepository $map, IdMapRepository $idMap, callable $fcProductExists)` where `$fcProductExists` is `callable(int): bool`; and `promote(string $migrationId): array` returning `array{linked: int, variants: int, skipped: list<int>, dead: list<int>}`. **Task 5b adds a fourth constructor parameter and an `added` key** — this task's tests are updated there, not here.

- [ ] **Step 1: Write the failing test**

> **Two corrections to the code block below, both learned the hard way.**
>
> 1. **The test doubles do not compile.** `IdMapRepository` and
>    `ProductMapRepository` are both `final class`, so `new class (…) extends
>    IdMapRepository` fatals with "cannot extend final class". Drive the **real**
>    repositories through the `$wpdb` stub's global callbacks instead, with a
>    single insert dispatcher keyed on table name — the stub exposes one shared
>    callback slot and the promoter drives two repositories through it.
>    `tests/Unit/Storage/IdMapRepositoryTest.php` is the established pattern.
> 2. **`testPromotionIsIdempotent` must flush the memo between the two
>    `promote()` calls.** `IdMapRepository::store()` populates an in-request memo
>    and `getFcId()` reads it before touching the database, so reusing one
>    repository instance means the second call's already-promoted check never
>    reaches the query. That is the wrong scenario: a resumed run under REST or
>    Action Scheduler is a *fresh request* with an empty memo (see
>    `IdMapRepository`'s own docblock). Call `flushMemo()` between the calls, or
>    construct a second repository, so the test actually exercises the DB
>    fallback a resumed run depends on.

Create `tests/Unit/Domain/Migration/MappingPromoterTest.php`:

```php
<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Migration\MappingPromoter;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\ProductMapRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * Promotion is the whole feature in one method: a `link` becomes an ID map row
 * with created_by_migration = 0, and every migrator downstream inherits it
 * without knowing this class exists.
 *
 * The flag is not cosmetic. Rollback deletes only created_by_migration = 1, so
 * a promotion that got it wrong would let a rollback delete a product the shop
 * owner built by hand.
 */
final class MappingPromoterTest extends PluginTestCase
{
    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->stored = [];
    }

    private function idMap(): IdMapRepository
    {
        $stored = &$this->stored;

        return new class ($stored) extends IdMapRepository {
            /** @param array<int, array{0: string, 1: string, 2: int, 3: string, 4: bool}> $stored */
            public function __construct(private array &$stored)
            {
            }

            public function store(
                string $entityType,
                string $wcId,
                int $fcId,
                string $migrationId = '',
                bool $createdByMigration = true,
            ): void {
                $this->stored[] = [$entityType, $wcId, $fcId, $migrationId, $createdByMigration];
            }

            public function getFcId(string $entityType, string $wcId): int|null
            {
                foreach ($this->stored as $row) {
                    if ($row[0] === $entityType && $row[1] === $wcId) {
                        return $row[2];
                    }
                }

                return null;
            }
        };
    }

    private function mapRepo(array $decisions): ProductMapRepository
    {
        return new class ($decisions) extends ProductMapRepository {
            /** @param list<ProductMapDecision> $decisions */
            public function __construct(private readonly array $decisions)
            {
            }

            public function linked(): array
            {
                return array_values(array_filter(
                    $this->decisions,
                    static fn (ProductMapDecision $d): bool => $d->isLink(),
                ));
            }

            public function skippedProductIds(): array
            {
                $ids = array_map(
                    static fn (ProductMapDecision $d): int => $d->wcId(),
                    array_filter($this->decisions, static fn (ProductMapDecision $d): bool => $d->isSkip()),
                );

                sort($ids);

                return array_values($ids);
            }
        };
    }

    public function testALinkIsPromotedWithCreatedByMigrationFalse(): void
    {
        $promoter = new MappingPromoter(
            $this->mapRepo([ProductMapDecision::link(42, 'simple', 900, 'strong', [42 => 777])]),
            $this->idMap(),
            static fn (int $id): bool => true,
        );

        $result = $promoter->promote('run-1');

        $this->assertSame(1, $result['linked']);
        $this->assertSame(1, $result['variants']);

        $this->assertSame(
            [Constants::ENTITY_PRODUCT, '42', 900, 'run-1', false],
            $this->stored[0],
            'A hand-made FluentCart product was not created by this migration and rollback must not delete it.',
        );
        $this->assertSame(
            [Constants::ENTITY_VARIATION, '42', 777, 'run-1', false],
            $this->stored[1],
        );
    }

    public function testEveryVariantInTheMapIsPromoted(): void
    {
        $promoter = new MappingPromoter(
            $this->mapRepo([
                ProductMapDecision::link(42, 'variable', 900, 'strong', [11 => 501, 12 => 502, 13 => 503]),
            ]),
            $this->idMap(),
            static fn (int $id): bool => true,
        );

        $result = $promoter->promote('run-1');

        $this->assertSame(3, $result['variants']);
        $this->assertCount(4, $this->stored);
    }

    public function testADeadLinkDegradesToCreateAndIsReported(): void
    {
        $promoter = new MappingPromoter(
            $this->mapRepo([ProductMapDecision::link(42, 'simple', 900, 'strong', [42 => 777])]),
            $this->idMap(),
            // The owner deleted the FluentCart product between mapping and running.
            static fn (int $id): bool => false,
        );

        $result = $promoter->promote('run-1');

        $this->assertSame(0, $result['linked']);
        $this->assertSame([900], $result['dead']);
        $this->assertSame([], $this->stored, 'An ID map row pointing at a deleted post is worse than no row.');
    }

    public function testPromotionIsIdempotent(): void
    {
        $promoter = new MappingPromoter(
            $this->mapRepo([ProductMapDecision::link(42, 'simple', 900, 'strong', [42 => 777])]),
            $this->idMap(),
            static fn (int $id): bool => true,
        );

        $promoter->promote('run-1');
        $second = $promoter->promote('run-1');

        $this->assertSame(0, $second['linked'], 'A resumed run must not double-promote.');
        $this->assertCount(2, $this->stored);
    }

    public function testSkipsAreReportedAndNeverStored(): void
    {
        $promoter = new MappingPromoter(
            $this->mapRepo([
                ProductMapDecision::skip(7, 'simple', 'none'),
                ProductMapDecision::skip(9, 'simple', 'none'),
            ]),
            $this->idMap(),
            static fn (int $id): bool => true,
        );

        $result = $promoter->promote('run-1');

        $this->assertSame([7, 9], $result['skipped']);
        $this->assertSame([], $this->stored);
    }

    public function testACreateDecisionDoesNothingAtAll(): void
    {
        $promoter = new MappingPromoter(
            $this->mapRepo([ProductMapDecision::create(7, 'simple', 'none')]),
            $this->idMap(),
            static fn (int $id): bool => true,
        );

        $result = $promoter->promote('run-1');

        $this->assertSame(0, $result['linked']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame([], $this->stored, 'Create is CartShift default behaviour, not an instruction.');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit --filter 'MappingPromoterTest'`
Expected: FAIL — `Class "CartShift\Domain\Migration\MappingPromoter" not found`.

- [ ] **Step 3: Write the promoter**

Create `app/Domain/Migration/MappingPromoter.php`:

```php
<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration;

use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\ProductMapRepository;
use CartShift\Support\Constants;

defined('ABSPATH') || exit;

/**
 * Turns the owner's drafted decisions into ID map rows, once, at run start.
 *
 * This is the entire integration surface of product mapping. Every migrator
 * downstream resolves products through IdMapRepository::getFcId(), so once a
 * link is promoted, orders, subscriptions, coupon restrictions and downloads
 * all attach to the owner's own FluentCart product without a line of change.
 *
 * created_by_migration is passed as false and that is safety-critical:
 * rollback deletes only rows flagged true, so a product the owner built by
 * hand survives a rollback of the run that referenced it.
 */
final class MappingPromoter
{
    /**
     * Normalised from the constructor's $fcProductExists at construction time.
     *
     * A property cannot be declared `callable` in PHP — only `Closure` is a
     * real type, standalone or in a union. `private readonly \Closure|callable`
     * is a fatal at class load, not a warning.
     */
    private readonly \Closure $fcProductExists;

    /**
     * @param callable(int): bool $fcProductExists Injected rather than called
     *        directly on FluentCart, so this class stays unit-testable without
     *        a live FluentCart install.
     */
    public function __construct(
        private readonly ProductMapRepository $map,
        private readonly IdMapRepository $idMap,
        callable $fcProductExists,
    ) {
        $this->fcProductExists = \Closure::fromCallable($fcProductExists);
    }

    /**
     * @return array{linked: int, variants: int, skipped: list<int>, dead: list<int>}
     */
    public function promote(string $migrationId): array
    {
        $linked   = 0;
        $variants = 0;
        $dead     = [];

        foreach ($this->map->linked() as $decision) {
            $fcPostId = $decision->fcPostId();

            if ($fcPostId === null) {
                continue;
            }

            // A link whose target was deleted between mapping and running is a
            // reason to fall back to creating the product, not a reason to fail
            // a migration the owner has already confirmed. The caller logs it.
            if (!($this->fcProductExists)($fcPostId)) {
                $dead[] = $fcPostId;
                continue;
            }

            // Resumed runs re-enter this method. The ID map is the record of
            // what has already been promoted, so consult it rather than keeping
            // a separate "promoted" flag that could disagree with it.
            if ($this->idMap->getFcId(Constants::ENTITY_PRODUCT, (string) $decision->wcId()) !== null) {
                continue;
            }

            $this->idMap->store(
                Constants::ENTITY_PRODUCT,
                (string) $decision->wcId(),
                $fcPostId,
                $migrationId,
                false,
            );

            $linked++;

            foreach ($decision->variantMap() as $wooVariationId => $fcVariationId) {
                $this->idMap->store(
                    Constants::ENTITY_VARIATION,
                    (string) $wooVariationId,
                    $fcVariationId,
                    $migrationId,
                    false,
                );

                $variants++;
            }
        }

        return [
            'linked'   => $linked,
            'variants' => $variants,
            'skipped'  => $this->map->skippedProductIds(),
            'dead'     => $dead,
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/phpunit --filter 'MappingPromoterTest'`
Expected: PASS

- [ ] **Step 5: Stage**

```bash
git add app/Domain/Migration/MappingPromoter.php tests/Unit/Domain/Migration/MappingPromoterTest.php
```

---

### Task 5b: Orphan variant creation

**Files:**
- Modify: `app/Domain/Migration/MappingPromoter.php`
- Test: `tests/Unit/Domain/Migration/MappingPromoterOrphanTest.php`

Without this task the feature is silently broken: a linked product is skipped by `ProductMigrator` (the ID map says "already migrated"), so nothing else in the codebase ever creates the missing "XL" variant, and every order line item for it resolves to null. Promotion is the only place that both knows the orphan list and runs before the migrators.

**Interfaces:**
- Consumes: `ProductMapDecision::orphans()` (Task 2), `MappingPromoter` (Task 5).
- Produces: a fourth constructor parameter on `MappingPromoter` — `callable(int $fcPostId, array{id: int, sku: string, name: string} $orphan): ?int $createVariant`, returning the new FC variation ID or null. `promote()`'s return grows an `added` key: `array{linked: int, variants: int, added: int, skipped: list<int>, dead: list<int>}`.

- [ ] **Step 1: Write the failing test**

> **The test doubles in the code block below do not compile.** `IdMapRepository`
> and `ProductMapRepository` are both `final class`, so `new class (…) extends
> IdMapRepository` fatals with "cannot extend final class" — anonymous or not.
> Take the double-wiring from the shipped
> `tests/Unit/Domain/Migration/MappingPromoterTest.php` instead: it drives the
> **real** repositories through the `$wpdb` stub's global callbacks, with a
> single insert dispatcher keyed on table name (the stub exposes one shared
> callback slot, and the promoter drives two repositories through it). Keep the
> test *bodies* and assertions below as specified — only the double-construction
> changes.

Create `tests/Unit/Domain/Migration/MappingPromoterOrphanTest.php`:

```php
<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Migration\MappingPromoter;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\ProductMapRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * A Woo variation with no FluentCart counterpart gets one created inside the
 * owner's product, flagged created_by_migration = 1 so rollback takes it back
 * out. The flag is the difference between "CartShift added a variant" and
 * "CartShift deleted the owner catalogue", so it is asserted explicitly.
 */
final class MappingPromoterOrphanTest extends PluginTestCase
{
    private array $stored = [];
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->stored = [];
        $this->created = [];
    }

    private function idMap(): IdMapRepository
    {
        $stored = &$this->stored;

        return new class ($stored) extends IdMapRepository {
            public function __construct(private array &$stored)
            {
            }

            public function store(
                string $entityType,
                string $wcId,
                int $fcId,
                string $migrationId = '',
                bool $createdByMigration = true,
            ): void {
                $this->stored[] = [$entityType, $wcId, $fcId, $migrationId, $createdByMigration];
            }

            public function getFcId(string $entityType, string $wcId): int|null
            {
                foreach ($this->stored as $row) {
                    if ($row[0] === $entityType && $row[1] === $wcId) {
                        return $row[2];
                    }
                }

                return null;
            }
        };
    }

    private function mapRepo(array $decisions): ProductMapRepository
    {
        return new class ($decisions) extends ProductMapRepository {
            public function __construct(private readonly array $decisions)
            {
            }

            public function linked(): array
            {
                return array_values(array_filter(
                    $this->decisions,
                    static fn (ProductMapDecision $d): bool => $d->isLink(),
                ));
            }

            public function skippedProductIds(): array
            {
                return [];
            }
        };
    }

    private function promoter(array $decisions, ?callable $createVariant = null): MappingPromoter
    {
        $created = &$this->created;

        return new MappingPromoter(
            $this->mapRepo($decisions),
            $this->idMap(),
            static fn (int $id): bool => true,
            $createVariant ?? static function (int $fcPostId, array $orphan) use (&$created): ?int {
                $created[] = [$fcPostId, $orphan['name']];
                return 9000 + $orphan['id'];
            },
        );
    }

    public function testAnOrphanVariantIsCreatedAndFlaggedAsOurs(): void
    {
        $decision = ProductMapDecision::link(
            42,
            'variable',
            900,
            'likely',
            [11 => 501],
            [['id' => 13, 'sku' => 'TS-XL', 'name' => 'XL']],
        );

        $result = $this->promoter([$decision])->promote('run-1');

        $this->assertSame(1, $result['added']);
        $this->assertSame([[900, 'XL']], $this->created);

        $orphanRow = $this->stored[2];

        $this->assertSame(Constants::ENTITY_VARIATION, $orphanRow[0]);
        $this->assertSame('13', $orphanRow[1]);
        $this->assertSame(9013, $orphanRow[2]);
        $this->assertTrue(
            $orphanRow[4],
            'A variant CartShift added is migration output and must roll back with the run.',
        );
    }

    public function testTheProductItselfIsStillFlaggedAsNotOurs(): void
    {
        $decision = ProductMapDecision::link(
            42,
            'variable',
            900,
            'likely',
            [],
            [['id' => 13, 'sku' => '', 'name' => 'XL']],
        );

        $this->promoter([$decision])->promote('run-1');

        $this->assertFalse(
            $this->stored[0][4],
            'Adding a variant does not make the owner product ours to delete.',
        );
    }

    public function testAFailedVariantCreationIsNotMappedToNothing(): void
    {
        $decision = ProductMapDecision::link(
            42,
            'variable',
            900,
            'likely',
            [],
            [['id' => 13, 'sku' => '', 'name' => 'XL']],
        );

        $result = $this->promoter([$decision], static fn (int $p, array $o): ?int => null)->promote('run-1');

        $this->assertSame(0, $result['added']);
        $this->assertCount(1, $this->stored, 'Only the product row; a null variant must not be stored.');
    }

    public function testNoOrphansMeansNoCreation(): void
    {
        $decision = ProductMapDecision::link(42, 'simple', 900, 'strong', [42 => 777]);

        $result = $this->promoter([$decision])->promote('run-1');

        $this->assertSame(0, $result['added']);
        $this->assertSame([], $this->created);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit --filter 'MappingPromoterOrphanTest'`
Expected: FAIL — `MappingPromoter::__construct()` takes three arguments, not four.

- [ ] **Step 3: Extend the promoter**

In `app/Domain/Migration/MappingPromoter.php`, add the parameter:

```php
/**
 * @param callable(int): bool                                                  $fcProductExists
 * @param callable(int, array{id: int, sku: string, name: string}): ?int       $createVariant
 *        Creates one FluentCart variant on the given product and returns its
 *        ID. Injected for the same reason as $fcProductExists: this class must
 *        stay testable without a live FluentCart.
 */
private readonly \Closure $createVariant;

public function __construct(
    private readonly ProductMapRepository $map,
    private readonly IdMapRepository $idMap,
    callable $fcProductExists,
    callable $createVariant,
) {
    $this->fcProductExists = \Closure::fromCallable($fcProductExists);
    $this->createVariant   = \Closure::fromCallable($createVariant);
}
```

`$fcProductExists` is already a declared `private readonly \Closure` property from Task 5; declare `$createVariant` beside it. **Neither may be a promoted property typed `callable`** — PHP has no `callable` property type, standalone or in a union, so `private readonly \Closure|callable $x` fatals at class load.

Add the counter and the orphan loop. Inside `promote()`, initialise `$added = 0;` alongside the other counters, and after the existing `variantMap()` loop, add:

```php
// The one place CartShift writes into a product the owner built by hand.
// Flagged created_by_migration = 1, unlike the product row above, because
// this variant IS migration output and rollback should remove it.
foreach ($decision->orphans() as $orphan) {
    $fcVariationId = ($this->createVariant)($fcPostId, $orphan);

    if ($fcVariationId === null || $fcVariationId <= 0) {
        continue;
    }

    $this->idMap->store(
        Constants::ENTITY_VARIATION,
        (string) $orphan['id'],
        $fcVariationId,
        $migrationId,
        true,
    );

    $added++;
}
```

Extend the return:

```php
return [
    'linked'   => $linked,
    'variants' => $variants,
    'added'    => $added,
    'skipped'  => $this->map->skippedProductIds(),
    'dead'     => $dead,
];
```

- [ ] **Step 4: Run both promoter test classes**

Run: `./vendor/bin/phpunit --filter 'MappingPromoter'`
Expected: PASS. `MappingPromoterTest` from Task 5 constructs the promoter with three arguments and will now fatal — add a fourth argument to each of its five constructions:

```php
static fn (int $fcPostId, array $orphan): ?int => null,
```

Those tests use decisions with no orphans, so a creator that never fires is the honest stub.

- [ ] **Step 5: Run the whole suite**

Run: `./vendor/bin/phpunit`
Expected: PASS. `MappingPromoter` has no container registration yet — that is Task 8's job, and it registers all four arguments at once.

- [ ] **Step 6: Stage**

```bash
git add app/Domain/Migration/MappingPromoter.php tests/Unit/Domain/Migration/
```

---

### Task 6: ProductMigrator honours the skip list

**Files:**
- Modify: `app/Migrator/ProductMigrator.php` (`countTotal()` at :607, `fetchProductIdPage()` at :805)
- Test: `tests/Unit/Migrator/Entity/ProductSkipExclusionTest.php`

**Interfaces:**
- Consumes: `MappingPromoter::promote()['skipped']` (Task 5).
- Produces: `ProductMigrator::excludeProductIds(array $ids): void`. Called by `MigrationModule` (Task 8). Absent or empty, the SQL is byte-identical to today's.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Migrator/Entity/ProductSkipExclusionTest.php`:

```php
<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Migrator\ProductMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The count and the page query must agree, or the progress bar lies about the
 * denominator — the exact failure the type-predicate consolidation fixed. The
 * skip list is a second chance to reintroduce it, so both are asserted here.
 */
final class ProductSkipExclusionTest extends PluginTestCase
{
    private function migrator(): ProductMigrator
    {
        return new ProductMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
    }

    /** @return list<string> */
    private function capturedQueries(): array
    {
        $queries = [];

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $entry) {
            if (is_string($entry)) {
                $queries[] = $entry;
            }
        }

        return $queries;
    }

    public function testNoSkipListLeavesTheCountQueryUnchanged(): void
    {
        $seen = null;

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use (&$seen): string {
            $seen = $query;
            return '0';
        };

        $this->migrator()->count();

        $this->assertIsString($seen);
        $this->assertStringNotContainsString('NOT IN', $seen);
    }

    public function testASkipListExcludesThoseIdsFromTheCount(): void
    {
        $seen = null;

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use (&$seen): string {
            $seen = $query;
            return '0';
        };

        $migrator = $this->migrator();
        $migrator->excludeProductIds([7, 9]);
        $migrator->count();

        $this->assertIsString($seen);
        $this->assertStringContainsString('p.ID NOT IN (7,9)', $seen);
    }

    public function testASkipListExcludesThoseIdsFromThePageQuery(): void
    {
        $seen = null;

        $GLOBALS['_cartshift_test_get_col_callback'] = static function (string $query) use (&$seen): array {
            $seen = $query;
            return [];
        };

        $migrator = $this->migrator();
        $migrator->excludeProductIds([7, 9]);
        $migrator->fetchBatch(null, 10);

        $this->assertIsString($seen);
        $this->assertStringContainsString('p.ID NOT IN (7,9)', $seen);
    }

    public function testTheSkipListIsSanitisedToIntegers(): void
    {
        $seen = null;

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use (&$seen): string {
            $seen = $query;
            return '0';
        };

        $migrator = $this->migrator();
        // Whatever reaches this setter has been through REST sanitisation, but
        // an exclusion list spliced into SQL is not the place to rely on that.
        $migrator->excludeProductIds([7, 0, -3, 9]);
        $migrator->count();

        $this->assertStringContainsString('p.ID NOT IN (7,9)', $seen);
    }
}
```

- [ ] **Step 2: Add the get_col test hook**

In `tests/stubs/test-bootstrap.php`, make `wpdb::get_col()` delegate the same way `get_var()` and `get_results()` already do:

```php
public function get_col(string $query): array
{
    $GLOBALS['_cartshift_test_queries'][] = $query;

    if (isset($GLOBALS['_cartshift_test_get_col_callback'])) {
        return ($GLOBALS['_cartshift_test_get_col_callback'])($query);
    }

    return [];
}
```

Keep whatever the existing body returns as the fallback — only the callback branch is new.

- [ ] **Step 3: Run the test to verify it fails**

Run: `./vendor/bin/phpunit --filter 'ProductSkipExclusionTest'`
Expected: FAIL — `Call to undefined method CartShift\Migrator\ProductMigrator::excludeProductIds()`.

- [ ] **Step 4: Implement the exclusion**

In `app/Migrator/ProductMigrator.php`, add the property and setter near the other state, above `countTotal()`:

```php
/**
 * WooCommerce product IDs the owner explicitly chose not to migrate.
 *
 * Set from the mapping screen's `skip` decisions, via MigrationModule. Empty
 * for every run that never opened the mapping screen, which is why the SQL
 * below appends nothing at all rather than a vacuous `NOT IN ()`.
 *
 * @var list<int>
 */
private array $excludedProductIds = [];

/**
 * @param list<int> $ids
 */
public function excludeProductIds(array $ids): void
{
    $clean = [];

    foreach ($ids as $id) {
        $id = (int) $id;

        if ($id > 0) {
            $clean[$id] = true;
        }
    }

    $clean = array_keys($clean);
    sort($clean);

    $this->excludedProductIds = $clean;
}

/**
 * The exclusion clause, or an empty string.
 *
 * Built by casting to int and joining rather than by %d placeholders, because
 * both call sites already spread a positional argument list into prepare() and
 * a variable-length placeholder run there is how off-by-one argument bugs get
 * written. Every element is an int by construction — see excludeProductIds().
 */
private function exclusionSql(): string
{
    if ($this->excludedProductIds === []) {
        return '';
    }

    return ' AND p.ID NOT IN (' . implode(',', $this->excludedProductIds) . ')';
}
```

In `countTotal()`, append the clause to the SQL string, immediately after `$selection->andSql()`:

```php
return (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*)
     FROM {$wpdb->prefix}wc_product_meta_lookup pml
     INNER JOIN {$wpdb->posts} p ON p.ID = pml.product_id
     WHERE p.post_type = 'product'
       AND p.post_status IN ('publish', 'draft', 'private')
       AND {$typeSql}"
    . $selection->andSql()
    . $this->exclusionSql(),
    ...[...$typeValues, ...$selection->values()],
));
```

In `fetchProductIdPage()`, the same, before the `ORDER BY`:

```php
$ids = $wpdb->get_col($wpdb->prepare(
    "SELECT p.ID
     FROM {$wpdb->prefix}wc_product_meta_lookup pml
     INNER JOIN {$wpdb->posts} p ON p.ID = pml.product_id
     WHERE p.post_type = 'product'
       AND p.post_status IN ('publish', 'draft', 'private')
       AND p.ID > %d
       AND {$typeSql}"
    . $selection->andSql()
    . $this->exclusionSql()
    . " ORDER BY p.ID ASC
     LIMIT %d",
    ...[$afterId, ...$typeValues, ...$selection->values(), $limit],
));
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `./vendor/bin/phpunit --filter 'ProductSkipExclusionTest'`
Expected: PASS

- [ ] **Step 6: Run the whole suite**

Run: `./vendor/bin/phpunit`
Expected: PASS. `KeysetSourceQueryTest`, `ScopedKeysetTest` and `ProductTypePredicateAgreementTest` all assert on these two queries; with an empty exclusion list the SQL is unchanged, so they must stay green. If any fails, the clause is being appended when the list is empty.

- [ ] **Step 7: Stage**

```bash
git add app/Migrator/ProductMigrator.php tests/Unit/Migrator/Entity/ProductSkipExclusionTest.php tests/stubs/test-bootstrap.php
```

---

### Task 7: Rollback safety regression

**Files:**
- Test: `tests/Unit/Domain/Migration/MappingRollbackSafetyTest.php`

This task adds no production code. It pins the property the whole design rests on, so that a future change to `IdMapRepository` or `MigrationRollback` cannot quietly remove it.

**Interfaces:**
- Consumes: `IdMapRepository::getCreatedByMigration()` (`app/Storage/IdMapRepository.php:232`), `MappingPromoter` (Task 5).
- Produces: nothing.

- [ ] **Step 1: Write the test**

Create `tests/Unit/Domain/Migration/MappingRollbackSafetyTest.php`:

```php
<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The load-bearing invariant of product mapping.
 *
 * A linked FluentCart product was built by the shop owner, not by CartShift.
 * Rollback deletes only what the migration created, and it decides that from
 * created_by_migration alone. If this test ever goes red, a rollback is
 * deleting the owner's catalogue.
 */
final class MappingRollbackSafetyTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_id_map_rows'] = [];

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query): array {
            if (!str_contains($query, 'cartshift_id_map')) {
                return [];
            }

            preg_match("/entity_type = '([^']*)'/", $query, $matches);
            $createdOnly = str_contains($query, 'created_by_migration = 1');

            $rows = [];

            foreach ($GLOBALS['_cartshift_test_id_map_rows'] as $row) {
                if ($row['entity_type'] !== $matches[1]) {
                    continue;
                }

                if ($createdOnly && $row['created_by_migration'] !== 1) {
                    continue;
                }

                $rows[] = (object) ['wc_id' => $row['wc_id'], 'fc_id' => $row['fc_id']];
            }

            return $rows;
        };
    }

    private function seed(string $entityType, string $wcId, int $fcId, bool $createdByMigration): void
    {
        $GLOBALS['_cartshift_test_id_map_rows'][] = [
            'entity_type'          => $entityType,
            'wc_id'                => $wcId,
            'fc_id'                => $fcId,
            'migration_id'         => 'run-1',
            'created_by_migration' => $createdByMigration ? 1 : 0,
            'is_simulated'         => 0,
        ];
    }

    public function testRollbackNeverSeesALinkedProduct(): void
    {
        // 900 is the owner's hand-made product, promoted from a link.
        $this->seed(Constants::ENTITY_PRODUCT, '42', 900, false);
        // 901 is a product CartShift created for an unmapped Woo product.
        $this->seed(Constants::ENTITY_PRODUCT, '43', 901, true);

        $deletable = (new IdMapRepository())->getCreatedByMigration(Constants::ENTITY_PRODUCT, 'run-1');

        $ids = array_map(static fn (object $row): int => (int) $row->fc_id, $deletable);

        $this->assertSame([901], $ids, 'Rollback must not delete a product the owner built by hand.');
    }

    public function testAVariantCartShiftAddedIsStillDeletable(): void
    {
        // The "adds XL" case: the product is the owner's, the variant is ours.
        $this->seed(Constants::ENTITY_VARIATION, '11', 501, false);
        $this->seed(Constants::ENTITY_VARIATION, '13', 999, true);

        $deletable = (new IdMapRepository())->getCreatedByMigration(Constants::ENTITY_VARIATION, 'run-1');

        $ids = array_map(static fn (object $row): int => (int) $row->fc_id, $deletable);

        $this->assertSame(
            [999],
            $ids,
            'A variant CartShift added to the owner product is migration output and must roll back.',
        );
    }
}
```

- [ ] **Step 2: Run the test**

Run: `./vendor/bin/phpunit --filter 'MappingRollbackSafetyTest'`
Expected: PASS immediately — this is a characterisation test of behaviour that already exists. If it fails, `getCreatedByMigration()` has changed and the design's safety claim is void; stop and report rather than adjusting the test.

- [ ] **Step 3: Stage**

```bash
git add tests/Unit/Domain/Migration/MappingRollbackSafetyTest.php
```

---

### Task 8: MappingController and wiring

**Files:**
- Create: `app/Http/Controllers/MappingController.php`
- Modify: `app/Modules/Infrastructure/InfrastructureModule.php`
- Modify: `app/Modules/Migration/MigrationModule.php`
- Modify: `app/Http/Controllers/PreflightController.php`
- Test: `tests/Unit/Http/Controllers/MappingControllerTest.php`

**Interfaces:**
- Consumes: `ProductMapRepository` (Task 2), `ProductMatcher` (Task 3), `VariantResolver` (Task 4), `MappingPromoter` (Task 5), `ProductMigrator::excludeProductIds()` (Task 6).
- Produces four REST routes under `cartshift/v1`, all `permission_callback` = `manage_options`:
  - `GET  mapping/rows?page=1&per_page=50` → `{data: {rows: [...], total: int, fc_product_count: int}}`
  - `POST mapping/decide` body `{wc_id, decision, fc_post_id?, band?, variant_map?}` → `{data: {saved: true, decision: {...}}}`
  - `POST mapping/bulk` body `{band, decision, rows: [{wc_id, fc_post_id?, variant_map?}]}` → `{data: {saved: int}}`
  - `POST mapping/clear` → `{data: {cleared: true}}`
- Also produces `fc_product_count` on the existing preflight response, which is what lets the Vue app skip the step.

- [ ] **Step 1: Write the failing test**

> **The `new class (…) extends ProductMapRepository` double below does not
> compile** — `ProductMapRepository` is `final`. Use the real repository driven
> through the `$wpdb` stub's global callbacks, as
> `tests/Unit/Domain/Migration/MappingPromoterTest.php` and
> `tests/Unit/Storage/ProductMapRepositoryTest.php` both do. Keep the test
> bodies and assertions as specified — only the double-construction changes.

Create `tests/Unit/Http/Controllers/MappingControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Http\Controllers\MappingController;
use CartShift\Storage\ProductMapRepository;
use CartShift\Tests\Unit\PluginTestCase;
use WP_REST_Request;

/**
 * The controller is a thin seam: it sanitises, delegates, and wraps in
 * {data: …} because useApi() unwraps exactly that. The tests worth having are
 * about refusing rubbish, not about the happy path.
 */
final class MappingControllerTest extends PluginTestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->saved = [];
    }

    private function controller(): MappingController
    {
        $saved = &$this->saved;

        $repo = new class ($saved) extends ProductMapRepository {
            /** @param list<ProductMapDecision> $saved */
            public function __construct(private array &$saved)
            {
            }

            public function save(ProductMapDecision $decision): void
            {
                $this->saved[] = $decision;
            }

            public function all(): array
            {
                return $this->saved;
            }

            public function clear(): void
            {
                $this->saved = [];
            }
        };

        $container = new Container();
        $container->instance(ProductMapRepository::class, $repo);

        return new MappingController($container);
    }

    private function request(array $params): WP_REST_Request
    {
        $request = new WP_REST_Request();

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }

    public function testDecideSavesALink(): void
    {
        $response = $this->controller()->decide($this->request([
            'wc_id'       => 42,
            'wc_type'     => 'variable',
            'decision'    => 'link',
            'fc_post_id'  => 900,
            'band'        => 'strong',
            'variant_map' => ['11' => '501'],
        ]));

        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $this->saved);
        $this->assertSame('link', $this->saved[0]->decision());
        $this->assertSame(900, $this->saved[0]->fcPostId());
        $this->assertSame([11 => 501], $this->saved[0]->variantMap());
    }

    public function testALinkWithoutATargetIsRefused(): void
    {
        $response = $this->controller()->decide($this->request([
            'wc_id'    => 42,
            'decision' => 'link',
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame([], $this->saved, 'A link with nowhere to point must never reach the table.');
    }

    public function testAnUnknownDecisionIsRefused(): void
    {
        $response = $this->controller()->decide($this->request([
            'wc_id'    => 42,
            'decision' => 'obliterate',
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame([], $this->saved);
    }

    public function testAMissingProductIsRefused(): void
    {
        $response = $this->controller()->decide($this->request([
            'wc_id'    => 0,
            'decision' => 'skip',
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame([], $this->saved);
    }

    public function testBulkSavesEveryRow(): void
    {
        $response = $this->controller()->bulk($this->request([
            'decision' => 'create',
            'band'     => 'none',
            'rows'     => [
                ['wc_id' => 1, 'wc_type' => 'simple'],
                ['wc_id' => 2, 'wc_type' => 'simple'],
                ['wc_id' => 3, 'wc_type' => 'simple'],
            ],
        ]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(3, $response->get_data()['data']['saved']);
        $this->assertCount(3, $this->saved);
    }

    public function testBulkSkipsRowsItCannotUse(): void
    {
        $response = $this->controller()->bulk($this->request([
            'decision' => 'link',
            'band'     => 'strong',
            'rows'     => [
                ['wc_id' => 1, 'fc_post_id' => 900],
                ['wc_id' => 2],
            ],
        ]));

        $this->assertSame(1, $response->get_data()['data']['saved']);
        $this->assertCount(1, $this->saved);
    }

    public function testClearEmptiesTheTable(): void
    {
        $controller = $this->controller();

        $controller->decide($this->request(['wc_id' => 1, 'decision' => 'skip']));
        $controller->clear($this->request([]));

        $this->assertSame([], $this->saved);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit --filter 'MappingControllerTest'`
Expected: FAIL — `Class "CartShift\Http\Controllers\MappingController" not found`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/MappingController.php`:

```php
<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Mapping\ProductMatcher;
use CartShift\Domain\Mapping\VariantResolver;
use CartShift\Storage\ProductMapRepository;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * REST surface for the mapping screen.
 *
 * Read-only against WooCommerce and FluentCart; the only thing it writes is
 * the staging table. Nothing here promotes anything into the ID map — that
 * happens once, at run start, in MappingPromoter.
 */
final class MappingController
{
    private const string NAMESPACE = 'cartshift/v1';

    private const int DEFAULT_PER_PAGE = 50;
    private const int MAX_PER_PAGE     = 200;

    private const int MAX_CANDIDATES = 8;

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/mapping/rows', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rows'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => [
                'page'     => ['type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint'],
                'per_page' => ['type' => 'integer', 'default' => self::DEFAULT_PER_PAGE, 'sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/mapping/decide', [
            'methods'             => 'POST',
            'callback'            => [$this, 'decide'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/mapping/bulk', [
            'methods'             => 'POST',
            'callback'            => [$this, 'bulk'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/mapping/clear', [
            'methods'             => 'POST',
            'callback'            => [$this, 'clear'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * One page of Woo products, each with ranked FluentCart candidates.
     *
     * Paginated because the matcher is O(page x catalogue) and a 2,000-product
     * shop against a 500-product FluentCart catalogue is a million comparisons
     * in one request otherwise.
     */
    public function rows(WP_REST_Request $request): WP_REST_Response
    {
        $page    = max(1, (int) $request->get_param('page'));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->get_param('per_page')));

        $wooProducts = $this->wooProductPage($page, $perPage);
        $candidates  = $this->fcCandidates();

        $matcher  = new ProductMatcher();
        $resolver = new VariantResolver();
        $repo     = $this->repo();

        $rows = [];

        foreach ($wooProducts as $woo) {
            $match = $matcher->match($woo['match_fields'], $candidates);

            $ranked = array_slice($match['ranked'], 0, self::MAX_CANDIDATES);

            $existing = $repo->get($woo['id']);

            $variantSummary = null;

            if ($match['candidate_id'] !== null) {
                $resolved = $resolver->resolve(
                    $woo['variations'],
                    $this->fcVariants($match['candidate_id']),
                );

                // The resolver returns orphan IDs; promotion needs the whole
                // descriptor to name and SKU the variant it will create, so
                // rehydrate here where the Woo variations are still in hand.
                $byId = [];

                foreach ($woo['variations'] as $variation) {
                    $byId[$variation['id']] = $variation;
                }

                $orphanDetail = [];

                foreach ($resolved['orphans'] as $orphanId) {
                    if (isset($byId[$orphanId])) {
                        $orphanDetail[] = $byId[$orphanId];
                    }
                }

                $variantSummary = [
                    'matched' => count($resolved['map']),
                    'total'   => count($woo['variations']),
                    'adds'    => count($orphanDetail),
                    'map'     => $resolved['map'],
                    'orphans' => $orphanDetail,
                ];
            }

            $rows[] = [
                'wc_id'       => $woo['id'],
                'name'        => $woo['name'],
                'wc_type'     => $woo['type'],
                'sku'         => $woo['match_fields']['sku'],
                'variations'  => count($woo['variations']),
                'order_count' => $woo['order_count'],
                'band'        => $match['band'],
                'suggested'   => $match['candidate_id'],
                'candidates'  => $this->labelCandidates($ranked, $candidates),
                'variant'     => $variantSummary,
                'decision'    => $existing?->toArray(),
            ];
        }

        return new WP_REST_Response(['data' => [
            'rows'             => $rows,
            'page'             => $page,
            'per_page'         => $perPage,
            'total'            => $this->wooProductCount(),
            'fc_product_count' => count($candidates),
        ]]);
    }

    public function decide(WP_REST_Request $request): WP_REST_Response
    {
        $wcId = absint($request->get_param('wc_id'));

        if ($wcId <= 0) {
            return $this->refuse('A decision needs a WooCommerce product.');
        }

        $decision = sanitize_text_field((string) $request->get_param('decision'));
        $wcType   = sanitize_text_field((string) ($request->get_param('wc_type') ?? ''));
        $band     = sanitize_text_field((string) ($request->get_param('band') ?? ProductMatcher::BAND_NONE));

        $built = $this->build($wcId, $wcType, $decision, $band, [
            'fc_post_id'  => $request->get_param('fc_post_id'),
            'variant_map' => $request->get_param('variant_map'),
            'orphans'     => $request->get_param('orphans'),
        ]);

        if ($built === null) {
            return $this->refuse(sprintf('Unusable decision "%s" for product %d.', $decision, $wcId));
        }

        $this->repo()->save($built);

        return new WP_REST_Response(['data' => ['saved' => true, 'decision' => $built->toArray()]]);
    }

    /**
     * Apply one decision to many rows.
     *
     * Rows it cannot use are dropped rather than failing the batch: a bulk
     * "link all" over a band where one row lost its candidate should link the
     * other eighteen, not refuse the lot.
     */
    public function bulk(WP_REST_Request $request): WP_REST_Response
    {
        $decision = sanitize_text_field((string) $request->get_param('decision'));
        $band     = sanitize_text_field((string) ($request->get_param('band') ?? ProductMatcher::BAND_NONE));
        $rows     = $request->get_param('rows');

        if (!is_array($rows)) {
            return $this->refuse('Bulk needs a list of rows.');
        }

        $built = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $wcId = absint($row['wc_id'] ?? 0);

            if ($wcId <= 0) {
                continue;
            }

            $one = $this->build(
                $wcId,
                sanitize_text_field((string) ($row['wc_type'] ?? '')),
                $decision,
                $band,
                [
                    'fc_post_id'  => $row['fc_post_id'] ?? null,
                    'variant_map' => $row['variant_map'] ?? null,
                    'orphans'     => $row['orphans'] ?? null,
                ],
            );

            if ($one !== null) {
                $built[] = $one;
            }
        }

        $this->repo()->saveMany($built);

        return new WP_REST_Response(['data' => ['saved' => count($built)]]);
    }

    public function clear(WP_REST_Request $request): WP_REST_Response
    {
        $this->repo()->clear();

        return new WP_REST_Response(['data' => ['cleared' => true]]);
    }

    /**
     * @param array{fc_post_id: mixed, variant_map: mixed, orphans: mixed} $extra
     */
    private function build(int $wcId, string $wcType, string $decision, string $band, array $extra): ?ProductMapDecision
    {
        if ($decision === ProductMapDecision::CREATE) {
            return ProductMapDecision::create($wcId, $wcType, $band);
        }

        if ($decision === ProductMapDecision::SKIP) {
            return ProductMapDecision::skip($wcId, $wcType, $band);
        }

        if ($decision !== ProductMapDecision::LINK) {
            return null;
        }

        $fcPostId = absint($extra['fc_post_id'] ?? 0);

        if ($fcPostId <= 0) {
            return null;
        }

        $variantMap = [];

        if (is_array($extra['variant_map'])) {
            foreach ($extra['variant_map'] as $wooVariationId => $fcVariationId) {
                $wooVariationId = absint($wooVariationId);
                $fcVariationId  = absint($fcVariationId);

                if ($wooVariationId > 0 && $fcVariationId > 0) {
                    $variantMap[$wooVariationId] = $fcVariationId;
                }
            }
        }

        $orphans = [];

        if (is_array($extra['orphans'])) {
            foreach ($extra['orphans'] as $orphan) {
                if (!is_array($orphan) || absint($orphan['id'] ?? 0) <= 0) {
                    continue;
                }

                $orphans[] = [
                    'id'   => absint($orphan['id']),
                    'sku'  => sanitize_text_field((string) ($orphan['sku'] ?? '')),
                    'name' => sanitize_text_field((string) ($orphan['name'] ?? '')),
                ];
            }
        }

        return ProductMapDecision::link($wcId, $wcType, $fcPostId, $band, $variantMap, $orphans);
    }

    private function refuse(string $message): WP_REST_Response
    {
        return new WP_REST_Response(['data' => ['message' => $message, 'saved' => false]], 422);
    }

    private function repo(): ProductMapRepository
    {
        return $this->container->get(ProductMapRepository::class);
    }

    /**
     * Attach display labels to the ranked candidate IDs.
     *
     * @param list<array{id: int, score: float}>                                              $ranked
     * @param list<array{id: int, name: string, sku: string, price: float, variation_count: int}> $candidates
     *
     * @return list<array{id: int, label: string, score: float}>
     */
    private function labelCandidates(array $ranked, array $candidates): array
    {
        $byId = [];

        foreach ($candidates as $candidate) {
            $byId[(int) $candidate['id']] = $candidate['name'];
        }

        $out = [];

        foreach ($ranked as $entry) {
            if (!isset($byId[$entry['id']])) {
                continue;
            }

            $out[] = ['id' => $entry['id'], 'label' => $byId[$entry['id']], 'score' => $entry['score']];
        }

        return $out;
    }

    /**
     * One page of in-scope WooCommerce products, shaped for the matcher.
     *
     * @return list<array{id: int, name: string, type: string, order_count: int, match_fields: array{name: string, sku: string, price: float, variation_count: int}, variations: list<array{id: int, sku: string, name: string}>}>
     */
    private function wooProductPage(int $page, int $perPage): array
    {
        global $wpdb;

        $offset = ($page - 1) * $perPage;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish', 'draft', 'private')
             ORDER BY p.ID ASC
             LIMIT %d OFFSET %d",
            $perPage,
            $offset,
        ));

        $rows = [];

        foreach ($ids as $id) {
            $product = function_exists('wc_get_product') ? wc_get_product((int) $id) : null;

            if (!$product instanceof \WC_Product) {
                continue;
            }

            $variations = [];

            if ($product->get_type() === 'variable') {
                foreach ($product->get_children() as $childId) {
                    $child = wc_get_product((int) $childId);

                    if ($child instanceof \WC_Product_Variation) {
                        $variations[] = [
                            'id'   => (int) $childId,
                            'sku'  => (string) $child->get_sku(),
                            'name' => (string) $child->get_name(),
                        ];
                    }
                }
            } else {
                // A simple product is one pseudo-variation keyed by the product
                // ID — the shape ProductMigrator and VariantResolver both expect.
                $variations[] = [
                    'id'   => (int) $product->get_id(),
                    'sku'  => (string) $product->get_sku(),
                    'name' => (string) $product->get_name(),
                ];
            }

            $rows[] = [
                'id'           => (int) $product->get_id(),
                'name'         => (string) $product->get_name(),
                'type'         => (string) $product->get_type(),
                'order_count'  => $this->orderCount((int) $product->get_id()),
                'match_fields' => [
                    'name'            => (string) $product->get_name(),
                    'sku'             => (string) $product->get_sku(),
                    'price'           => (float) $product->get_price(),
                    'variation_count' => count($variations),
                ],
                'variations'   => $variations,
            ];
        }

        return $rows;
    }

    private function wooProductCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'product'
               AND post_status IN ('publish', 'draft', 'private')",
        );
    }

    /**
     * How many WooCommerce order line items reference this product.
     *
     * This is what tells the owner which twelve rows out of three hundred
     * actually matter. Without it every row looks equally important.
     */
    private function orderCount(int $productId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT oi.order_id)
             FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id
             WHERE oim.meta_key = '_product_id' AND oim.meta_value = %d",
            $productId,
        ));
    }

    /**
     * The FluentCart catalogue, shaped for the matcher.
     *
     * @return list<array{id: int, name: string, sku: string, price: float, variation_count: int}>
     */
    private function fcCandidates(): array
    {
        global $wpdb;

        $products = $wpdb->get_results(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'fluent-products'
               AND p.post_status IN ('publish', 'draft', 'private')
             ORDER BY p.ID ASC",
        );

        $out = [];

        foreach ($products ?: [] as $product) {
            $variants = $this->fcVariants((int) $product->ID);

            $out[] = [
                'id'              => (int) $product->ID,
                'name'            => (string) $product->post_title,
                'sku'             => (string) ($variants[0]['sku'] ?? ''),
                'price'           => (float) ($variants[0]['price'] ?? 0.0),
                'variation_count' => count($variants),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: int, sku: string, name: string, price: float}>
     */
    private function fcVariants(int $fcPostId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, variation_title, item_price, sku
             FROM {$wpdb->prefix}fct_product_variations
             WHERE post_id = %d
             ORDER BY id ASC",
            $fcPostId,
        ));

        $out = [];

        foreach ($rows ?: [] as $row) {
            $out[] = [
                'id'    => (int) $row->id,
                'sku'   => (string) ($row->sku ?? ''),
                'name'  => (string) ($row->variation_title ?? ''),
                // FluentCart stores money in the smallest currency unit.
                'price' => ((int) ($row->item_price ?? 0)) / 100,
            ];
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/phpunit --filter 'MappingControllerTest'`
Expected: PASS

- [ ] **Step 5: Register the routes**

Controllers are registered in `app/Modules/Migration/MigrationModule.php:73-85`, as a list of class-name strings inside a `rest_api_init` closure — not in `InfrastructureModule`, which only runs migrations and the logger. Add one line to that list:

```php
add_action('rest_api_init', static function () use ($container): void {
    foreach ([
        'CartShift\\Http\\Controllers\\PreflightController',
        'CartShift\\Http\\Controllers\\PreviewController',
        'CartShift\\Http\\Controllers\\MigrationController',
        'CartShift\\Http\\Controllers\\RollbackController',
        'CartShift\\Http\\Controllers\\FinalizeController',
        'CartShift\\Http\\Controllers\\LogController',
        'CartShift\\Http\\Controllers\\MappingController',
    ] as $class) {
        (new $class($container))->registerRoutes();
    }
});
```

No import is needed — the list holds fully-qualified strings resolved by the autoloader.

- [ ] **Step 6: Register the services and feed the skip list**

In `app/Modules/Migration/MigrationModule.php`, add the imports:

```php
use CartShift\Domain\Migration\MappingPromoter;
use CartShift\Storage\ProductMapRepository;
```

Register the repository alongside the others:

```php
$container->singleton(ProductMapRepository::class, static fn (): ProductMapRepository => new ProductMapRepository());
```

Register the promoter with all four arguments, injecting both FluentCart touchpoints as closures so `MappingPromoter` itself stays testable. Add the import `use FluentCart\App\Models\ProductVariation;` alongside the others:

```php
$container->singleton(MappingPromoter::class, static fn (Container $c): MappingPromoter => new MappingPromoter(
    $c->get(ProductMapRepository::class),
    $c->get(IdMapRepository::class),
    // A trashed or deleted product must not count as present, or promotion
    // writes an ID map row aimed at a post nobody can buy.
    static fn (int $fcPostId): bool => get_post_status($fcPostId) !== false
        && get_post_type($fcPostId) === 'fluent-products',
    static function (int $fcPostId, array $orphan): ?int {
        if (!class_exists(ProductVariation::class)) {
            return null;
        }

        // Deliberately minimal: title, SKU and a zero price. This variant
        // exists so historical line items have something real to point at.
        // The owner prices it themselves — CartShift guessing a price for a
        // variant their catalogue never had would be an opinion, not a
        // migration.
        $variation = ProductVariation::query()->create([
            'post_id'         => $fcPostId,
            'variation_title' => $orphan['name'] !== '' ? $orphan['name'] : 'Migrated variant',
            'sku'             => $orphan['sku'],
            'item_price'      => 0,
            'manage_stock'    => 0,
        ]);

        return $variation && $variation->id ? (int) $variation->id : null;
    },
));
```

If `ProductVariation::query()->create()` rejects any of these columns on the installed FluentCart version, mirror the payload `ProductMigrator` builds at `app/Migrator/ProductMigrator.php:1002` rather than inventing new keys.

Inside the existing `$orchestratorFactory` closure, promote before building the migrators and hand `ProductMigrator` the skip list:

```php
$orchestratorFactory = static function () use ($c): MigrationOrchestrator {
    $state = $c->get(MigrationState::class);
    $idMap = $c->get(IdMapRepository::class);
    $log = $c->get(MigrationLogRepository::class);

    // Promotion is idempotent, so a resumed run re-entering here is harmless.
    // It has to happen before the migrators are built, because ProductMigrator
    // needs the skip list the promotion returns.
    $promotion = $c->get(MappingPromoter::class)->promote((string) $state->getMigrationId());

    foreach ($promotion['dead'] as $deadFcId) {
        $log->write(
            (string) $state->getMigrationId(),
            'product',
            (string) $deadFcId,
            'warning',
            sprintf(
                'Mapped FluentCart product %d no longer exists; the WooCommerce product it was linked to will be created instead.',
                $deadFcId,
            ),
        );
    }

    $productMigrator = new ProductMigrator($idMap, $log, $state);
    $productMigrator->excludeProductIds($promotion['skipped']);

    $migrators = [
        $productMigrator,
        new CustomerMigrator($idMap, $log, $state),
        new CouponMigrator($idMap, $log, $state),
        new OrderMigrator($idMap, $log, $state),
        new SubscriptionMigrator($idMap, $log, $state),
    ];

    return new MigrationOrchestrator($migrators, $state, $idMap, $log);
};
```

Check `MigrationLogRepository`'s write method signature before using it — if it differs from `write($migrationId, $entityType, $wcId, $status, $message)`, adapt the call to the real one rather than changing the repository.

- [ ] **Step 7: Report the FluentCart catalogue size from preflight**

In `app/Http/Controllers/PreflightController.php`, find the method backing `GET /counts` and add one field to its response payload:

```php
'fc_product_count' => (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts}
     WHERE post_type = 'fluent-products'
       AND post_status IN ('publish', 'draft', 'private')",
),
```

This is the single value that lets the Vue app skip the mapping step entirely on a virgin FluentCart install.

- [ ] **Step 8: Run the whole suite**

Run: `./vendor/bin/phpunit`
Expected: PASS. `PreflightControllerTest` asserts on the counts payload — if it asserts an exact array shape, extend it to expect `fc_product_count` rather than loosening the assertion.

- [ ] **Step 9: Stage**

```bash
git add app/Http/Controllers/MappingController.php app/Http/Controllers/PreflightController.php app/Modules/ tests/Unit/Http/Controllers/
```

---

### Task 9: useMapping composable

**Files:**
- Create: `src/composables/useMapping.js`
- Test: `tests/js/useMapping.test.js`

**Interfaces:**
- Consumes: `useApi()` — `api(method, endpoint, body)`.
- Produces: `useMapping()` returning `{ state, loadRows, decide, bulk, clearAll, bandRows, summary }` where `state` is `reactive({ rows: [], loading: false, error: null, runMode: 'create-rest', fcProductCount: 0, total: 0 })`, `runMode` is `'create-rest' | 'only-mapped'`, and `summary` is a computed `{ decided, total, fcProductCount }`.

- [ ] **Step 1: Write the failing test**

Create `tests/js/useMapping.test.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest';

const { apiMock } = vi.hoisted(() => ({ apiMock: vi.fn() }));

vi.mock('@/composables/useApi.js', () => ({ useApi: () => ({ api: apiMock }) }));

const { useMapping } = await import('@/composables/useMapping.js');

describe('useMapping', () => {
  beforeEach(() => {
    apiMock.mockReset();
  });

  function row(overrides = {}) {
    return {
      wc_id: 1,
      name: 'Blue Hoodie',
      wc_type: 'simple',
      sku: '',
      variations: 1,
      order_count: 12,
      band: 'likely',
      suggested: 900,
      candidates: [{ id: 900, label: 'Blue Hoodie', score: 1.0 }],
      variant: { matched: 1, total: 1, adds: 0, map: { 1: 501 } },
      decision: null,
      ...overrides,
    };
  }

  it('loads rows and records the catalogue size', async () => {
    apiMock.mockResolvedValue({ rows: [row()], total: 1, fc_product_count: 28 });

    const { state, loadRows } = useMapping();
    await loadRows();

    expect(apiMock).toHaveBeenCalledWith('GET', 'mapping/rows?page=1&per_page=50');
    expect(state.rows).toHaveLength(1);
    expect(state.fcProductCount).toBe(28);
  });

  it('groups rows by band', async () => {
    apiMock.mockResolvedValue({
      rows: [
        row({ wc_id: 1, band: 'strong' }),
        row({ wc_id: 2, band: 'likely' }),
        row({ wc_id: 3, band: 'strong' }),
        row({ wc_id: 4, band: 'none', suggested: null, candidates: [] }),
      ],
      total: 4,
      fc_product_count: 5,
    });

    const { loadRows, bandRows } = useMapping();
    await loadRows();

    expect(bandRows('strong').map((r) => r.wc_id)).toEqual([1, 3]);
    expect(bandRows('likely').map((r) => r.wc_id)).toEqual([2]);
    expect(bandRows('none').map((r) => r.wc_id)).toEqual([4]);
  });

  it('sends the variant map with a link decision', async () => {
    apiMock.mockResolvedValue({ rows: [row()], total: 1, fc_product_count: 1 });

    const { state, loadRows, decide } = useMapping();
    await loadRows();

    apiMock.mockResolvedValue({ saved: true, decision: { decision: 'link', fc_post_id: 900 } });

    await decide(state.rows[0], 'link');

    expect(apiMock).toHaveBeenCalledWith('POST', 'mapping/decide', {
      wc_id: 1,
      wc_type: 'simple',
      decision: 'link',
      band: 'likely',
      fc_post_id: 900,
      variant_map: { 1: 501 },
      orphans: [],
    });
  });

  it('carries orphan variations back so promotion can create them', async () => {
    apiMock.mockResolvedValue({
      rows: [
        row({
          variant: {
            matched: 2,
            total: 3,
            adds: 1,
            map: { 11: 501, 12: 502 },
            orphans: [{ id: 13, sku: 'TS-XL', name: 'XL' }],
          },
        }),
      ],
      total: 1,
      fc_product_count: 1,
    });

    const { state, loadRows, decide } = useMapping();
    await loadRows();

    apiMock.mockResolvedValue({ saved: true, decision: { decision: 'link' } });

    await decide(state.rows[0], 'link');

    // Dropping these is how "adds XL" becomes an order line pointing at nothing.
    expect(apiMock.mock.calls.at(-1)[2].orphans).toEqual([{ id: 13, sku: 'TS-XL', name: 'XL' }]);
  });

  it('applies a bulk action to one band only', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 1, band: 'strong' }), row({ wc_id: 2, band: 'likely' })],
      total: 2,
      fc_product_count: 2,
    });

    const { loadRows, bulk } = useMapping();
    await loadRows();

    apiMock.mockResolvedValue({ saved: 1 });

    await bulk('strong', 'link');

    expect(apiMock).toHaveBeenCalledWith('POST', 'mapping/bulk', {
      band: 'strong',
      decision: 'link',
      rows: [{ wc_id: 1, wc_type: 'simple', fc_post_id: 900, variant_map: { 1: 501 }, orphans: [] }],
    });
  });

  it('a per-row decision survives a later bulk action on its band', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 1, band: 'likely' }), row({ wc_id: 2, band: 'likely' })],
      total: 2,
      fc_product_count: 2,
    });

    const { state, loadRows, decide, bulk } = useMapping();
    await loadRows();

    apiMock.mockResolvedValue({ saved: true, decision: { decision: 'skip', fc_post_id: null } });
    await decide(state.rows[0], 'skip');

    apiMock.mockResolvedValue({ saved: 1 });
    await bulk('likely', 'link');

    const bulkCall = apiMock.mock.calls.at(-1);

    expect(bulkCall[2].rows.map((r) => r.wc_id)).toEqual([2]);
    expect(state.rows[0].decision.decision).toBe('skip');
  });

  it('bulk link omits rows with no candidate', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 1, band: 'none', suggested: null, candidates: [], variant: null })],
      total: 1,
      fc_product_count: 0,
    });

    const { loadRows, bulk } = useMapping();
    await loadRows();

    apiMock.mockResolvedValue({ saved: 0 });

    await bulk('none', 'link');

    expect(apiMock.mock.calls.at(-1)[2].rows).toEqual([]);
  });

  it('counts decided rows for the summary', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 1 }), row({ wc_id: 2, decision: { decision: 'create' } })],
      total: 2,
      fc_product_count: 3,
    });

    const { loadRows, summary } = useMapping();
    await loadRows();

    expect(summary.value.decided).toBe(1);
    expect(summary.value.total).toBe(2);
  });

  it('surfaces a failed load rather than silently showing nothing', async () => {
    apiMock.mockRejectedValue(new Error('boom'));

    const { state, loadRows } = useMapping();
    await loadRows();

    expect(state.error).toBe('boom');
    expect(state.loading).toBe(false);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm test -- useMapping`
Expected: FAIL — cannot resolve `@/composables/useMapping.js`.

- [ ] **Step 3: Write the composable**

Create `src/composables/useMapping.js`:

```js
import { reactive, computed } from 'vue';
import { useApi } from '@/composables/useApi.js';

export const BANDS = ['strong', 'likely', 'weak', 'none'];

/**
 * Mapping state for the map screen.
 *
 * A fresh reactive per call rather than a module singleton like useMigration:
 * the mapping screen is entered once per run and holds a page of rows, and a
 * singleton would carry a previous run's decisions into the next one's UI.
 */
export function useMapping() {
  const { api } = useApi();

  const state = reactive({
    rows: [],
    loading: false,
    error: null,
    total: 0,
    fcProductCount: 0,
    page: 1,
    perPage: 50,
    // 'create-rest' migrates untouched products as usual; 'only-mapped' turns
    // the linked set into a whitelist via MigrationScope's explicit mode.
    runMode: 'create-rest',
  });

  async function loadRows(page = 1) {
    state.loading = true;
    state.error = null;

    try {
      const data = await api('GET', `mapping/rows?page=${page}&per_page=${state.perPage}`);

      state.rows = data.rows || [];
      state.total = data.total || 0;
      state.fcProductCount = data.fc_product_count || 0;
      state.page = page;
    } catch (err) {
      state.error = err.message;
    } finally {
      state.loading = false;
    }
  }

  function bandRows(band) {
    return state.rows.filter((row) => row.band === band);
  }

  /**
   * The payload fragment a link needs. Null when the row has nothing to link
   * to, which is how bulk() drops candidate-less rows without special-casing.
   */
  function linkPayload(row) {
    if (!row.suggested) {
      return null;
    }

    return {
      fc_post_id: row.suggested,
      variant_map: row.variant ? row.variant.map : {},
      // Carried back so promotion can create the variants this link is missing.
      // Dropping them here is how "adds XL" becomes a line item pointing at
      // nothing three screens later.
      orphans: row.variant ? row.variant.orphans || [] : [],
    };
  }

  async function decide(row, decision) {
    const body = {
      wc_id: row.wc_id,
      wc_type: row.wc_type,
      decision,
      band: row.band,
    };

    if (decision === 'link') {
      const link = linkPayload(row);

      if (!link) {
        return;
      }

      Object.assign(body, link);
    }

    try {
      const data = await api('POST', 'mapping/decide', body);
      row.decision = data.decision;
    } catch (err) {
      state.error = err.message;
    }
  }

  /**
   * Apply one decision to every undecided row in a band.
   *
   * Undecided only: a row the owner already ruled on individually is theirs,
   * and a later bulk press on its band must not overwrite it.
   */
  async function bulk(band, decision) {
    const targets = bandRows(band).filter((row) => !row.decision);

    const rows = [];

    targets.forEach((row) => {
      const payload = { wc_id: row.wc_id, wc_type: row.wc_type };

      if (decision === 'link') {
        const link = linkPayload(row);

        if (!link) {
          return;
        }

        Object.assign(payload, link);
      }

      rows.push(payload);
    });

    try {
      await api('POST', 'mapping/bulk', { band, decision, rows });

      const applied = new Set(rows.map((r) => r.wc_id));

      state.rows.forEach((row) => {
        if (applied.has(row.wc_id)) {
          row.decision = { decision, fc_post_id: row.suggested || null };
        }
      });
    } catch (err) {
      state.error = err.message;
    }
  }

  async function clearAll() {
    try {
      await api('POST', 'mapping/clear', {});
      state.rows.forEach((row) => {
        row.decision = null;
      });
    } catch (err) {
      state.error = err.message;
    }
  }

  const summary = computed(() => ({
    decided: state.rows.filter((row) => row.decision).length,
    total: state.total,
    fcProductCount: state.fcProductCount,
  }));

  return { state, loadRows, decide, bulk, clearAll, bandRows, summary, BANDS };
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm test -- useMapping`
Expected: PASS

- [ ] **Step 5: Stage**

```bash
git add src/composables/useMapping.js tests/js/useMapping.test.js
```

---

### Task 10: MapRow, MapScreen and the wizard step

**Files:**
- Create: `src/components/MapRow.vue`
- Create: `src/components/MapScreen.vue`
- Modify: `src/App.vue`
- Test: `tests/js/mapScreen.test.js`

**Interfaces:**
- Consumes: `useMapping()` (Task 9), `PageHeader.vue`, and the shared wizard state via `inject('migration')` — the object `App.vue:24` provides, never a second `useMigration()` call.
- Produces: the `map` screen, reachable as `state.screen === 'map'`.

- [ ] **Step 1: Write the failing test**

Create `tests/js/mapScreen.test.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

const { apiMock } = vi.hoisted(() => ({ apiMock: vi.fn() }));

vi.mock('@/composables/useApi.js', () => ({ useApi: () => ({ api: apiMock }) }));

const { default: MapRow } = await import('@/components/MapRow.vue');
const { default: MapScreen } = await import('@/components/MapScreen.vue');

function row(overrides = {}) {
  return {
    wc_id: 1,
    name: 'Blue Hoodie',
    wc_type: 'variable',
    sku: 'HOOD-1',
    variations: 3,
    order_count: 88,
    band: 'likely',
    suggested: 900,
    candidates: [
      { id: 900, label: 'Blue Hoodie', score: 1.2 },
      { id: 901, label: 'Red Hoodie', score: 0.6 },
    ],
    variant: { matched: 2, total: 3, adds: 1, map: { 11: 501, 12: 502 } },
    decision: null,
    ...overrides,
  };
}

describe('MapRow', () => {
  it('shows the order count, because that is what says which rows matter', () => {
    const wrapper = mount(MapRow, { props: { row: row() } });

    expect(wrapper.text()).toContain('88');
  });

  it('warns before adding a variant to a hand-made product', () => {
    const wrapper = mount(MapRow, { props: { row: row() } });

    expect(wrapper.text()).toContain('2/3');
    expect(wrapper.text()).toContain('adds 1');
  });

  it('offers no link button when there is no candidate', () => {
    const wrapper = mount(MapRow, {
      props: { row: row({ band: 'none', suggested: null, candidates: [], variant: null }) },
    });

    expect(wrapper.find('[data-action="link"]').exists()).toBe(false);
    expect(wrapper.find('[data-action="create"]').exists()).toBe(true);
    expect(wrapper.find('[data-action="skip"]').exists()).toBe(true);
  });

  it('emits the chosen decision', async () => {
    const wrapper = mount(MapRow, { props: { row: row() } });

    await wrapper.find('[data-action="skip"]').trigger('click');

    expect(wrapper.emitted('decide')[0]).toEqual(['skip']);
  });

  it('changing the candidate select updates the row suggestion', async () => {
    const r = row();
    const wrapper = mount(MapRow, { props: { row: r } });

    await wrapper.find('select').setValue('901');

    expect(wrapper.emitted('suggest')[0]).toEqual([901]);
  });
});

describe('MapScreen', () => {
  beforeEach(() => {
    apiMock.mockReset();
  });

  // App.vue provides the shared wizard state; MapScreen injects it rather than
  // calling useMigration() again, so the test has to supply it.
  function mountScreen() {
    return mount(MapScreen, {
      global: {
        provide: {
          migration: {
            state: { screen: 'map' },
            actions: { startMigration: vi.fn(), goToScreen: vi.fn() },
          },
        },
      },
    });
  }

  it('renders a band header with a bulk button per populated band', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 1, band: 'strong' }), row({ wc_id: 2, band: 'none', suggested: null, candidates: [], variant: null })],
      total: 2,
      fc_product_count: 4,
    });

    const wrapper = mountScreen();
    await nextTick();
    await nextTick();

    expect(wrapper.find('[data-band="strong"]').exists()).toBe(true);
    expect(wrapper.find('[data-band="none"]').exists()).toBe(true);
    expect(wrapper.find('[data-band="likely"]').exists()).toBe(false);
  });

  it('does not offer a bulk link on the no-candidate band', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 2, band: 'none', suggested: null, candidates: [], variant: null })],
      total: 1,
      fc_product_count: 0,
    });

    const wrapper = mountScreen();
    await nextTick();
    await nextTick();

    const band = wrapper.find('[data-band="none"]');

    expect(band.find('[data-bulk="link"]').exists()).toBe(false);
    expect(band.find('[data-bulk="create"]').exists()).toBe(true);
    expect(band.find('[data-bulk="skip"]').exists()).toBe(true);
  });

  it('surfaces a load error', async () => {
    apiMock.mockRejectedValue(new Error('nope'));

    const wrapper = mountScreen();
    await nextTick();
    await nextTick();

    expect(wrapper.text()).toContain('nope');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm test -- mapScreen`
Expected: FAIL — cannot resolve `@/components/MapRow.vue`.

- [ ] **Step 3: Write MapRow.vue**

Create `src/components/MapRow.vue`:

```vue
<template>
  <tr class="cartshift-map-row" :class="{ 'is-decided': !!row.decision }">
    <td class="cartshift-map-woo">
      <strong>{{ row.name }}</strong>
      <span class="description">
        {{ row.wc_type }} &middot; {{ row.variations }} variation(s) &middot; {{ row.order_count }} orders
      </span>
    </td>

    <td class="cartshift-map-fc">
      <select
        v-if="row.candidates.length"
        :value="row.suggested"
        @change="$emit('suggest', Number($event.target.value))"
      >
        <option v-for="candidate in row.candidates" :key="candidate.id" :value="candidate.id">
          {{ candidate.label }}
        </option>
      </select>
      <span v-else class="description">Will be created</span>

      <!-- The one place CartShift writes into a product the owner built by
           hand, so it is said out loud before they press anything. -->
      <span v-if="row.variant" class="description cartshift-map-variants">
        {{ row.variant.matched }}/{{ row.variant.total }} variants matched<template
          v-if="row.variant.adds"
        >
          &middot; adds {{ row.variant.adds }}</template
        >
      </span>
    </td>

    <td class="cartshift-map-actions">
      <span v-if="row.decision" class="cartshift-map-decided">{{ row.decision.decision }}</span>
      <template v-else>
        <button v-if="row.suggested" type="button" class="button" data-action="link" @click="$emit('decide', 'link')">
          Link
        </button>
        <button type="button" class="button" data-action="create" @click="$emit('decide', 'create')">
          Create
        </button>
        <button type="button" class="button" data-action="skip" @click="$emit('decide', 'skip')">
          Skip
        </button>
      </template>
    </td>
  </tr>
</template>

<script setup>
defineProps({
  row: { type: Object, required: true },
});

defineEmits(['decide', 'suggest']);
</script>
```

- [ ] **Step 4: Write MapScreen.vue**

Create `src/components/MapScreen.vue`:

```vue
<template>
  <div>
    <PageHeader title="Map Products to FluentCart" />
    <p>
      Link each WooCommerce product to the FluentCart product you already built, or let CartShift
      create it. Nothing is written until you continue.
    </p>

    <div v-if="state.error" class="notice notice-error inline" role="alert">
      <p>{{ state.error }}</p>
    </div>

    <div class="cartshift-map-summary">
      <span><strong>{{ summary.total }}</strong> Woo products</span>
      <span><strong>{{ summary.decided }}</strong> decided</span>
      <span><strong>{{ summary.fcProductCount }}</strong> in FluentCart</span>
    </div>

    <fieldset class="cartshift-map-mode">
      <legend>What happens to products you do not touch?</legend>
      <label>
        <input type="radio" value="create-rest" v-model="state.runMode" />
        Create them in FluentCart, as usual
      </label>
      <label>
        <input type="radio" value="only-mapped" v-model="state.runMode" />
        Migrate only what I mapped
      </label>
    </fieldset>

    <div v-for="band in BANDS" :key="band">
      <template v-if="bandRows(band).length">
        <div class="cartshift-map-band" :data-band="band">
          <strong>{{ bandLabel(band) }} &middot; {{ bandRows(band).length }}</strong>
          <span class="description">{{ bandHint(band) }}</span>

          <button
            v-if="band !== 'none'"
            type="button"
            class="button"
            data-bulk="link"
            @click="bulk(band, 'link')"
          >
            Link all {{ bandRows(band).length }}
          </button>
          <button type="button" class="button" data-bulk="create" @click="bulk(band, 'create')">
            Create all
          </button>
          <button type="button" class="button" data-bulk="skip" @click="bulk(band, 'skip')">
            Skip all
          </button>
        </div>

        <table class="widefat striped cartshift-table-map">
          <tbody>
            <MapRow
              v-for="row in bandRows(band)"
              :key="row.wc_id"
              :row="row"
              @decide="(decision) => decide(row, decision)"
              @suggest="(id) => (row.suggested = id)"
            />
          </tbody>
        </table>
      </template>
    </div>

    <p v-if="state.loading" class="description">Loading products…</p>
  </div>
</template>

<script setup>
import { inject, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import MapRow from '@/components/MapRow.vue';
import { useMapping } from '@/composables/useMapping.js';

// The wizard's shared state comes from App.vue's provide, never from calling
// useMigration() again — that would hand this screen a private copy.
const { actions } = inject('migration');

// useMapping, by contrast, IS per-screen: its rows belong to this visit.
const { state, loadRows, decide, bulk, bandRows, summary, BANDS } = useMapping();

const LABELS = {
  strong: 'Strong',
  likely: 'Likely',
  weak: 'Weak',
  none: 'No candidate',
};

const HINTS = {
  strong: 'same SKU, or same name and price',
  likely: 'similar name only',
  weak: 'loose match — check these',
  none: 'nothing in FluentCart looks like this',
};

function bandLabel(band) {
  return LABELS[band];
}

function bandHint(band) {
  return HINTS[band];
}

onMounted(() => loadRows());
</script>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `npm test -- mapScreen`
Expected: PASS

- [ ] **Step 6: Wire the screen into the wizard**

In `src/App.vue`, add the screen between select and progress:

```vue
<PreflightScreen v-if="state.screen === 'preflight'" />
<SelectScreen v-else-if="state.screen === 'select'" />
<MapScreen v-else-if="state.screen === 'map'" />
<ProgressScreen v-else-if="state.screen === 'progress'" />
<ResultsScreen v-else-if="state.screen === 'results'" />
```

Import it alongside the others: `import MapScreen from '@/components/MapScreen.vue';`

In `src/composables/useMigration.js`, update the `screen` comment on line 109 to list the new value, and — at the point where `SelectScreen` moves the wizard on — route through `map` only when FluentCart already has products:

```js
// A virgin FluentCart install has nothing to map to, so the step would be a
// screen of 300 rows all saying "will be created". Skip it entirely.
function advanceFromSelect() {
  if ((state.counts?.fc_product_count || 0) > 0) {
    state.screen = 'map';
    return;
  }

  startMigration();
}
```

Export `advanceFromSelect` from `useMigration()` alongside `goToScreen`, then change the primary button at `src/components/SelectScreen.vue:133` from `@click="actions.startMigration()"` to `@click="actions.advanceFromSelect()"`. Leave the Back button at :137 alone.

`MapScreen` needs its own Continue, which calls `startMigration()` directly. Add to `MapScreen.vue`'s template, after the band loop:

```vue
<p class="cartshift-map-continue">
  <button class="button button-primary button-hero" @click="actions.startMigration()">
    Continue
  </button>
  <button class="button" @click="actions.goToScreen('select')">Back</button>
</p>
```

and to its script `const { actions } = inject('migration');` — **not** `useMigration()`. `useMigration()` builds a fresh reactive state on every call; `App.vue:21-24` calls it once and provides the result, and every screen injects it (`SelectScreen.vue:163`). Calling the composable again in `MapScreen` would give it a private `state.screen` that nothing else can see, and the Continue button would appear to do nothing.

- [ ] **Step 7: Run every JS test**

Run: `npm test`
Expected: PASS. `selectScreen.test.js` asserts on what the select screen's primary button does — if it asserts `startMigration` was called, update it to expect `advanceFromSelect`.

- [ ] **Step 8: Build the admin bundle**

Run: `npm run build`
Expected: builds without error, refreshing `resources/admin/dist/`.

- [ ] **Step 9: Run the full PHP suite one last time**

Run: `./vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 10: Stage**

```bash
git add src/ tests/js/ resources/admin/dist/
```

---

## Verification

After Task 10, the following must all hold. Check them before declaring the feature done.

- [ ] `./vendor/bin/phpunit` — full suite green
- [ ] `npm test` — full suite green
- [ ] `npm run build` — clean build
- [ ] `npx biome check src/` — clean (Biome, not ESLint)
- [ ] Manual: with an empty FluentCart catalogue, Select goes straight to Progress — the map step never appears
- [ ] Manual: with FluentCart products present, map a Woo product to one, run the migration, and confirm the resulting FluentCart order's line item points at the pre-existing product, not a duplicate
- [ ] Manual: roll that migration back and confirm the pre-existing FluentCart product still exists with its original variants
