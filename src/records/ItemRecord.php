<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * One recurring line on a subscription.
 */
class ItemRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ITEMS;
    }
}
