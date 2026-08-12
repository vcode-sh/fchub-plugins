<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class OrderNoteRecord
{
    public function __construct(
        public SourceIdentity $identity,
        public int $sourceNoteId,
        public string $content,
        public string $createdUtc,
        public bool $customerVisible,
        public string $authorKind,
        public string $publicIdentifier,
    ) {
        if ($sourceNoteId <= 0 || $content === '' || $createdUtc === '' || $publicIdentifier === '') {
            throw new \InvalidArgumentException('Order-note values are incomplete.');
        }
    }

    public function toArray(): array
    {
        return ['identity' => $this->identity->canonical(), 'source_note_id' => $this->sourceNoteId,
            'content' => $this->content, 'created_utc' => $this->createdUtc,
            'customer_visible' => $this->customerVisible, 'author_kind' => $this->authorKind,
            'public_identifier' => $this->publicIdentifier];
    }

    /** Public evidence deliberately excludes content and its digest. */
    public function publicEvidence(): array
    {
        return ['public_identifier' => $this->publicIdentifier, 'customer_visible' => $this->customerVisible,
            'author_kind' => $this->authorKind];
    }
}
