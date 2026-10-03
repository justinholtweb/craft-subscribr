<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * One slot in a box.
 *
 * @property int $id
 * @property int $boxId
 * @property string $name
 * @property int $minItems
 * @property int $maxItems
 * @property string|null $sources
 * @property bool $isAddOn
 * @property int|null $sortOrder
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class BoxSlotRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::BOXSLOTS;
    }
}
