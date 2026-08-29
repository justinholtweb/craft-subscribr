<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * One slot in a box.
 */
class BoxSlotRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::BOXSLOTS;
    }
}
