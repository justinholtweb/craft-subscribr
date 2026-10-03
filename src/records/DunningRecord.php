<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * A named dunning sequence.
 *
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property string|null $stages
 * @property bool $isDefault
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class DunningRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DUNNING;
    }
}
