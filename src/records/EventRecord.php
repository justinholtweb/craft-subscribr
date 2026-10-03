<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * One line of a subscription's history.
 *
 * @property int $id
 * @property int $subscriptionId
 * @property string $type
 * @property string|null $message
 * @property string|null $data
 * @property int|null $userId
 * @property string $source
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class EventRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::EVENTS;
    }
}
