<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\events;

use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\models\Proration;
use yii\base\Event;

/**
 * Raised once a proration has been computed and before it is shown or charged.
 *
 * The same event fires for the preview and for the real thing, which is the point: a plugin that
 * changes the number cannot change it in only one of the two places.
 */
class ProrationEvent extends Event
{
    public Subscription $subscription;
    public Proration $proration;
}
