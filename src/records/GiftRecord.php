<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * A gifted subscription, before and after it is claimed.
 */
class GiftRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::GIFTS;
    }
}
