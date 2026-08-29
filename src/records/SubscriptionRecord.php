<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * The subscription element's own row.
 */
class SubscriptionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SUBSCRIPTIONS;
    }
}
