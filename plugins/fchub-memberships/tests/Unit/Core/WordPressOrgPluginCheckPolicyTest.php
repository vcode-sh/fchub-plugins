<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Core;

use FChubMemberships\Tests\Unit\PluginTestCase;

final class WordPressOrgPluginCheckPolicyTest extends PluginTestCase
{
    /**
     * @return list<string>
     */
    private function productionPhpFiles(): array
    {
        $pluginRoot = dirname(__DIR__, 3);
        $files = [
            $pluginRoot . '/fchub-memberships.php',
            $pluginRoot . '/uninstall.php',
        ];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pluginRoot . '/app', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $path) {
            if ($path->isFile() && $path->getExtension() === 'php') {
                $files[] = $path->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function test_custom_table_queries_have_line_local_auditable_annotations(): void
    {
        $failures = [];

        foreach ($this->productionPhpFiles() as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('phpcs:disable', $source, $file);
            self::assertStringNotContainsString('phpcs:enable', $source, $file);
            self::assertStringNotContainsString('CustomTableDatabase::literal', $source, $file);
            self::assertStringNotContainsString('PreparedCustomTableQuery::fromPreparedSql', $source, $file);
            if (!str_ends_with($file, '/app/Support/CustomTableDatabase.php')) {
                self::assertStringNotContainsString('$wpdb->prepare(', $source, $file);
                self::assertDoesNotMatchRegularExpression(
                    '/\$this->(?:wpdb|database)->(?:get_results|get_row|get_var|get_col|query|prepare|insert|update|delete)\s*\(/',
                    $source,
                    $file,
                );
                self::assertDoesNotMatchRegularExpression(
                    '/CustomTableDatabase::literal\s*\(\s*(?:\$_|filter_input\s*\(|wp_unslash\s*\()/',
                    $source,
                    $file,
                );
            }
            $lines = preg_split('/\R/', $source) ?: [];

            foreach ($lines as $index => $line) {
                if (
                    str_contains($line, 'phpcs:ignore')
                    && !str_ends_with($file, '/app/Support/CustomTableDatabase.php')
                    && !(
                        str_ends_with($file, '/app/Domain/Plan/PlanProductLinkService.php')
                        && preg_match(
                            '/phpcs:ignore WordPress\.DB\.SlowDBQuery\.slow_db_query_meta_(?:key|value) -- Required FluentCart custom-table column; insertInto prepares values and the reindex action refreshes derived integration data\.$/',
                            trim($line),
                        )
                    )
                ) {
                    $failures[] = sprintf('%s:%d has an unaudited PHPCS annotation', $file, $index + 1);
                }

                if (!preg_match(
                    '/\$wpdb->(get_results|get_row|get_var|get_col|query|insert|update|delete|replace)\s*\(/',
                    $line,
                    $match,
                )) {
                    continue;
                }

                $annotation = $lines[$index - 1] ?? '';
                if (!str_contains(
                    $annotation,
                    'phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery',
                )) {
                    $failures[] = sprintf('%s:%d lacks a line-local DirectQuery annotation', $file, $index + 1);
                    continue;
                }

                if (
                    in_array($match[1], ['get_results', 'get_row', 'get_var', 'get_col'], true)
                    && !str_contains($annotation, 'WordPress.DB.DirectDatabaseQuery.NoCaching')
                ) {
                    $failures[] = sprintf('%s:%d lacks a line-local NoCaching annotation', $file, $index + 1);
                }

                if (!preg_match('/ -- [A-Z][^\r\n]{19,}\.?$/', trim($annotation))) {
                    $failures[] = sprintf('%s:%d lacks a concrete annotation reason', $file, $index + 1);
                }
            }
        }

        self::assertSame([], $failures, implode("\n", $failures));
    }

    public function test_dynamic_table_identifiers_use_wordpress_identifier_preparation(): void
    {
        $failures = [];

        foreach ($this->productionPhpFiles() as $file) {
            $lines = preg_split('/\R/', (string) file_get_contents($file)) ?: [];
            foreach ($lines as $index => $line) {
                if (
                    preg_match(
                        '/\$(?:this->)?[A-Za-z_][A-Za-z0-9_]*(?:table|Table)[A-Za-z0-9_]*\s*=\s*\$wpdb->(?:prefix|[A-Za-z_]+)/',
                        $line,
                    )
                ) {
                    $failures[] = sprintf(
                        '%s:%d assigns a table identifier without CustomTableDatabase::identifier()',
                        $file,
                        $index + 1,
                    );
                }
            }
        }

        self::assertSame([], $failures, implode("\n", $failures));
    }
}
