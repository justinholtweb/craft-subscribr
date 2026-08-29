<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * One attempt to take a renewal payment.
 */
class AttemptRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ATTEMPTS;
    }
}
