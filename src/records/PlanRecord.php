<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\records;

use craft\db\ActiveRecord;
use justinholtweb\subscribr\db\Table;

/**
 * A recurring plan.
 *
 * @property int $id
 * @property int|null $storeId
 * @property int|null $boxId
 * @property int|null $dunningId
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property string $interval
 * @property int $intervalCount
 * @property int|null $anchorDay
 * @property int $trialDays
 * @property float|null $signupFee
 * @property int $maxCycles
 * @property string $pricingMode
 * @property float|null $planPrice
 * @property float|null $discountPercent
 * @property string|null $prepaidOptions
 * @property float|null $prepaidDiscountPercent
 * @property bool $allowPause
 * @property int $maxPauseCycles
 * @property bool $allowSkip
 * @property int $maxSkipsPerYear
 * @property bool $allowSwap
 * @property bool $allowSwitch
 * @property bool $allowCancel
 * @property bool $allowGift
 * @property bool $allowQuantityChange
 * @property string $cancelMode
 * @property string $switchMode
 * @property string|null $switchGroup
 * @property bool $shippable
 * @property bool $enabled
 * @property int|null $sortOrder
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class PlanRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::PLANS;
    }
}
