<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\models;

use craft\base\Model;
use DateInterval;
use DateTime;
use DateTimeImmutable;

/**
 * A billing cadence: "every 2 weeks", "monthly on the 1st", "yearly".
 *
 * **This is the only place a next-payment date is computed.** The renewal engine, the CP preview,
 * the proration maths, the portal's "your next box ships on…" and the console's `renewals/due` all
 * ask a Cadence, so a subscriber can never be shown one date and billed on another.
 *
 * Two traps are handled here and nowhere else:
 *
 * - **Month-end.** `+1 month` from 31 January in PHP is 3 March, which silently moves a
 *   subscriber's billing day forward every short month until it settles on the 3rd. Monthly
 *   cadences clamp to the last day of the target month instead, so 31 Jan bills 28 Feb, then
 *   31 Mar — the day the subscriber signed up on is the day they keep.
 * - **DST.** Advancing by `P1M` on a `DateTime` in a zone that changes offset shifts the wall
 *   clock by an hour. Dates are advanced in the site's zone on the *date part*, and the time of
 *   day is reapplied, so a 09:00 renewal stays a 09:00 renewal across the spring change.
 */
class Cadence extends Model
{
    public const DAY = 'day';
    public const WEEK = 'week';
    public const MONTH = 'month';
    public const YEAR = 'year';

    public const INTERVALS = [self::DAY, self::WEEK, self::MONTH, self::YEAR];

    public string $interval = self::MONTH;
    public int $intervalCount = 1;

    /**
     * A fixed billing day shared by everyone on the plan: 1-28 for monthly and yearly cadences,
     * 0-6 (Sunday first) for weekly ones. Null bills on the anniversary of the signup.
     */
    public ?int $anchorDay = null;

    public static function fromPlan(Plan $plan): self
    {
        return new self([
            'interval' => $plan->interval,
            'intervalCount' => max(1, $plan->intervalCount),
            'anchorDay' => $plan->anchorDay,
        ]);
    }

    /**
     * The next payment date after `$from`.
     */
    public function next(DateTime|DateTimeImmutable $from): DateTime
    {
        $tz = $from->getTimezone();
        $time = $from->format('H:i:s');

        $advanced = match ($this->interval) {
            self::DAY => $this->_addDays($from, $this->intervalCount),
            self::WEEK => $this->_addDays($from, $this->intervalCount * 7),
            self::MONTH => $this->_addMonths($from, $this->intervalCount),
            self::YEAR => $this->_addMonths($from, $this->intervalCount * 12),
            default => $this->_addMonths($from, $this->intervalCount),
        };

        $advanced = $this->_applyAnchor($advanced);

        // Reapply the original time of day in the original zone, so a DST boundary crossed by the
        // date arithmetic cannot move the hour a subscriber is billed at.
        return new DateTime($advanced->format('Y-m-d') . ' ' . $time, $tz);
    }

    /**
     * How many whole days one cycle of this cadence spans, measured from `$from`.
     *
     * Months are not a fixed length, so this is only meaningful against a specific start — which
     * is exactly what proration needs and why it is not a constant.
     */
    public function daysInCycle(DateTime|DateTimeImmutable $from): int
    {
        $start = new DateTimeImmutable($from->format('Y-m-d H:i:s'), $from->getTimezone());
        $end = $this->next($from);

        return max(1, (int)$start->diff($end)->days);
    }

    /**
     * A human description: "every 2 weeks", "monthly", "every 3 months on the 15th".
     */
    public function describe(): string
    {
        $unit = $this->interval;
        $n = $this->intervalCount;

        if ($n === 1) {
            $base = match ($unit) {
                self::DAY => 'daily',
                self::WEEK => 'weekly',
                self::MONTH => 'monthly',
                self::YEAR => 'yearly',
                default => 'every ' . $unit,
            };
        } else {
            $base = 'every ' . $n . ' ' . $unit . 's';
        }

        if ($this->anchorDay === null) {
            return $base;
        }

        if ($unit === self::WEEK) {
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

            return $base . ' on ' . ($days[$this->anchorDay] ?? 'Monday');
        }

        return $base . ' on the ' . $this->_ordinal($this->anchorDay);
    }

    protected function defineRules(): array
    {
        return [
            [['interval', 'intervalCount'], 'required'],
            ['interval', 'in', 'range' => self::INTERVALS],
            ['intervalCount', 'integer', 'min' => 1, 'max' => 365],
            ['anchorDay', 'integer', 'min' => 0, 'max' => 28],
        ];
    }

    private function _addDays(DateTime|DateTimeImmutable $from, int $days): DateTimeImmutable
    {
        return (new DateTimeImmutable($from->format('Y-m-d'), $from->getTimezone()))
            ->add(new DateInterval('P' . $days . 'D'));
    }

    /**
     * Add months, clamping to the last day of the target month rather than overflowing into the
     * next one.
     */
    private function _addMonths(DateTime|DateTimeImmutable $from, int $months): DateTimeImmutable
    {
        $tz = $from->getTimezone();
        $day = (int)$from->format('j');
        $first = new DateTimeImmutable($from->format('Y-m-01'), $tz);
        $target = $first->add(new DateInterval('P' . $months . 'M'));
        $daysInTarget = (int)$target->format('t');

        return $target->setDate(
            (int)$target->format('Y'),
            (int)$target->format('n'),
            min($day, $daysInTarget),
        );
    }

    /**
     * Move a computed date onto the plan's shared billing day, always forwards — pulling it
     * backwards would bill a subscriber for a period they have not had yet.
     */
    private function _applyAnchor(DateTimeImmutable $date): DateTimeImmutable
    {
        if ($this->anchorDay === null) {
            return $date;
        }

        if ($this->interval === self::WEEK) {
            $current = (int)$date->format('w');
            $delta = ($this->anchorDay - $current + 7) % 7;

            return $delta === 0 ? $date : $date->add(new DateInterval('P' . $delta . 'D'));
        }

        if ($this->interval === self::DAY) {
            return $date;
        }

        $day = max(1, min(28, $this->anchorDay));

        if ((int)$date->format('j') === $day) {
            return $date;
        }

        $candidate = $date->setDate((int)$date->format('Y'), (int)$date->format('n'), $day);

        if ($candidate < $date) {
            $candidate = $candidate->add(new DateInterval('P1M'));
        }

        return $candidate;
    }

    private function _ordinal(int $n): string
    {
        if (in_array($n % 100, [11, 12, 13], true)) {
            return $n . 'th';
        }

        return $n . match ($n % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
}
