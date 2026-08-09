<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * What this PHP runtime has actually loaded.
 *
 * The compatibility gate's whole job is to answer "is the API the plan assumes
 * really here?", which means every one of its conclusions rests on
 * function_exists / class_exists / method_exists / defined. Those four cannot
 * be un-answered once a class is declared, so they are the one thing the probe
 * asks through a seam rather than calling directly — otherwise half the gate
 * (the half that refuses to run) would be untestable in a shared-process suite.
 *
 * This is a seam for tests, not an extension point. There is deliberately no
 * filter behind it: a safety gate that can be told it is fine is not a gate.
 */
interface RuntimeSymbols
{
    public function functionExists(string $function): bool;

    public function classExists(string $class): bool;

    public function methodExists(string $class, string $method): bool;

    /**
     * A defined constant's value as a string, or null when it is not defined.
     *
     * Class constants are addressed the way PHP addresses them, `Class::NAME`.
     */
    public function constantValue(string $constant): ?string;

    /**
     * The mass-assignment whitelist a loaded ORM model declares.
     *
     * Here rather than called directly on the FQCN because it is the same kind
     * of question as the four above — what has this runtime loaded, and what
     * does it say about itself — and because the drift it exists to catch (a
     * required column dropping out of $fillable, so mass assignment silently
     * omits it from a NOT NULL insert) is otherwise unreachable from a test:
     * the installed model's declaration is a constant of the process.
     *
     * Callers must check methodExists($class, 'getFillable') first.
     *
     * @return list<string>
     */
    public function declaredFillable(string $class): array;
}
