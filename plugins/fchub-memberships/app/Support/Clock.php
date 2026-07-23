<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

final class Clock
{
    private \DateTimeZone $timezone;
    private ?\DateTimeImmutable $fixedNow;

    public function __construct(
        ?\DateTimeImmutable $fixedNow = null,
        ?\DateTimeZone $timezone = null
    ) {
        $this->timezone = $timezone ?? wp_timezone();
        $this->fixedNow = $fixedNow?->setTimezone($this->timezone);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->fixedNow ?? current_datetime()->setTimezone($this->timezone);
    }

    public function parseLocal(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, $this->timezone);
    }

    public function storage(\DateTimeInterface $value): string
    {
        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone($this->timezone)
            ->format('Y-m-d H:i:s');
    }

    public function plusDays(int $days, ?\DateTimeImmutable $from = null): \DateTimeImmutable
    {
        return ($from ?? $this->now())
            ->setTimezone($this->timezone)
            ->modify(sprintf('%+d days', $days));
    }

    public function plusMinutes(int $minutes, ?\DateTimeImmutable $from = null): \DateTimeImmutable
    {
        return ($from ?? $this->now())
            ->setTimezone($this->timezone)
            ->modify(sprintf('%+d minutes', $minutes));
    }

    public function calendarDaysUntil(
        \DateTimeImmutable $until,
        ?\DateTimeImmutable $from = null
    ): int {
        $from = ($from ?? $this->now())->setTimezone($this->timezone);
        $until = $until->setTimezone($this->timezone);

        if ($until <= $from) {
            return 0;
        }

        $difference = $from->diff($until);
        $days = (int) $difference->days;
        $hasPartialDay = $difference->h !== 0
            || $difference->i !== 0
            || $difference->s !== 0
            || $difference->f > 0;

        return $days + ($hasPartialDay ? 1 : 0);
    }
}
