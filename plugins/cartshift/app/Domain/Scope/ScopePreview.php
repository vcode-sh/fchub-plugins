<?php

declare(strict_types=1);

namespace CartShift\Domain\Scope;

defined('ABSPATH') || exit;

use CartShift\Domain\Migration\Contracts\MigratorInterface;

/**
 * What POST /preview answers: counts and consequences for a candidate scope,
 * without starting anything.
 *
 * The owner is still choosing — this is called again on every change to the
 * selection, possibly on every keystroke via a debounce on the front end. So
 * it never touches MigrationState, never writes a scope anywhere, and never
 * returns a single record: on a 50,000-order shop, serialising the orders
 * themselves is the exact problem this endpoint exists to avoid. The five
 * migrators are handed the scope directly via useScope() rather than through
 * state for the same reason retry and a real run are not — this is a read,
 * not a run.
 */
final class ScopePreview
{
    /**
     * @param list<MigratorInterface> $migrators
     */
    public function __construct(
        private readonly array $migrators,
        private readonly ScopeResolver $resolver,
    ) {
    }

    /**
     * @param list<string> $entityTypes
     * @return array{
     *   scope: array<string, mixed>,
     *   counts: array<string, int>,
     *   consequences: list<array<string, mixed>>,
     *   closure: array{products: int, customers: int},
     *   too_large: bool
     * }
     */
    public function build(array $entityTypes): array
    {
        $scope = $this->resolver->scope();
        $wanted = array_fill_keys($entityTypes, true);

        $counts = [];

        foreach ($this->migrators as $migrator) {
            // Every migrator gets the scope, even one whose count is not being
            // asked for — CustomerMigrator's own useScope() resets its
            // scope-dependent memo, and skipping the call for an unticked
            // entity would leave it holding a stale total from whatever this
            // instance last counted.
            $migrator->useScope($scope);

            if (!isset($wanted[$migrator->entityType()])) {
                // An entity the owner unticked is not a number they want.
                continue;
            }

            $counts[$migrator->entityType()] = $migrator->count();
        }

        return [
            'scope'        => $scope->toArray(),
            'counts'       => $counts,
            // Filtered by the same list the counts are: a consequence of
            // migrating orders is not a fact about a run that migrates no
            // orders. The caller resolves dependencies before it gets here,
            // so ticking Orders alone still reports the product and customer
            // consequences that run will produce.
            'consequences' => (new ScopeConsequences($this->resolver))->all($entityTypes),
            'closure'      => $this->closure($scope),
            // Force the closure to be resolved before asking whether it
            // exceeded the limit — exceedsClosureLimit() does this itself,
            // but closure() above already paid the cost, so this reads the
            // memoised answer rather than re-running anything.
            'too_large'    => $this->resolver->exceedsClosureLimit(),
        ];
    }

    /**
     * The *added* counts: how many products or customers the closure pulled
     * in that the owner did not pick themselves. That is the sentence the
     * receipt writes — "that also brings in 31 customers and 12 more
     * products" — not the raw closure size, which would double-count the
     * owner's own picks.
     *
     * @return array{products: int, customers: int}
     */
    private function closure(MigrationScope $scope): array
    {
        $productsAdded = count($this->resolver->closedProductIds()) - count($scope->productIds());

        $closedCustomers = $this->resolver->closedCustomers();
        $pickedCustomers = count($scope->customerIds()) + count($scope->guestEmails());
        $closedCustomerTotal = count($closedCustomers['registered']) + count($closedCustomers['guests']);

        return [
            'products'  => max(0, $productsAdded),
            'customers' => max(0, $closedCustomerTotal - $pickedCustomers),
        ];
    }
}
