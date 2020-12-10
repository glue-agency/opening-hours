<?php

namespace Spatie\OpeningHours;

class DateRange
{

    /** @var \Spatie\OpeningHours\Date */
    protected $start;

    /** @var \Spatie\OpeningHours\Date */
    protected $end;

    protected function __construct(Date $start, Date $end)
    {
        $this->start = $start;
        $this->end = $end;
    }

    public static function fromDefinition(string $start, string $end): self
    {
        return new self(Date::fromString($start), Date::fromString($end));
    }

    public function start()
    {
        return $this->start;
    }

    public function end()
    {
        return $this->end;
    }
}
