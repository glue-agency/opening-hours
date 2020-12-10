<?php

namespace Spatie\OpeningHours;

use DateTime;
use DateTimeInterface;

class Date
{

    /** @var int */
    protected $day;

    /** @var int */
    protected $month;

    /** @var int */
    protected $year;

    public function __construct(int $day, int $month, int $year)
    {
        $this->day = $day;
        $this->month = $month;
        $this->year = $year;
    }

    public static function fromString(string $string): self
    {
        [$year, $month, $day] = explode('-', $string);

        return new self($day, $month, $year);
    }

    public function day(): int
    {
        return $this->day;
    }

    public function month(): int
    {
        return $this->month;
    }

    public function year(): int
    {
        return $this->year;
    }

    public function toDateTime(): DateTimeInterface
    {
        return DateTime::createFromFormat('!Y-m-d', $this->year . '-' . $this->month . '-' . $this->day);
    }

    public static function fromDateTime(DateTimeInterface $dateTime): self
    {
        return static::fromString($dateTime->format('Y-m-d'));
    }

    public function format(string $format = 'Y-m-d'): string
    {
        return $this->toDateTime()->format($format);
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
