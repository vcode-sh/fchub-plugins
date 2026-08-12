<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Runtime;

use CartShift\Domain\Transfer\Runtime\TransferRuntimeProbe;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeSymbols;
use CartShift\Domain\Transfer\Runtime\TransferSchemaInspector;
use CartShift\Domain\Transfer\Runtime\TransferTopology;
use PHPUnit\Framework\TestCase;

final class TransferRuntimeProbeTest extends TestCase
{
    public function testSourceRuntimeRejectsMissingWooCrudApis(): void
    {
        $symbols = new FakeTransferRuntimeSymbols(functions: []);
        $report = (new TransferRuntimeProbe($symbols, new FakeSchemaInspector()))->inspect('source');

        self::assertFalse($report->isReady());
        self::assertContains('source_woocommerce_api_missing', $report->errors);
    }

    public function testTargetFingerprintChangesWhenRequiredSchemaChanges(): void
    {
        $left = $this->probeWithOrderRateType('decimal(12,4)')->inspect('target');
        $right = $this->probeWithOrderRateType('bigint(20)')->inspect('target');

        self::assertNotSame($left->fingerprint, $right->fingerprint);
    }

    public function testUnknownRoleIsRejectedBeforeAnySchemaRead(): void
    {
        $schema = new FakeSchemaInspector();

        try {
            (new TransferRuntimeProbe(new FakeTransferRuntimeSymbols(), $schema))->inspect('both');
            self::fail('Expected an invalid role to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $schema->inspectionCount);
        }
    }

    public function testTargetRejectsNarrowSkuAndVariationIdentifierColumns(): void
    {
        $schema = FakeSchemaInspector::targetBaseline()
            ->withColumnType('fct_product_variations', 'sku', 'varchar(29)')
            ->withColumnType('fct_product_variations', 'variation_identifier', 'varchar(99)');

        $report = (new TransferRuntimeProbe(new FakeTransferRuntimeSymbols(), $schema))->inspect('target');

        self::assertContains('target_schema_unrepresentable', $report->errors);
    }

    public function testTargetFingerprintIsIndependentOfSchemaEnumerationOrder(): void
    {
        $left = FakeSchemaInspector::targetBaseline();
        $right = FakeSchemaInspector::targetBaseline()->reversed();

        $first = (new TransferRuntimeProbe(new FakeTransferRuntimeSymbols(), $left))->inspect('target');
        $second = (new TransferRuntimeProbe(new FakeTransferRuntimeSymbols(), $right))->inspect('target');

        self::assertSame($first->fingerprint, $second->fingerprint);
    }

    public function testInstalledAttributeRelationShapeIsAccepted(): void
    {
        $schema = new PermissiveTargetSchemaInspector([
            'fct_atts_relations' => [
                'id',
                'group_id',
                'term_id',
                'object_id',
                'created_at',
                'updated_at',
            ],
        ]);

        $report = (new TransferRuntimeProbe(new FakeTransferRuntimeSymbols(), $schema))->inspect('target');

        self::assertNotContains('target_schema_missing', $report->errors);
    }

    public function testSourceFingerprintChangesWhenLoadedWcsBuildChanges(): void
    {
        $left = new FakeTransferRuntimeSymbols(digests: ['wcs' => str_repeat('a', 64)]);
        $right = new FakeTransferRuntimeSymbols(digests: ['wcs' => str_repeat('b', 64)]);

        $first = (new TransferRuntimeProbe($left, new FakeSchemaInspector()))->inspect('source');
        $second = (new TransferRuntimeProbe($right, new FakeSchemaInspector()))->inspect('source');

        self::assertNotSame($first->fingerprint, $second->fingerprint);
    }

    // ──────────────────────────────────────────────
    // Which topology this runtime is
    // ──────────────────────────────────────────────

    /**
     * The classification exists today and decides nothing. These are the tests
     * that make it decide, because the shop with WooCommerce and FluentCart in
     * one install is the common case and it currently has no route at all.
     */
    public function testOneInstallHoldingBothPluginsIsSameSite(): void
    {
        $probe = new TransferRuntimeProbe(new FakeTransferRuntimeSymbols(), new FakeSchemaInspector());

        self::assertSame(TransferTopology::SameSite, $probe->topology());
    }

    public function testARuntimeWithoutWooCommerceIsCrossRuntime(): void
    {
        // A FluentCart-only target. It can receive a package; it cannot read a
        // shop, because there is no shop here to read.
        $symbols = new FakeTransferRuntimeSymbols(functions: []);

        self::assertSame(
            TransferTopology::CrossRuntime,
            (new TransferRuntimeProbe($symbols, new FakeSchemaInspector()))->topology(),
        );
    }

    public function testARuntimeWithoutFluentCartIsCrossRuntime(): void
    {
        // A WooCommerce-only source. It can export; it has nothing to write into.
        $symbols = new FakeTransferRuntimeSymbols(absentClasses: ['FluentCart\\App\\Models\\Order']);

        self::assertSame(
            TransferTopology::CrossRuntime,
            (new TransferRuntimeProbe($symbols, new FakeSchemaInspector()))->topology(),
        );
    }

    /**
     * `runtime_fingerprint` is bound into cutover approvals — an operator reads
     * it, passes it to `--cutover-approval`, and a fingerprint that has moved
     * since invalidates the approval. Folding topology into it would invalidate
     * every approval in flight to report something that is not a compatibility
     * fact about a role. So the classification travels beside the report.
     */
    public function testClassifyingTheTopologyDoesNotDisturbTheRuntimeFingerprint(): void
    {
        $probe = new TransferRuntimeProbe(new FakeTransferRuntimeSymbols(), FakeSchemaInspector::targetBaseline());

        $before = $probe->inspect('target')->fingerprint;
        $probe->topology();

        self::assertSame($before, $probe->inspect('target')->fingerprint);
    }

    public function testTopologyIsAnsweredWithoutReadingASingleSchema(): void
    {
        // The guided screen asks this on every page load. A classification that
        // walked twenty-six tables to answer "are both plugins here" would put
        // a schema read behind a question `class_exists()` settles.
        $schema = new FakeSchemaInspector();

        (new TransferRuntimeProbe(new FakeTransferRuntimeSymbols(), $schema))->topology();

        self::assertSame(0, $schema->inspectionCount);
    }

    private function probeWithOrderRateType(string $type): TransferRuntimeProbe
    {
        $schema = FakeSchemaInspector::targetBaseline()
            ->withColumnType('fct_orders', 'rate', $type);

        return new TransferRuntimeProbe(new FakeTransferRuntimeSymbols(), $schema);
    }
}

final class FakeTransferRuntimeSymbols implements TransferRuntimeSymbols
{
    /**
     * @param list<string>|null $functions
     * @param list<string> $absentClasses Classes this runtime has NOT loaded.
     */
    public function __construct(
        private readonly ?array $functions = null,
        private readonly array $digests = [],
        private readonly array $absentClasses = [],
    ) {
    }

