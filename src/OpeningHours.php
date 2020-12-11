<?php

namespace Spatie\OpeningHours;

use DateTime;
use DateTimeZone;
use DateTimeImmutable;
use DateTimeInterface;
use Spatie\OpeningHours\Helpers\Arr;
use Spatie\OpeningHours\Helpers\DataTrait;
use Spatie\OpeningHours\Exceptions\Exception;
use Spatie\OpeningHours\Exceptions\InvalidDate;
use Spatie\OpeningHours\Exceptions\InvalidDayName;

class OpeningHours
{
    use DataTrait;

    /** @var \Spatie\OpeningHours\Day[] */
    protected $openingHours = [];

    /** @var \Spatie\OpeningHours\ClosingPeriod[] */
    protected $closingPeriods = [];

    /** @var \Spatie\OpeningHours\OpeningHoursForDay[] */
    protected $exceptions = [];

    /** @var callable[] */
    protected $filters = [];

    /** @var DateTimeZone|null */
    protected $timezone = null;

    public function __construct($timezone = null)
    {
        $this->timezone = $timezone ? new DateTimeZone($timezone) : null;

        $this->openingHours = Day::mapDays(function () {
            return new OpeningHoursForDay();
        });
    }

    /**
     * @param array $data
     *
     * @return static
     */
    public static function create(array $data)
    {
        return (new static())->fill($data);
    }

    /**
     * @param array $data
     *
     * @return array
     */
    public static function mergeOverlappingRanges(array $data)
    {
        $result = [];
        $ranges = [];
        foreach ($data as $key => $value) {
            $value = is_array($value)
                ? static::mergeOverlappingRanges($value)
                : (is_string($value) ? TimeRange::fromString($value) : $value);

            if ($value instanceof TimeRange) {
                $newRanges = [];
                foreach ($ranges as $range) {
                    if ($value->format() === $range->format()) {
                        continue 2;
                    }

                    if ($value->overlaps($range) || $range->overlaps($value)) {
                        $value = TimeRange::fromList([$value, $range]);

                        continue;
                    }

                    $newRanges[] = $range;
                }

                $newRanges[] = $value;
                $ranges = $newRanges;

                continue;
            }

            $result[$key] = $value;
        }

        foreach ($ranges as $range) {
            $result[] = $range;
        }

        return $result;
    }

    /**
     * @param array $data
     *
     * @return static
     */
    public static function createAndMergeOverlappingRanges(array $data)
    {
        return static::create(static::mergeOverlappingRanges($data));
    }

    /**
     * @param array $data
     *
     * @return bool
     */
    public static function isValid(array $data): bool
    {
        try {
            static::create($data);

            return true;
        } catch (Exception $exception) {
            return false;
        }
    }

