<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * A gifted subscription, before and after it is claimed.
 *
 * @property int $id
 * @property int|null $subscriptionId
 * @property int|null $orderId
 * @property int|null $purchaserId
 * @property int|null $recipientId
 * @property string $recipientEmail
 * @property string|null $recipientName
 * @property string|null $senderName
 * @property string|null $message
 * @property string $token
 * @property int $cycles
 * @property string|null $dateDeliver
 * @property string|null $dateDelivered
 * @property string|null $dateClaimed
 * @property string|null $dateExpires
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class GiftRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::GIFTS;
    }
}