    public function functionExists(string $function): bool
    {
        return $this->functions === null || in_array($function, $this->functions, true);
    }

    public function classExists(string $class): bool
    {
        return !in_array($class, $this->absentClasses, true);
    }

    public function methodExists(string $class, string $method): bool
    {
        return true;
    }

    public function constantValue(string $constant): ?string
    {
        return match ($constant) {
            'WC_VERSION' => '11.0.0',
            'FLUENTCART_VERSION' => '1.6.0',
            'FluentCart\\App\\Helpers\\Helper::PRODUCT_TYPE_SIMPLE' => 'simple',
            'FluentCart\\App\\Helpers\\Helper::PRODUCT_TYPE_SIMPLE_VARIATION' => 'simple_variations',
            'FluentCart\\App\\Helpers\\Helper::PRODUCT_TYPE_ADVANCE_VARIATION' => 'advanced_variations',
            default => null,
        };
    }

    public function runtimeVersion(string $component): ?string
    {
        return match ($component) {
            'php' => PHP_VERSION,
            'wordpress' => '7.0.3',
            'woocommerce' => '11.0.0',
            'wcs' => '8.7.1',
            'fluentcart' => '1.6.0',
            'cartshift' => '1.5.0',
            'cartshift_db' => '7',
            default => null,
        };
    }

