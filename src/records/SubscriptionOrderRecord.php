<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * The link between a subscription and an order it produced.
 *
 * @property int $id
 * @property int $subscriptionId
 * @property int $orderId
 * @property string $kind
 * @property int $cycle
 * @property bool $isPaidFromPrepaid
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SubscriptionOrderRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ORDERS;
    }
}
