<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/**
 * The verdict on a whole set of mapping decisions, plus the fingerprint of the
 * set it was reached about.
 *
 * The fingerprint is the load-bearing half. Sections 11's receipt transitions
 * each revalidate against the mapping-set fingerprint recorded at stage time,
 * so a hash that moved when nothing meaningful had changed would invalidate a
 * perfectly good approval — and one that stayed still when a variation choice
 * changed would let a different mapping execute under an old approval.
 *
 * It therefore uses CanonicalJson, the one serialisation RuntimeCompatibilityReport
 * already fingerprints through, rather than inventing a second convention:
 * recursive key sort, then SHA-256. That helper leaves lists alone, so the
 * decision list is sorted here — by `wc_id`, a key that means something —
 * before it is handed over.
 */
final readonly class MappingSetValidation
{
    /** @var list<array<string, mixed>> Sorted, so one collision reads identically every time. */
    public array $errors;

    /** @var list<array<string, mixed>> */
    private array $canonical;

    /**
     * @param list<array<string, mixed>> $errors
     * @param list<array<string, mixed>> $canonical One entry per decision, already reduced to the
     *                                             fields that decide what gets written.
     */
    public function __construct(array $errors, array $canonical)
    {
        usort(
            $errors,
            static fn (array $a, array $b): int
                => [$a['code'] ?? '', $a['target_variation_id'] ?? 0]
                <=> [$b['code'] ?? '', $b['target_variation_id'] ?? 0],
        );

        $this->errors    = array_values($errors);
        $this->canonical = $canonical;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * SHA-256 over the decision set, independent of the order it arrived in.
     */
    public function fingerprint(): string
    {
        return CanonicalJson::fingerprint($this->canonical);
    }

    /**
     * @return array{errors: list<array<string, mixed>>, fingerprint: string, valid: bool}
     */
    public function toArray(): array
    {
        return [
            'errors'      => $this->errors,
            'fingerprint' => $this->fingerprint(),
            'valid'       => $this->isValid(),
        ];
    }
}
