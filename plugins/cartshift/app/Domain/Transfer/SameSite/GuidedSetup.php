<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Execution\ConfiguredTransferEvidence;

defined('ABSPATH') || exit;

/**
 * The one-time `wp-config.php` edit a guided run cannot make for itself.
 *
 * Everything from `stage` onwards resolves through
 * `LoadedTargetTransferPipeline`, which asks `ConfiguredTransferEvidence` for
 * its working directory and its lease holder. That class reads a PHP constant
 * or an environment variable and nothing else — no filter, no option, no
 * setter. It is the same principle as `RuntimeSymbols` and the banned `local`
 * source key: evidence a web request can set is not evidence, and a gate that
 * can be told it is fine is not a gate.
 *
 * So a guided run cannot configure its own way past `prepare`. What it can do
 * is say precisely what is missing, suggest values the real validators accept,
 * and hand over two lines to paste. Once. Ever.
 *
 * IT DOES NOT PRETEND TO UNLOCK CUTOVER. `assertCutoverApproval()` needs a
 * sha256 constant matching a manifest file whose operator matches, per run
 * rather than once, and a browser cannot define a PHP constant. `cutover()`
 * says so up front rather than letting somebody finish a rehearsal and discover
 * the last button was never there.
 */
final readonly class GuidedSetup
{
    public function __construct(
        private string $sourceKey,
        private string $operatorId,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->requirements() === [];
    }

    /** @return list<array{constant:string,purpose:string}> */
    public function requirements(): array
    {
        $missing = [];
        if (!PrivateWorkspace::isTransferDirectoryConfigured()) {
            $missing[] = [
                'constant' => ConfiguredTransferEvidence::PRIVATE_DIRECTORY,
                'purpose' => 'Keeps the private rehearsal package outside the public site.',
            ];
        }
        if (!$this->isOperatorConfigured()) {
            $missing[] = [
                'constant' => ConfiguredTransferEvidence::OPERATOR_ID,
                'purpose' => 'Prevents two transfer runs from owning the shop at once.',
            ];
        }

        return $missing;
    }

    /**
     * What is not configured, in the order it should be pasted.
     *
     * @return list<array{constant: string, purpose: string, suggested: string}>
     */
    public function missing(): array
    {
        $missing = [];

        if (!PrivateWorkspace::isTransferDirectoryConfigured()) {
            $missing[] = [
                'constant' => ConfiguredTransferEvidence::PRIVATE_DIRECTORY,
                'purpose' => 'Where CartShift keeps the transfer package and the run\'s evidence. It holds every '
                    . 'customer and order in the shop, so it lives outside the web root with private permissions '
                    . 'and CartShift will not improvise a location for it.',
                'suggested' => $this->suggestionFor(ConfiguredTransferEvidence::PRIVATE_DIRECTORY),
            ];
        }

        if (!$this->isOperatorConfigured()) {
            $missing[] = [
                'constant' => ConfiguredTransferEvidence::OPERATOR_ID,
                'purpose' => 'Who holds the transfer lease. Two runs cannot stage at once, and the lease has to '
                    . 'belong to a name that outlives a single request.',
                'suggested' => $this->suggestionFor(ConfiguredTransferEvidence::OPERATOR_ID),
            ];
        }

        return $missing;
    }

    /**
     * A value the real validator will accept.
     *
     * The directory suggestion comes from `PrivateWorkspace`, which creates it
     * `0700` outside the web root — the five conditions
     * `PrivateTransferFile::directory()` enforces — so the suggestion is a
     * directory that already exists rather than a path somebody has to go and
     * make.
     */
    public function suggestionFor(string $constant): string
    {
        return match ($constant) {
            ConfiguredTransferEvidence::PRIVATE_DIRECTORY => (new PrivateWorkspace($this->sourceKey))->path(),
            ConfiguredTransferEvidence::OPERATOR_ID => $this->operatorId,
            default => throw new \InvalidArgumentException('Unknown transfer evidence constant: ' . $constant),
        };
    }

    /** Two lines, pasteable, or an empty string when there is nothing to do. */
    public function wpConfigSnippet(): string
    {
        $lines = array_map(
            static fn (array $requirement): string => sprintf(
                "define('%s', '%s');",
                $requirement['constant'],
                addcslashes($requirement['suggested'], "'\\"),
            ),
            $this->missing(),
        );

        return implode("\n", $lines);
    }

    /**
     * Whether the browser can perform the real thing, and why not.
     *
     * @return array{available: bool, reason: string, message: string}
     */
    public function cutover(): array
    {
        return [
            'available' => false,
            'reason' => 'cutover_approval_is_per_run_evidence',
            'message' => 'Cutover remains unavailable until CartShift can roll back a completed rehearsal and '
                . 'prove the shop returned to its exact starting state. The guided check stops before target '
                . 'records while that core contract is missing.',
        ];
    }

    private function isOperatorConfigured(): bool
    {
        try {
            ConfiguredTransferEvidence::operatorId();

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}
