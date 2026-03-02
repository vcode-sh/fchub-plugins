<?php

namespace FChubP24\Tests;

/**
 * Custom stream wrapper to mock php://input in tests.
 */
class PhpInputStreamWrapper
{
    /** @var resource|null Stream context (required by PHP 8.4+) */
    public $context;
    public static string $input = '';
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        if ($path !== 'php://input') {
            return false;
        }
        $this->position = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        $data = substr(self::$input, $this->position, $count);
        $this->position += strlen($data);
        return $data;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$input);
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }
}