    public function runtimeDigest(string $component): ?string
    {
        return $this->digests[$component] ?? null;
    }

    public function modelFillable(string $class): array
    {
        return [];
    }

    public function modelCasts(string $class): array
    {
        return [];
    }
}

final class FakeSchemaInspector implements TransferSchemaInspector
{
    public int $inspectionCount = 0;

    /** @param array<string, array<string, mixed>> $tables */
    public function __construct(private array $tables = [])
    {
    }

    public static function targetBaseline(): self
    {
        return new self([
            'fct_orders' => self::table([
                'rate' => 'decimal(12,4)',
                'mode' => "enum('live','test')",
                'tax_behavior' => 'tinyint(1)',
            ]),
            'fct_order_items' => self::table(['rate' => 'bigint(20)']),
            'fct_order_transactions' => self::table(['rate' => 'bigint(20)']),
            'fct_product_variations' => self::table([
                'sku' => 'varchar(30)',
                'variation_identifier' => 'varchar(100)',
            ]),
        ]);
    }

    public function withColumnType(string $table, string $column, string $type): self
    {
        $clone = clone $this;
        $clone->tables[$table]['columns'][$column]['type'] = $type;

        return $clone;
    }

    public function reversed(): self
    {
        $clone = clone $this;
        $clone->tables = array_reverse($clone->tables, true);

        foreach ($clone->tables as &$table) {
            $table['columns'] = array_reverse($table['columns'], true);
        }

        return $clone;
    }

    public function inspect(array $tables): array
    {
        $this->inspectionCount++;

        return array_intersect_key($this->tables, array_flip($tables));
    }

    /** @param array<string, string> $columns */
    private static function table(array $columns): array
    {
        return [
            'engine' => 'InnoDB',
            'columns' => array_map(
                static fn(string $type): array => [
                    'type' => $type,
                    'nullable' => true,
                    'default' => null,
                    'extra' => '',
                ],
                $columns,
            ),
            'indexes' => [],
        ];
    }
}

final class PermissiveTargetSchemaInspector implements TransferSchemaInspector
{
    /** @param array<string, list<string>> $strictColumns */
    public function __construct(private readonly array $strictColumns)
    {
    }

    public function inspect(array $tables): array
    {
        $result = [];

        foreach ($tables as $table) {
            $columns = isset($this->strictColumns[$table])
                ? array_fill_keys($this->strictColumns[$table], ['type' => 'bigint(20)'])
                : new AlwaysPresentColumns($table);

            $result[$table] = [
                'engine' => 'InnoDB',
                'columns' => $columns,
                'indexes' => [],
            ];
        }

        return $result;
    }
}

final class AlwaysPresentColumns implements \ArrayAccess
{
    public function __construct(private readonly string $table)
    {
    }

    public function offsetExists(mixed $offset): bool
    {
        return true;
    }

    public function offsetGet(mixed $offset): array
    {
        $type = match ([$this->table, $offset]) {
            ['fct_product_variations', 'sku'] => 'varchar(30)',
            ['fct_product_variations', 'variation_identifier'] => 'varchar(100)',
            ['fct_orders', 'rate'] => 'decimal(12,4)',
            ['fct_orders', 'mode'] => "enum('live','test')",
            ['fct_orders', 'tax_behavior'] => 'tinyint(1)',
            ['fct_order_items', 'rate'], ['fct_order_transactions', 'rate'] => 'bigint(20)',
            default => 'varchar(255)',
        };

        return ['type' => $type];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Immutable test schema.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Immutable test schema.');
    }
}
