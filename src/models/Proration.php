<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\models;

use craft\base\Model;
use DateTime;
use justinholtweb\subscribr\helpers\Money;

/**
 * What a mid-cycle change costs, and the arithmetic that got there.
 *
 * Every field a UI would need to *show its working* is on the model, because the whole point of
 * a proration UI is that the subscriber can see why the number is what it is before they agree
 * to it. A preview that only produced a total would be a worse version of not showing one.
 *
 * The same object is produced by the portal preview, the CP preview and the switch that is
 * actually applied — the confirmation cannot disagree with the charge.
 */
class Proration extends Model
{
    public const MODE_IMMEDIATE = 'immediate';
    public const MODE_END = 'end';

    public string $mode = self::MODE_IMMEDIATE;
    public string $currency = 'USD';

    /** The period being left. */
    public ?DateTime $periodStart = null;
    public ?DateTime $periodEnd = null;
    public ?DateTime $effectiveDate = null;

    public int $daysInPeriod = 0;
    public int $daysUsed = 0;
    public int $daysRemaining = 0;

    /** What the subscriber is on now, per cycle. */
    public float $oldCycleAmount = 0.0;

    /** What they would be on, per cycle. */
    public float $newCycleAmount = 0.0;

    /** The unused part of what they already paid, as a positive number. */
    public float $credit = 0.0;

    /** The new plan's charge for the rest of the period, as a positive number. */
    public float $charge = 0.0;

    /** Anything left over that could not be billed now and is carried to the next renewal. */
    public float $carriedCredit = 0.0;

    /** @var Plan|null The plan being moved to. */
    public ?Plan $newPlan = null;

    /** @var Plan|null The plan being moved from. */
    public ?Plan $oldPlan = null;

    /** Why the maths is what it is, in the order it happened. Rendered in the CP preview. */
    public array $lines = [];

    /**
     * What is owed right now. Negative means the store owes the subscriber, which Subscribr does
     * not refund automatically — it carries. See `carriedCredit`.
     */
    public function getNetDue(): float
    {
        return round($this->charge - $this->credit, 2);
    }

    public function getIsCredit(): bool
    {
        return $this->getNetDue() < 0;
    }

    public function getIsFree(): bool
    {
        return abs($this->getNetDue()) < 0.005;
    }

    /** The share of the period already consumed, 0.0–1.0. Drives the progress bar in the preview. */
    public function getUsedFraction(): float
    {
        return $this->daysInPeriod > 0 ? round($this->daysUsed / $this->daysInPeriod, 4) : 0.0;
    }

    public function addLine(string $label, float $amount, ?string $detail = null): void
    {
        $this->lines[] = [
            'label' => $label,
            'amount' => round($amount, 2),
            'detail' => $detail,
        ];
    }

    public function formatAmount(float $amount): string
    {
        return Money::format($amount, $this->currency);
    }
}
