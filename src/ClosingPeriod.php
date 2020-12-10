<?php

namespace Spatie\OpeningHours;

use DateTimeInterface;

class ClosingPeriod
{

    /** @var \Spatie\OpeningHours\DateRange */
    protected $range;

    public static function fromRange(string $start, string $end)
    {
        $closingPeriod = new static();

        $dateRange = DateRange::fromDefinition($start, $end);
        $closingPeriod->range = $dateRange;

        return $closingPeriod;
    }

    public function range(): DateRange
    {
        return $this->range;
    }

    public function isInRange(DateTimeInterface $date): bool
    {
        if($this->isEmpty()) {
            return false;
        }

        $date->setTime(0, 0, 0);

        if($this->range->start()->toDateTime() <= $date && $this->range->end()->toDateTime() >= $date) {
            return true;
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return empty($this->range);
    }

    public function __toString()
    {
        return (string) $this->range->start() . ' - ' . (string) $this->range->end();
    }
}
