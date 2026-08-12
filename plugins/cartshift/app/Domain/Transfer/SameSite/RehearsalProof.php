<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Execution\TransferRunState;

defined('ABSPATH') || exit;

/**
 * Evidence that a rehearsal finished, and the only way to obtain a cutover plan.
 *
 * "Rehearsal or cutover" was one of the sixteen decisions, and it is the one
 * that should never have been a question. So it stops being one: a cutover plan
 * takes an instance of this, an instance exists only for a run that reached
 * `Completed`, and there is therefore nothing an impatient operator can pass to
 * skip ahead. The illegal state is unrepresentable rather than guarded against
 * at the point of use.
 */
final readonly class RehearsalProof
{
    private function __construct(
        public string $runId,
    ) {
    }

    /** @throws \InvalidArgumentException when the rehearsal did not finish. */
    public static function fromRehearsal(string $runId, TransferRunState $state): self
    {
        if ($state !== TransferRunState::Completed) {
            throw new \InvalidArgumentException(sprintf(
                'A cutover needs a rehearsal that finished. Run "%s" is %s.',
                $runId,
                $state->value,
            ));
        }

        return new self($runId);
    }
}
