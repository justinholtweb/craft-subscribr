<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * A named dunning sequence.
 */
class DunningRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DUNNING;
    }
}
