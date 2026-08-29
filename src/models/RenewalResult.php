<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\models;

use craft\base\Model;
use craft\commerce\elements\Order;
use DateTime;

/**
 * What happened when a subscription was asked to renew.
 *
 * Returned rather than thrown, for every outcome including the failures, because a renewal sweep
 * runs over hundreds of subscriptions and one declined card must not end the run. The only things
 * that throw out of the engine are conditions that mean the *engine* is wrong — a missing plan, a
 * subscription with no items — and even those are caught per subscription by the sweep.
 */
class RenewalResult extends Model
{
    public const RENEWED = 'renewed';
    public const PREPAID = 'prepaid';
    public const SKIPPED = 'skipped';
    public const PAUSED = 'paused';
    public const NOT_DUE = 'notDue';
    public const TRIAL = 'trial';
    public const FAILED = 'failed';
    public const MANUAL = 'manual';
    public const ENDED = 'ended';
    public const ABANDONED = 'abandoned';
    public const ERROR = 'error';

    public string $outcome = self::NOT_DUE;
    public ?int $subscriptionId = null;
    public ?Order $order = null;
    public ?int $cycle = null;
    public ?float $amount = null;
    public ?string $currency = null;
    public ?DateTime $nextPaymentDate = null;
    public ?string $message = null;
    public ?Attempt $attempt = null;

    public function getIsSuccess(): bool
    {
        return in_array($this->outcome, [self::RENEWED, self::PREPAID, self::SKIPPED, self::MANUAL], true);
    }

    /** Whether the cycle moved on. A failure does not advance the schedule; dunning owns it from there. */
    public function getDidAdvance(): bool
    {
        return in_array($this->outcome, [self::RENEWED, self::PREPAID, self::SKIPPED, self::MANUAL], true);
    }

    public static function make(string $outcome, int $subscriptionId, ?string $message = null): self
    {
        return new self([
            'outcome' => $outcome,
            'subscriptionId' => $subscriptionId,
            'message' => $message,
        ]);
    }
}
