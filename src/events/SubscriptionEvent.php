<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\events;

use justinholtweb\subscribr\elements\Subscription;
use yii\base\Event;

/**
 * Raised around a subscription's transitions. `isValid = false` on a `before` event stops it.
 */
class SubscriptionEvent extends Event
{
    public Subscription $subscription;
    public bool $isValid = true;
}
