<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * A subscription box definition.
 *
 * @property int $id
 * @property int|null $storeId
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property string $mode
 * @property string $pricing
 * @property float|null $boxPrice
 * @property int|null $minItems
 * @property int|null $maxItems
 * @property bool $allowSwap
 * @property int $lockHours
 * @property int $avoidRepeatCycles
 * @property bool $enabled
 * @property int|null $sortOrder
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class BoxRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::BOXES;
    }
}
