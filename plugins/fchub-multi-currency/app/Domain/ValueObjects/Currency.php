<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\ValueObjects;

use FChubMultiCurrency\Domain\Enums\CurrencyPosition;

defined('ABSPATH') || exit;

final readonly class Currency
{
    public function __construct(
        public string $code,
        public string $name,
        public string $symbol,
        public int $decimals,
        public CurrencyPosition $position,
    ) {
    }

    public static function from(array $data): self
    {
        return new self(
            code: strtoupper($data['code']),
            name: $data['name'],
            symbol: $data['symbol'],
            decimals: (int) ($data['decimals'] ?? 2),
            position: CurrencyPosition::tryFrom($data['position'] ?? 'left') ?? CurrencyPosition::Left,
        );
    }

    public function isBase(string $baseCurrencyCode): bool
    {
        return $this->code === strtoupper($baseCurrencyCode);
    }
}
