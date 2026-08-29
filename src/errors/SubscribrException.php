<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\errors;

use yii\base\Exception;

/**
 * Something Subscribr cannot do.
 *
 * Thrown only for conditions that mean the *caller* is wrong — a subscription with no plan, a
 * selection for a box that does not exist. A declined card is not one of these: it is a normal
 * outcome of a renewal, and it comes back as a `RenewalResult`, because a sweep over five hundred
 * subscriptions must not end on the first bad card.
 */
class SubscribrException extends Exception
{
    public function getName(): string
    {
        return 'Subscribr Exception';
    }
}
