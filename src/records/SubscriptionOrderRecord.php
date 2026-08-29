<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * The link between a subscription and an order it produced.
 */
class SubscriptionOrderRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ORDERS;
    }
}
