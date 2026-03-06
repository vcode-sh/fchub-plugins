<?php

declare(strict_types=1);

namespace FchubThankYou\Domain\ValueObjects;

use FchubThankYou\Domain\Enums\RedirectType;

final readonly class RedirectSettings
{
    public function __construct(
        public bool $enabled,
        public RedirectType $type = RedirectType::Url,
        public ?int $targetId = null,
        public string $url = '',
        public string $postType = '',
    ) {
    }

    public function hasValidTarget(): bool
    {
        return match ($this->type) {
            RedirectType::Page, RedirectType::Post, RedirectType::Cpt => $this->targetId !== null,
            RedirectType::Url => $this->url !== '',
        };
    }

    /** @return array{enabled: bool, type: string, target_id: int|null, url: string, post_type: string} */
    public function toArray(): array
    {
        return [
            'enabled'   => $this->enabled,
            'type'      => $this->type->value,
            'target_id' => $this->targetId,
            'url'       => $this->url,
            'post_type' => $this->postType,
        ];
    }
}
