<?php

/**
 * WP-CLI stubs for IDE autocompletion, and for the test suite.
 *
 * Provides type information for WP_CLI static methods and utility functions
 * so the IDE can resolve them without requiring WP-CLI as a Composer dependency.
 *
 * The output methods additionally RECORD what they were told, in
 * `$GLOBALS['_cartshift_test_wp_cli']`. That is what lets a command test assert
 * the stable reason code an operator actually sees rather than only the side
 * effects of the call — a refusal that returns quietly and a refusal that says
 * `source_renewal_maintenance_unconfirmed` are not the same command.
 *
 * The file is autoload-dev only and is excluded from the distribution, and the
 * whole of it is skipped when a real WP-CLI is present, so nothing here ever
 * runs in production.
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
        public static function line(string $message = ''): void
        {
            self::record('line', $message);
        }

        /**
         * Display an informational message with "Info:" prefix.
         *
         * @param string $message
         */
        public static function log(string $message): void
        {
            self::record('log', $message);
        }

        /**
         * Display a success message with "Success:" prefix.
         *
         * @param string $message
         */
        public static function success(string $message): void
        {
            self::record('success', $message);
        }

        /**
         * Display an error message and exit.
         *
         * @param string|\WP_Error $message
         * @param bool $exit
         * @return never-return
         */
        public static function error($message, bool $exit = true): void
        {
            self::record('error', is_string($message) ? $message : 'WP_Error');
        }

        /**
         * Display a warning message with "Warning:" prefix.
         *
         * @param string $message
         */
        public static function warning(string $message): void
        {
            self::record('warning', $message);
        }

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

        /**
         * Remember one message, when a test is watching.
         *
         * Guarded on the global already existing so a real WP-CLI run — which
         * never loads this file anyway — could not start accumulating output in
         * memory if it somehow did.
         */
        private static function record(string $level, string $message): void
        {
            if (!isset($GLOBALS['_cartshift_test_wp_cli']) || !is_array($GLOBALS['_cartshift_test_wp_cli'])) {
                return;
            }

            $GLOBALS['_cartshift_test_wp_cli'][] = ['level' => $level, 'message' => $message];
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
