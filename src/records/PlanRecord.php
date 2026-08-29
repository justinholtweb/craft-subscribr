<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * A recurring plan.
 */
class PlanRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::PLANS;
    }
}
