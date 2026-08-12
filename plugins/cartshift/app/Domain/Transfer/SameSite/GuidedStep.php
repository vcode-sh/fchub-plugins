<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

defined('ABSPATH') || exit;

/**
 * One transfer verb with the arguments a human would otherwise have typed.
 *
 * `pending` names the evidence this step is still missing — the audit's
 * fingerprint, the export's package, the prepare's descriptor. An empty list
 * means runnable. Naming what is absent is the difference between a step that
 * waits and a step that runs with a guess: the guess is how a stage confirms a
 * selection nobody audited.
 */
final readonly class GuidedStep
{
    /**
     * @param array<string, string|true> $arguments `true` renders as a bare flag.
     * @param list<string> $pending Sorted option names whose evidence has not arrived.
     */
    public function __construct(
        public string $verb,
        public array $arguments,
        public array $pending = [],
    ) {
    }

    public function isRunnable(): bool
    {
        return $this->pending === [];
    }

    /**
     * The command this step would be, for a screen that wants to show its
     * working or a support ticket that needs to quote it.
     *
     * Values carrying whitespace are quoted, because `--decided-at` is a UTC
     * timestamp with a space in the middle of it and an unquoted one turns a
     * copied command into a parse error two arguments later. The runner passes
     * an array and never sees this; the human who pastes it does.
     */
    public function command(): string
    {
        $rendered = ['wp cartshift transfer ' . $this->verb];

        foreach ($this->arguments as $option => $value) {
            $rendered[] = $value === true
                ? '--' . $option
                : '--' . $option . '=' . self::quote((string) $value);
        }

        return implode(' ', $rendered);
    }

    private static function quote(string $value): string
    {
        return preg_match('/\A[A-Za-z0-9._\/:-]*\z/D', $value) === 1
            ? $value
            : "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
