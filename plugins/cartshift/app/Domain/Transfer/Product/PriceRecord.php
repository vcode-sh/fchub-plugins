<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

defined('ABSPATH') || exit;

final readonly class PriceRecord
{
    public function __construct(
        public ?int $activePrice,
        public ?int $regularPrice,
        public ?int $salePrice,
        public ?string $saleStartsUtc,
        public ?string $saleEndsUtc,
        public string $currency,
    ) {
        if (preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new \InvalidArgumentException('Price currency must be an uppercase ISO-style code.');
        }

        foreach ([$saleStartsUtc, $saleEndsUtc] as $date) {
            if ($date !== null && !self::isUtc($date)) {
                throw new \InvalidArgumentException('Sale dates must be canonical UTC timestamps.');
            }
        }

        if ($saleStartsUtc !== null && $saleEndsUtc !== null && $saleStartsUtc > $saleEndsUtc) {
            throw new \InvalidArgumentException('Sale start cannot follow sale end.');
        }
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'active_price' => $this->activePrice,
            'regular_price' => $this->regularPrice,
            'sale_price' => $this->salePrice,
            'sale_starts_utc' => $this->saleStartsUtc,
            'sale_ends_utc' => $this->saleEndsUtc,
            'currency' => $this->currency,
        ];
    }

    private static function isUtc(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }
}
