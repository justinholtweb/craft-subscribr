<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * The subscription element's own row.
 *
 * @property int $id
 * @property int|null $planId
 * @property int|null $userId
 * @property int|null $gatewayId
 * @property int|null $paymentSourceId
 * @property int|null $orderId
 * @property int|null $boxId
 * @property int|null $billingAddressId
 * @property int|null $shippingAddressId
 * @property string $reference
 * @property string $status
 * @property int $quantity
 * @property string $currency
 * @property float $renewalPrice
 * @property string|null $dateStarted
 * @property string|null $dateTrialEnds
 * @property string|null $dateNextPayment
 * @property string|null $dateCurrentPeriodStart
 * @property string|null $dateCanceled
 * @property string|null $dateEnds
 * @property string|null $dateEnded
 * @property string|null $datePaused
 * @property string|null $dateResumes
 * @property string|null $dateLastPayment
 * @property int $cycleCount
 * @property int $prepaidCyclesRemaining
 * @property int $skipsUsed
 * @property int $pausesUsed
 * @property int $failureCount
 * @property int $dunningStage
 * @property string|null $dateNextRetry
 * @property string|null $lastFailureMessage
 * @property bool $isManual
 * @property bool $autoRenew
 * @property string|null $cancelReason
 * @property string|null $note
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SubscriptionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SUBSCRIPTIONS;
    }
}
