<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\RuntimeSymbols;

/**
 * A runtime whose symbol table the test decides.
 *
 * The probe asks "is this function/class/method/constant here?" and nothing
 * else through this seam, which is the only part of a compatibility check a
 * unit test cannot otherwise reach: a class, once declared, stays declared for
 * the rest of the process, so the missing-API branches are unreachable from a
 * shared-process suite that also has to exercise the present-API ones.
 *
 * Everything the probe does with the answers — calling FluentCart's canonical
 * collection-method probe, reading the schema, aggregating the census, deciding
 * topology and attributing reasons — still runs against the real stubs.
 *
 * Starts fully populated (every symbol present) so a test states only what it
 * wants taken away.
 */
final class FakeRuntimeSymbols implements RuntimeSymbols
{
    /** @var array<string, bool> */
    private array $absentFunctions = [];

    /** @var array<string, bool> */
    private array $absentClasses = [];

    /** @var array<string, bool> */
    private array $absentMethods = [];

    /** @var array<string, string|null> */
    private array $constants = [];

    /** @var array<string, list<string>> */
    private array $fillable = [];

    public function withoutFunction(string $function): self
    {
        $this->absentFunctions[$function] = true;

        return $this;
    }

    public function withoutClass(string $class): self
    {
        $this->absentClasses[$class] = true;

        return $this;
    }

    public function withoutMethod(string $class, string $method): self
    {
        $this->absentMethods[$class . '::' . $method] = true;

        return $this;
    }

    public function withConstant(string $constant, ?string $value): self
    {
        $this->constants[$constant] = $value;

        return $this;
    }

    /**
     * Make a model declare a different mass-assignment whitelist.
     *
     * @param list<string> $fillable
     */
    public function withFillable(string $class, array $fillable): self
    {
        $this->fillable[$class] = $fillable;

        return $this;
    }

    public function functionExists(string $function): bool
    {
        return !isset($this->absentFunctions[$function]);
    }

    public function classExists(string $class): bool
    {
        return !isset($this->absentClasses[$class]);
    }

    public function methodExists(string $class, string $method): bool
    {
        if (isset($this->absentClasses[$class])) {
            return false;
        }

        return !isset($this->absentMethods[$class . '::' . $method]);
    }

    /**
     * An override when the test set one, otherwise whatever is really defined.
     *
     * Falling through matters for class constants: FluentCart's
     * `SubscriptionManagementMode::CONFIG_KEY` is the key the census groups by
     * and the writer stamps, and reading the real one keeps that agreement
     * honest instead of restating it in the fixture.
     */
    public function constantValue(string $constant): ?string
    {
        if (array_key_exists($constant, $this->constants)) {
            return $this->constants[$constant];
        }

        // A class constant cannot be defined if the class is not loaded, so a
        // class taken away takes its constants with it. Without this, removing
        // SubscriptionManagementMode would still hand back its SETTING_KEY.
        [$owner] = explode('::', $constant, 2);

        if ($owner !== $constant && isset($this->absentClasses[$owner])) {
            return null;
        }

        return defined($constant) ? (string) constant($constant) : null;
    }

    /**
     * @return list<string>
     */
    public function declaredFillable(string $class): array
    {
        if (array_key_exists($class, $this->fillable)) {
            return $this->fillable[$class];
        }

        return array_values(array_map(strval(...), (new $class())->getFillable()));
    }
}
