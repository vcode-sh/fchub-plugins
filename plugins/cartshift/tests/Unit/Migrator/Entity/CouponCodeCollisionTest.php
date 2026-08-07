<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Migrator\CouponMigrator;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';

/**
 * fct_coupons.code is VARCHAR(50) NOT NULL UNIQUE, so a collision throws on
 * insert and used to land in the migration log as a hard error.
 */
final class CouponCodeCollisionTest extends PluginTestCase
{
    public function testMaxCodeLengthMatchesTheFluentCartColumn(): void
    {
        $reflection = new \ReflectionClass(CouponMigrator::class);

        $this->assertSame(
            50,
            $reflection->getConstant('MAX_CODE_LENGTH'),
            'fct_coupons.code is VARCHAR(50)',
        );
    }

    public function testNormalizeCodeTrimsAndCollapsesWhitespace(): void
    {
        $this->assertSame('SAVE10', $this->normalizeCode('  SAVE10  '));
        $this->assertSame('SAVE 10', $this->normalizeCode("SAVE\t\n 10"));
        $this->assertSame('', $this->normalizeCode("  \n "));
    }

    public function testCollationKeyTreatsCaseVariantsAsTheSameCode(): void
    {
        $this->assertSame(
            $this->collationKey('save10'),
            $this->collationKey('SAVE10'),
            "MySQL's default *_ci collation makes these the same row",
        );
        $this->assertNotSame($this->collationKey('save10'), $this->collationKey('save11'));
    }

    public function testCodeLengthIsMultibyteAware(): void
    {
        $this->assertSame(6, $this->codeLength('SAVE10'));
        $this->assertSame(4, $this->codeLength('ŻÓŁW'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('duplicateKeyErrors')]
    public function testDuplicateKeyDetection(\Throwable $error, bool $expected): void
    {
        $method = new \ReflectionMethod(CouponMigrator::class, 'isDuplicateKeyError');

        $this->assertSame($expected, $method->invoke(null, $error));
    }

    /**
     * @return array<string, array{\Throwable, bool}>
     */
    public static function duplicateKeyErrors(): array
    {
        return [
            'mysql duplicate entry' => [
                new \RuntimeException("Duplicate entry 'SAVE10' for key 'code'"),
                true,
            ],
            'pdo integrity violation' => [
                new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062'),
                true,
            ],
            'sqlite unique constraint' => [
                new \RuntimeException('UNIQUE constraint failed: fct_coupons.code'),
                true,
            ],
            'numeric mysql code' => [
                new \RuntimeException('some driver wording', 1062),
                true,
            ],
            'unrelated failure must still surface' => [
                new \RuntimeException('Table fct_coupons does not exist'),
                false,
            ],
        ];
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function normalizeCode(string $code): string
    {
        return (string) (new \ReflectionMethod(CouponMigrator::class, 'normalizeCode'))
            ->invoke(null, $code);
    }

    private function collationKey(string $code): string
    {
        return (string) (new \ReflectionMethod(CouponMigrator::class, 'collationKey'))
            ->invoke(null, $code);
    }

    private function codeLength(string $code): int
    {
        return (int) (new \ReflectionMethod(CouponMigrator::class, 'codeLength'))
            ->invoke(null, $code);
    }
}
