<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * A change booked for a future cycle.
 *
 * @property int $id
 * @property int $subscriptionId
 * @property string $action
 * @property int|null $cycle
 * @property string|null $applyAt
 * @property string|null $payload
 * @property string|null $appliedAt
 * @property string|null $canceledAt
 * @property int|null $createdByUserId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ScheduleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SCHEDULES;
    }
}
