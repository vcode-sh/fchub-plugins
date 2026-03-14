<?php

declare(strict_types=1);

namespace CartShift\Core;

use CartShift\Core\Contracts\ModuleInterface;

defined('ABSPATH') || exit();

final class ModuleRegistry
{
    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function add(ModuleInterface $module): self
    {
        $key = $module->key();

        if (isset($this->modules[$key])) {
            throw new \InvalidArgumentException(
                sprintf('Module "%s" is already registered.', $key),
            );
        }

        $this->modules[$key] = $module;

        return $this;
    }

    public function boot(): void
    {
        /** @var FeatureFlags $flags */
        $flags = $this->container->get(FeatureFlags::class);

        foreach ($this->modules as $key => $module) {
            if (! $flags->isEnabled($key)) {
                continue;
            }

            $module->register($this->container);
        }
    }

    /** @return string[] */
    public function keys(): array
    {
        return array_keys($this->modules);
    }
}
