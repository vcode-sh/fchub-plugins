<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

final readonly class SelectionClause
{
    /** @var list<int> */
    public array $ids;

    /**
     * @param list<int> $ids
     */
    public function __construct(
        public SelectionMode $mode,
        array $ids = [],
        public ?string $since = null,
    ) {
        if (!array_is_list($ids)) {
            throw new \InvalidArgumentException('Selection IDs must be a list.');
        }

        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new \InvalidArgumentException('Selection IDs must be positive integers.');
            }
        }

        if (count($ids) !== count(array_unique($ids, SORT_REGULAR))) {
            throw new \InvalidArgumentException('Selection IDs must be unique.');
        }

        sort($ids, SORT_NUMERIC);
        $this->ids = $ids;

        match ($mode) {
            SelectionMode::Ids => $this->assertIdsMode(),
            SelectionMode::Since => $this->assertSinceMode(),
            SelectionMode::None, SelectionMode::All => $this->assertValuelessMode(),
        };
    }

    public static function none(): self
    {
        return new self(SelectionMode::None);
    }

    public static function all(): self
    {
        return new self(SelectionMode::All);
    }

    /** @param list<int> $ids */
    public static function ids(array $ids): self
    {
        return new self(SelectionMode::Ids, $ids);
    }

    public static function since(string $since): self
    {
        return new self(SelectionMode::Since, [], $since);
    }

    /** @return array{mode: string, ids?: list<int>, since?: string} */
    public function canonical(): array
    {
        return match ($this->mode) {
            SelectionMode::Ids => ['mode' => $this->mode->value, 'ids' => $this->ids],
            SelectionMode::Since => ['mode' => $this->mode->value, 'since' => $this->since],
            SelectionMode::None, SelectionMode::All => ['mode' => $this->mode->value],
        };
    }

    private function assertIdsMode(): void
    {
        if ($this->ids === [] || $this->since !== null) {
            throw new \InvalidArgumentException('IDs mode requires a non-empty ID list and no date.');
        }
    }

    private function assertSinceMode(): void
    {
        if ($this->ids !== [] || $this->since === null || !$this->isCanonicalUtcDate($this->since)) {
            throw new \InvalidArgumentException('Since mode requires one valid canonical UTC timestamp and no IDs.');
        }
    }

    private function assertValuelessMode(): void
    {
        if ($this->ids !== [] || $this->since !== null) {
            throw new \InvalidArgumentException('None and all modes cannot carry IDs or a date.');
        }
    }

    private function isCanonicalUtcDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $value,
            new \DateTimeZone('UTC'),
        );

        return $date !== false
            && $date->format('Y-m-d\TH:i:s\Z') === $value
            && \DateTimeImmutable::getLastErrors() === false;
    }
}