    public function setFilters(array $filters)
    {
        $this->filters = $filters;

        return $this;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function fill(array $data)
    {
        list($openingHours, $closingPeriods, $exceptions, $metaData, $filters) = $this->parseOpeningHoursAndExceptions($data);

        foreach ($openingHours as $day => $openingHoursForThisDay) {
            $this->setOpeningHoursFromStrings($day, $openingHoursForThisDay);
        }

        $this->setClosingPeriodsFromStrings($closingPeriods);

        $this->setExceptionsFromStrings($exceptions);

        return $this->setFilters($filters)->setData($metaData);
    }

    public function forRegularWeek(): array
    {
        return $this->openingHours;
    }

    public function forWeek(): array
    {
        $monday = new DateTime('monday this week');
        $sunday = new DateTime('sunday this week');

        // https://stackoverflow.com/a/38226650/2787376
        $sunday->setTime(0,0,1);

        $period = new \DatePeriod(
            $monday,
            new \DateInterval('P1D'),
            $sunday
        );

        $week = [];

        foreach($period as $day) {
            $week[$day->format('l')] = $this->forDate($day);
        }

        return $week;
    }

    public function forWeekCombined(): array
    {
        $equalDays = [];
        $allOpeningHours = $this->openingHours;
        $uniqueOpeningHours = array_unique($allOpeningHours);
        $nonUniqueOpeningHours = $allOpeningHours;

        foreach ($uniqueOpeningHours as $day => $value) {
            $equalDays[$day] = ['days' => [$day], 'opening_hours' => $value];
            unset($nonUniqueOpeningHours[$day]);
        }

        foreach ($uniqueOpeningHours as $uniqueDay => $uniqueValue) {
            foreach ($nonUniqueOpeningHours as $nonUniqueDay => $nonUniqueValue) {
                if ((string) $uniqueValue === (string) $nonUniqueValue) {
                    $equalDays[$uniqueDay]['days'][] = $nonUniqueDay;
                }
            }
        }

        return $equalDays;
    }

    public function forDay(string $day): OpeningHoursForDay
    {
        $day = $this->normalizeDayName($day);

        return $this->openingHours[$day];
    }

    public function forDate(DateTimeInterface $date): OpeningHoursForDay
    {
        $date = $this->applyTimezone($date);

        foreach($this->closingPeriods as $period) {
            if($period->isInRange($date)) {
                return OpeningHoursForDay::fromStrings([]);
            }
        }

        foreach ($this->filters as $filter) {
            $result = $filter($date);

            if (is_array($result)) {
                return OpeningHoursForDay::fromStrings($result);
            }
        }

        return $this->exceptions[$date->format('Y-m-d')] ?? ($this->exceptions[$date->format('m-d')] ?? $this->forDay(Day::onDateTime($date)));
    }

    public function closingPeriods(): array
    {
        return $this->closingPeriods;
    }

    public function exceptions(): array
    {
        return $this->exceptions;
    }

    public function isOpenOn(string $day): bool
    {
        return count($this->forDay($day)) > 0;
    }

    public function isClosedOn(string $day): bool
    {
        return ! $this->isOpenOn($day);
    }

    public function isOpenAt(DateTimeInterface $dateTime): bool
    {
        $dateTime = $this->applyTimezone($dateTime);

        $openingHoursForDay = $this->forDate($dateTime);

        return $openingHoursForDay->isOpenAt(Time::fromDateTime($dateTime));
    }

    public function isClosedAt(DateTimeInterface $dateTime): bool
    {
        return ! $this->isOpenAt($dateTime);
    }

    public function isOpen(): bool
    {
        return $this->isOpenAt(new DateTime());
    }

    public function isClosed(): bool
    {
        return $this->isClosedAt(new DateTime());
    }

    public function nextOpen(DateTimeInterface $dateTime): DateTimeInterface
    {
        if (! ($dateTime instanceof DateTimeImmutable)) {
            $dateTime = clone $dateTime;
        }

        $openingHoursForDay = $this->forDate($dateTime);
        $nextOpen = $openingHoursForDay->nextOpen(Time::fromDateTime($dateTime));

        while ($nextOpen === false || $nextOpen->hours() >= 24) {
            $dateTime = $dateTime
                ->modify('+1 day')
                ->setTime(0, 0, 0);

            if ($this->isOpenAt($dateTime) && ! $openingHoursForDay->isOpenAt(Time::fromString('23:59'))) {
                return $dateTime;
            }

            $openingHoursForDay = $this->forDate($dateTime);

            $nextOpen = $openingHoursForDay->nextOpen(Time::fromDateTime($dateTime));
        }

        if ($dateTime->format('H:i') === '00:00' && $this->isOpenAt((clone $dateTime)->modify('-1 second'))) {
            return $this->nextOpen($dateTime->modify('+1 minute'));
        }

        $nextDateTime = $nextOpen->toDateTime();
        $dateTime = $dateTime->setTime($nextDateTime->format('G'), $nextDateTime->format('i'), 0);

        return $dateTime;
    }

    public function nextClose(DateTimeInterface $dateTime): DateTimeInterface
    {
        if (! ($dateTime instanceof DateTimeImmutable)) {
            $dateTime = clone $dateTime;
        }

        $openingHoursForDay = $this->forDate($dateTime);
        $nextClose = $openingHoursForDay->nextClose(Time::fromDateTime($dateTime));

        while ($nextClose === false || $nextClose->hours() >= 24) {
            $dateTime = $dateTime
                ->modify('+1 day')
                ->setTime(0, 0, 0);

            if ($this->isClosedAt($dateTime) && $openingHoursForDay->isOpenAt(Time::fromString('23:59'))) {
                return $dateTime;
            }

            $openingHoursForDay = $this->forDate($dateTime);

            $nextClose = $openingHoursForDay->nextClose(Time::fromDateTime($dateTime));
        }

        $nextDateTime = $nextClose->toDateTime();
        $dateTime = $dateTime->setTime($nextDateTime->format('G'), $nextDateTime->format('i'), 0);

        return $dateTime;
    }

    public function regularClosingDays(): array
    {
        return array_keys($this->filter(function (OpeningHoursForDay $openingHoursForDay) {
            return $openingHoursForDay->isEmpty();
        }));
    }

    public function regularClosingDaysISO(): array
    {
        return Arr::map($this->regularClosingDays(), [Day::class, 'toISO']);
    }

    public function exceptionalClosingDates(): array
    {
        $dates = array_keys($this->filterExceptions(function (OpeningHoursForDay $openingHoursForDay) {
            return $openingHoursForDay->isEmpty();
        }));

        return Arr::map($dates, function ($date) {
            return DateTime::createFromFormat('Y-m-d', $date);
        });
    }

    public function setTimezone($timezone)
    {
        $this->timezone = new DateTimeZone($timezone);
    }

    protected function parseOpeningHoursAndExceptions(array $data): array
    {
        $metaData = Arr::pull($data, 'data', null);
        $closingPeriods = [];
        $exceptions = [];
        $filters = Arr::pull($data, 'filters', []);

        foreach (Arr::pull($data, 'closing_periods', []) as $start => $end) {
            $closingPeriods[$start] = $end;
        }

        foreach (Arr::pull($data, 'exceptions', []) as $key => $exception) {
            if (is_callable($exception)) {
                $filters[] = $exception;

                continue;
            }

            $exceptions[$key] = $exception;
        }
        $openingHours = [];

        foreach ($data as $day => $openingHoursData) {
            $openingHours[$this->normalizeDayName($day)] = $openingHoursData;
        }

        return [$openingHours, $closingPeriods, $exceptions, $metaData, $filters];
    }

    protected function setOpeningHoursFromStrings(string $day, array $openingHours)
    {
        $day = $this->normalizeDayName($day);

        $data = null;

        if (isset($openingHours['data'])) {
            $data = $openingHours['data'];
            unset($openingHours['data']);
        }

        $this->openingHours[$day] = OpeningHoursForDay::fromStrings($openingHours)->setData($data);
    }

    protected function setClosingPeriodsFromStrings(array $closing_periods)
    {
        $this->closingPeriods = array_map(function(string $start, string $end) {
            $recurring = DateTime::createFromFormat('m-d', $start);

            if ($recurring === false || $recurring->format('m-d') !== $start) {
                $dateTime = DateTime::createFromFormat('Y-m-d', $start);

                if ($dateTime === false || $dateTime->format('Y-m-d') !== $start) {
                    throw InvalidDate::invalidDate($start);
                }
            }

            return ClosingPeriod::fromRange($start, $end);
        }, array_keys($closing_periods), $closing_periods);
    }

    protected function setExceptionsFromStrings(array $exceptions)
    {
        $this->exceptions = Arr::map($exceptions, function (array $openingHours, string $date) {
            $recurring = DateTime::createFromFormat('m-d', $date);

            if ($recurring === false || $recurring->format('m-d') !== $date) {
                $dateTime = DateTime::createFromFormat('Y-m-d', $date);

                if ($dateTime === false || $dateTime->format('Y-m-d') !== $date) {
                    throw InvalidDate::invalidDate($date);
                }
            }

            return OpeningHoursForDay::fromStrings($openingHours);
        });
    }

    protected function normalizeDayName(string $day)
    {
        $day = strtolower($day);

        if (! Day::isValid($day)) {
            throw InvalidDayName::invalidDayName($day);
        }

        return $day;
    }

    protected function applyTimezone(DateTimeInterface $date)
    {
        if ($this->timezone) {
            $date = $date->setTimezone($this->timezone);
        }

        return $date;
    }

    public function filter(callable $callback): array
    {
        return Arr::filter($this->openingHours, $callback);
    }

    public function map(callable $callback): array
    {
        return Arr::map($this->openingHours, $callback);
    }

    public function flatMap(callable $callback): array
    {
        return Arr::flatMap($this->openingHours, $callback);
    }

    public function filterExceptions(callable $callback): array
    {
        return Arr::filter($this->exceptions, $callback);
    }

    public function mapExceptions(callable $callback): array
    {
        return Arr::map($this->exceptions, $callback);
    }

    public function flatMapExceptions(callable $callback): array
    {
        return Arr::flatMap($this->exceptions, $callback);
    }

    public function asStructuredData(): array
    {
        $regularHours = $this->flatMap(function (OpeningHoursForDay $openingHoursForDay, string $day) {
            return $openingHoursForDay->map(function (TimeRange $timeRange) use ($day) {
                return [
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => ucfirst($day),
                    'opens' => (string) $timeRange->start(),
                    'closes' => (string) $timeRange->end(),
                ];
            });
        });

        $exceptions = $this->flatMapExceptions(function (OpeningHoursForDay $openingHoursForDay, string $date) {
            if ($openingHoursForDay->isEmpty()) {
                return [[
                    '@type' => 'OpeningHoursSpecification',
                    'opens' => '00:00',
                    'closes' => '00:00',
                    'validFrom' => $date,
                    'validThrough' => $date,
                ]];
            }

            return $openingHoursForDay->map(function (TimeRange $timeRange) use ($date) {
                return [
                    '@type' => 'OpeningHoursSpecification',
                    'opens' => (string) $timeRange->start(),
                    'closes' => (string) $timeRange->end(),
                    'validFrom' => $date,
                    'validThrough' => $date,
                ];
            });
        });

        return array_merge($regularHours, $exceptions);
    }
}
