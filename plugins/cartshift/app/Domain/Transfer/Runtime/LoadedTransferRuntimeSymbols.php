<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Runtime;

defined('ABSPATH') || exit;

use CartShift\Support\CanonicalJson;

final class LoadedTransferRuntimeSymbols implements TransferRuntimeSymbols
{
    public function functionExists(string $function): bool
    {
        return function_exists($function);
    }

    public function classExists(string $class): bool
    {
        return class_exists($class);
    }

    public function methodExists(string $class, string $method): bool
    {
        return method_exists($class, $method);
    }

    public function constantValue(string $constant): ?string
    {
        if (!defined($constant)) {
            return null;
        }

        $value = constant($constant);

        return is_scalar($value) ? (string) $value : null;
    }

    public function modelFillable(string $class): array
    {
        if (!$this->classExists($class) || !$this->methodExists($class, 'getFillable')) {
            return [];
        }

        return array_values(array_map(strval(...), (new $class())->getFillable()));
    }

    public function modelCasts(string $class): array
    {
        if (!$this->classExists($class) || !$this->methodExists($class, 'getCasts')) {
            return [];
        }

        $casts = (new $class())->getCasts();

        if (!is_array($casts)) {
            return [];
        }

        return array_map(
            static fn(mixed $cast): string => is_string($cast) ? $cast : get_debug_type($cast),
            $casts,
        );
    }

    public function runtimeVersion(string $component): ?string
    {
        global $wp_version;

        return match ($component) {
            'php' => PHP_VERSION,
            'wordpress' => isset($wp_version) && is_string($wp_version) ? $wp_version : null,
            'woocommerce' => $this->constantValue('WC_VERSION'),
            'wcs' => $this->wcsVersion(),
            'fluentcart' => $this->constantValue('FLUENTCART_VERSION'),
            'cartshift' => $this->constantValue('CARTSHIFT_VERSION'),
            'cartshift_db' => $this->constantValue('CARTSHIFT_DB_VERSION'),
            default => null,
        };
    }

    public function runtimeDigest(string $component): ?string
    {
        if ($component !== 'wcs') {
            return null;
        }

        $root = $this->wcsRoot();

        if ($root === null) {
            return null;
        }

        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }

            $path = $file->getRealPath();

            if (!is_string($path)) {
                return null;
            }

            $digest = hash_file('sha256', $path);

            if (!is_string($digest)) {
                return null;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
            $entries[$relative] = [
                'bytes' => $file->getSize(),
                'sha256' => $digest,
            ];
        }

        ksort($entries);

        return CanonicalJson::fingerprint($entries);
    }

    private function wcsVersion(): ?string
    {
        $constant = $this->constantValue('WCS_VERSION');

        if ($constant !== null) {
            return $constant;
        }

        if (!$this->classExists('WC_Subscriptions')) {
            return null;
        }

        foreach (['version', '_version'] as $property) {
            if (property_exists('WC_Subscriptions', $property)) {
                $value = (new \ReflectionClass('WC_Subscriptions'))->getStaticPropertyValue($property);

                return is_scalar($value) ? (string) $value : null;
            }
        }

        return null;
    }

    private function wcsRoot(): ?string
    {
        $pluginFile = $this->constantValue('WCS_PLUGIN_FILE');

        if ($pluginFile !== null && is_file($pluginFile)) {
            $root = realpath(dirname($pluginFile));

            return is_string($root) ? $root : null;
        }

        if (!$this->classExists('WC_Subscriptions')) {
            return null;
        }

        $classFile = (new \ReflectionClass('WC_Subscriptions'))->getFileName();
        $pluginRoot = defined('WP_PLUGIN_DIR') ? realpath((string) WP_PLUGIN_DIR) : false;
        $classPath = is_string($classFile) ? realpath($classFile) : false;

        if (!is_string($pluginRoot) || !is_string($classPath) || !str_starts_with($classPath, $pluginRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $relative = substr($classPath, strlen($pluginRoot) + 1);
        $slug = strtok($relative, DIRECTORY_SEPARATOR);

        if (!is_string($slug) || $slug === '') {
            return null;
        }

        $root = realpath($pluginRoot . DIRECTORY_SEPARATOR . $slug);

        return is_string($root) ? $root : null;
    }
}
