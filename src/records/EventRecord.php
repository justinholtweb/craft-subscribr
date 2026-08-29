<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * One line of a subscription's history.
 */
class EventRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::EVENTS;
    }
}
