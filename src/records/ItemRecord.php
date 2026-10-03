<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * One recurring line on a subscription.
 *
 * @property int $id
 * @property int $subscriptionId
 * @property int|null $purchasableId
 * @property int|null $boxSlotId
 * @property int|null $cycle
 * @property int $qty
 * @property float $price
 * @property string|null $description
 * @property string|null $sku
 * @property string|null $options
 * @property string|null $snapshot
 * @property int|null $sortOrder
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ItemRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ITEMS;
    }
}
