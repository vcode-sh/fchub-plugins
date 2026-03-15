<?php

/**
 * WP-CLI stubs for IDE autocompletion.
 *
 * Provides type information for WP_CLI static methods and utility functions
 * so the IDE can resolve them without requiring WP-CLI as a Composer dependency.
 *
 * @noinspection PhpMultipleClassDeclarationsInspection
 */

namespace {
    if (class_exists('WP_CLI')) {
        return;
    }

    /**
     * WP-CLI main class.
     */
    class WP_CLI
    {
        /**
         * Display an informational message (no prefix).
         *
         * @param string $message
         */
        public static function line(string $message = ''): void {}

        /**
         * Display an informational message with "Info:" prefix.
         *
         * @param string $message
         */
        public static function log(string $message): void {}

        /**
         * Display a success message with "Success:" prefix.
         *
         * @param string $message
         */
        public static function success(string $message): void {}

        /**
         * Display an error message and exit.
         *
         * @param string|\WP_Error $message
         * @param bool $exit
         * @return never-return
         */
        public static function error($message, bool $exit = true): void {}

        /**
         * Display a warning message with "Warning:" prefix.
         *
         * @param string $message
         */
        public static function warning(string $message): void {}

        /**
         * Display a debug message (only shown with --debug flag).
         *
         * @param string $message
         * @param string $group
         */
        public static function debug(string $message, string $group = ''): void {}

        /**
         * Ask for confirmation before proceeding.
         *
         * @param string $question
         * @param array  $assocArgs
         */
        public static function confirm(string $question, array $assocArgs = []): void {}

        /**
         * Register a command to WP-CLI.
         *
         * @param string          $name
         * @param callable|string $callable
         * @param array           $args
         */
        public static function add_command(string $name, callable|string $callable, array $args = []): bool
        {
            return true;
        }

        /**
         * Run a WP-CLI command.
         *
         * @param string $command
         * @param array  $options
         * @return int
         */
        public static function runcommand(string $command, array $options = []): int
        {
            return 0;
        }

        /**
         * Halt script execution with a specific return code.
         *
         * @param int $code
         */
        public static function halt(int $code): void {}

        /**
         * Read a value from the WP-CLI config.
         *
         * @param string $key
         * @return mixed
         */
        public static function get_config(string $key): mixed
        {
            return null;
        }
    }
}

namespace WP_CLI\Utils {
    if (function_exists(__NAMESPACE__ . '\\make_progress_bar')) {
        return;
    }

    /**
     * Create a progress bar.
     *
     * @param string $message Label for the progress bar.
     * @param int    $count   Total number of ticks.
     * @return object Progress bar object with tick() and finish() methods.
     */
    function make_progress_bar(string $message, int $count): object
    {
        return new class {
            public function tick(int $increment = 1): void {}
            public function finish(): void {}
        };
    }

    /**
     * Display items in a table, JSON, CSV, or other format.
     *
     * @param string $format
     * @param array  $items
     * @param array  $fields
     */
    function format_items(string $format, array $items, array|string $fields): void {}

    /**
     * Get a flag value from associative args.
     *
     * @param array  $assocArgs
     * @param string $flag
     * @param mixed  $default
     * @return mixed
     */
    function get_flag_value(array $assocArgs, string $flag, mixed $default = false): mixed
    {
        return $default;
    }

    /**
     * Get the temp directory.
     *
     * @return string
     */
    function get_temp_dir(): string
    {
        return sys_get_temp_dir();
    }
}
