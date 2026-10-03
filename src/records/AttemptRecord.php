<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * One attempt to take a renewal payment.
 *
 * @property int $id
 * @property int $subscriptionId
 * @property int|null $orderId
 * @property int|null $transactionId
 * @property int $stage
 * @property string $outcome
 * @property float|null $amount
 * @property string|null $currency
 * @property string|null $message
 * @property string|null $gatewayCode
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class AttemptRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ATTEMPTS;
    }
}
