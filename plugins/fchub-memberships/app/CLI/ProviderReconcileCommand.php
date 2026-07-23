<?php

declare(strict_types=1);

namespace FChubMemberships\CLI;

use FChubMemberships\Domain\Reconciliation\ProviderReconciliationService;

defined('ABSPATH') || exit;

final class ProviderReconcileCommand
{
    private \Closure $scanner;
    private \Closure $repairer;

    public function __construct(?callable $scanner = null, ?callable $repairer = null)
    {
        if ($scanner === null || $repairer === null) {
            $service = new ProviderReconciliationService();
            $scanner ??= $service->scanPage(...);
            $repairer ??= $service->repair(...);
        }
        $this->scanner = \Closure::fromCallable($scanner);
        $this->repairer = \Closure::fromCallable($repairer);
    }

    /**
     * Scan provider drift, or schedule one explicit durable repair.
     *
     * [--cursor=<cursor>]
     * : Continue a bounded dry-run scan.
     *
     * [--limit=<number>]
     * : Resources to scan. Defaults to 50 and is capped at 100.
     *
     * [--repair]
     * : Schedule an explicit repair instead of scanning.
     *
     * [--user-id=<id>]
     * [--provider=<provider>]
     * [--resource-type=<type>]
     * [--resource-id=<id>]
     * [--request-id=<id>]
     * [--expected-classification=<classification>]
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $repair = in_array($assocArgs['repair'] ?? false, [true, 1, '1', 'true', 'yes'], true);
        if (!$repair) {
            $cursor = trim((string) ($assocArgs['cursor'] ?? ''));
            $result = ($this->scanner)(
                $cursor === '' ? null : $cursor,
                (int) ($assocArgs['limit'] ?? 50)
            );
            $this->write(['mode' => 'dry-run'] + $result);
            return;
        }

        $resource = [
            'user_id' => (int) ($assocArgs['user-id'] ?? 0),
            'provider' => trim((string) ($assocArgs['provider'] ?? '')),
            'resource_type' => trim((string) ($assocArgs['resource-type'] ?? '')),
            'resource_id' => trim((string) ($assocArgs['resource-id'] ?? '')),
        ];
        $requestId = trim((string) ($assocArgs['request-id'] ?? ''));
        $expectedClassification = trim((string) ($assocArgs['expected-classification'] ?? ''));
        if ($resource['user_id'] <= 0
            || $resource['provider'] === ''
            || $resource['resource_type'] === ''
            || $resource['resource_id'] === ''
            || $requestId === ''
            || $expectedClassification === ''
        ) {
            \WP_CLI::error(
                'Explicit repair requires user-id, provider, resource-type, resource-id, request-id and expected-classification.'
            );
        }

        $this->write([
            'mode' => 'repair',
            'result' => ($this->repairer)($resource, $requestId, $expectedClassification),
        ]);
    }

    private function write(array $result): void
    {
        $json = wp_json_encode($result);
        if (!is_string($json)) {
            \WP_CLI::error('Provider reconciliation output could not be encoded.');
        }
        \WP_CLI::line($json);
    }
}
