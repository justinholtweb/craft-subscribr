<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\events;

use craft\commerce\elements\Order;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\models\RenewalResult;
use yii\base\Event;

/**
 * Raised around a renewal.
 *
 * `EVENT_BEFORE_RENEW` fires with the order built but not yet paid, which is where a plugin adds a
 * line, a discount or a note. Setting `isValid = false` there skips the cycle *without advancing
 * the schedule*, so the next sweep tries again — it is a veto, not a cancellation.
 */
class RenewalEvent extends Event
{
    public Subscription $subscription;
    public ?Order $order = null;
    public ?RenewalResult $result = null;
    public int $cycle = 0;
    public bool $isValid = true;
}
