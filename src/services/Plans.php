<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\services;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use justinholtweb\subscribr\db\Table;
use justinholtweb\subscribr\models\Plan;
use justinholtweb\subscribr\records\PlanRecord;
use yii\base\Component;

/**
 * Plan storage.
 *
 * In the database rather than project config, and the reason is the same one Commerce has for its
 * own shipping methods: a plan can point at a box, and a box points at purchasables, and
 * purchasable IDs mean nothing in another environment. `subscribr/plans export|import` moves plans
 * between environments by handle and SKU, which is the only way that can be right.
 */
class Plans extends Component
{
    /** @var Plan[]|null */
    private ?array $_allPlans = null;

    /** @return Plan[] */
    public function getAllPlans(): array
    {
        if ($this->_allPlans === null) {
            $rows = $this->_query()->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])->all();
            $this->_allPlans = array_map(fn(array $row): Plan => $this->_toModel($row), $rows);
        }

        return $this->_allPlans;
    }

    /** @return Plan[] */
    public function getEnabledPlans(): array
    {
        return array_values(array_filter($this->getAllPlans(), static fn(Plan $p): bool => $p->enabled));
    }

    public function getPlanById(int $id): ?Plan
    {
        foreach ($this->getAllPlans() as $plan) {
            if ($plan->id === $id) {
                return $plan;
            }
        }

        return null;
    }

    public function getPlanByHandle(string $handle): ?Plan
    {
        foreach ($this->getAllPlans() as $plan) {
            if ($plan->handle === $handle) {
                return $plan;
            }
        }

        return null;
    }

    /** @return Plan[] */
    public function getPlansInGroup(?string $group): array
    {
        if ($group === null) {
            return $this->getEnabledPlans();
        }

        return array_values(array_filter(
            $this->getEnabledPlans(),
            static fn(Plan $p): bool => $p->switchGroup === $group,
        ));
    }

    /**
     * The plans `$plan` may be switched onto, in the order a UI should offer them.
     *
     * @return Plan[]
     */
    public function getSwitchOptions(Plan $plan): array
    {
        $options = array_values(array_filter(
            $this->getEnabledPlans(),
            static fn(Plan $other): bool => $plan->canSwitchTo($other),
        ));

        // Cheapest first, so the "downgrade instead of cancelling" option is not buried under the
        // upsells. A retention offer nobody can find is not a retention offer.
        usort($options, static fn(Plan $a, Plan $b): int => ($a->planPrice ?? 0) <=> ($b->planPrice ?? 0));

        return $options;
    }

    public function savePlan(Plan $plan, bool $runValidation = true): bool
    {
        if ($runValidation && !$plan->validate()) {
            Craft::info('Plan not saved due to validation error.', __METHOD__);

            return false;
        }

        $isNew = !$plan->id;
        $record = $isNew ? new PlanRecord() : PlanRecord::findOne($plan->id);

        if ($record === null) {
            $record = new PlanRecord();
            $isNew = true;
        }

        $record->storeId = $plan->storeId ?? Commerce::getInstance()?->getStores()->getPrimaryStore()->id;
        $record->boxId = $plan->boxId;
        $record->dunningId = $plan->dunningId;
        $record->name = $plan->name;
        $record->handle = $plan->handle;
        $record->description = $plan->description;
        $record->interval = $plan->interval;
        $record->intervalCount = $plan->intervalCount;
        $record->anchorDay = $plan->anchorDay;
        $record->trialDays = $plan->trialDays;
        $record->signupFee = $plan->signupFee;
        $record->maxCycles = $plan->maxCycles;
        $record->pricingMode = $plan->pricingMode;
        $record->planPrice = $plan->planPrice;
        $record->discountPercent = $plan->discountPercent;
        $record->prepaidOptions = $plan->prepaidOptions;
        $record->prepaidDiscountPercent = $plan->prepaidDiscountPercent;
        $record->allowPause = $plan->allowPause;
        $record->maxPauseCycles = $plan->maxPauseCycles;
        $record->allowSkip = $plan->allowSkip;
        $record->maxSkipsPerYear = $plan->maxSkipsPerYear;
        $record->allowSwap = $plan->allowSwap;
        $record->allowSwitch = $plan->allowSwitch;
        $record->allowCancel = $plan->allowCancel;
        $record->allowGift = $plan->allowGift;
        $record->allowQuantityChange = $plan->allowQuantityChange;
        $record->cancelMode = $plan->cancelMode;
        $record->switchMode = $plan->switchMode;
        $record->switchGroup = $plan->switchGroup;
        $record->shippable = $plan->shippable;
        $record->enabled = $plan->enabled;
        $record->sortOrder = $plan->sortOrder ?? $this->_nextSortOrder();

        if (!$record->save()) {
            $plan->addErrors($record->getErrors());

            return false;
        }

        $plan->id = (int)$record->id;
        $plan->uid = $record->uid;
        $this->_allPlans = null;

        return true;
    }

    /**
     * Delete a plan.
     *
     * Refused while anything live is on it. A plan is the only description of what a subscriber
     * agreed to — how often, for how much, for how long — and deleting it out from under a running
     * subscription leaves a charge nobody can explain. Disable it instead: a disabled plan takes no
     * new subscribers and renews its existing ones exactly as before.
     */
    public function deletePlanById(int $id): bool
    {
        $inUse = (new Query())
            ->from([Table::SUBSCRIPTIONS])
            ->where(['planId' => $id])
            ->andWhere(['not', ['status' => 'expired']])
            ->exists();

        if ($inUse) {
            return false;
        }

        $record = PlanRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        $record->delete();
        $this->_allPlans = null;

        return true;
    }

    public function getSubscriberCount(int $planId, bool $liveOnly = true): int
    {
        $query = (new Query())->from([Table::SUBSCRIPTIONS])->where(['planId' => $planId]);

        if ($liveOnly) {
            $query->andWhere(['status' => ['active', 'trialing', 'pastDue', 'canceled']]);
        }

        return (int)$query->count('[[id]]');
    }

    public function reorderPlans(array $ids): bool
    {
        $db = Craft::$app->getDb();

        foreach ($ids as $order => $id) {
            $db->createCommand()
                ->update(Table::PLANS, ['sortOrder' => $order + 1], ['id' => $id])
                ->execute();
        }

        $this->_allPlans = null;

        return true;
    }

    /**
     * A plan as portable data: no IDs, a box handle instead of a box ID.
     */
    public function toExportArray(Plan $plan): array
    {
        $data = $plan->toArray([], ['id', 'storeId', 'boxId', 'dunningId', 'uid', 'dateCreated', 'dateUpdated', 'sortOrder']);
        $data['box'] = $plan->getBox()?->handle;
        $data['dunning'] = $plan->getDunningProfile()?->handle;

        unset($data['id'], $data['storeId'], $data['boxId'], $data['dunningId'], $data['uid'], $data['dateCreated'], $data['dateUpdated'], $data['sortOrder']);

        return $data;
    }

    private function _nextSortOrder(): int
    {
        return (int)(new Query())->from([Table::PLANS])->max('[[sortOrder]]') + 1;
    }

    private function _query(): Query
    {
        return (new Query())->select('*')->from([Table::PLANS]);
    }

    private function _toModel(array $row): Plan
    {
        $plan = new Plan();
        $plan->id = (int)$row['id'];
        $plan->storeId = isset($row['storeId']) ? (int)$row['storeId'] : null;
        $plan->boxId = isset($row['boxId']) ? (int)$row['boxId'] : null;
        $plan->dunningId = isset($row['dunningId']) ? (int)$row['dunningId'] : null;
        $plan->name = (string)$row['name'];
        $plan->handle = (string)$row['handle'];
        $plan->description = $row['description'] ?? null;
        $plan->interval = (string)$row['interval'];
        $plan->intervalCount = (int)$row['intervalCount'];
        $plan->anchorDay = isset($row['anchorDay']) ? (int)$row['anchorDay'] : null;
        $plan->trialDays = (int)$row['trialDays'];
        $plan->signupFee = isset($row['signupFee']) ? (float)$row['signupFee'] : null;
        $plan->maxCycles = (int)$row['maxCycles'];
        $plan->pricingMode = (string)$row['pricingMode'];
        $plan->planPrice = isset($row['planPrice']) ? (float)$row['planPrice'] : null;
        $plan->discountPercent = isset($row['discountPercent']) ? (float)$row['discountPercent'] : null;
        $plan->prepaidOptions = $row['prepaidOptions'] ?? null;
        $plan->prepaidDiscountPercent = isset($row['prepaidDiscountPercent']) ? (float)$row['prepaidDiscountPercent'] : null;
        $plan->allowPause = (bool)$row['allowPause'];
        $plan->maxPauseCycles = (int)$row['maxPauseCycles'];
        $plan->allowSkip = (bool)$row['allowSkip'];
        $plan->maxSkipsPerYear = (int)$row['maxSkipsPerYear'];
        $plan->allowSwap = (bool)$row['allowSwap'];
        $plan->allowSwitch = (bool)$row['allowSwitch'];
        $plan->allowCancel = (bool)$row['allowCancel'];
        $plan->allowGift = (bool)$row['allowGift'];
        $plan->allowQuantityChange = (bool)$row['allowQuantityChange'];
        $plan->cancelMode = (string)$row['cancelMode'];
        $plan->switchMode = (string)$row['switchMode'];
        $plan->switchGroup = $row['switchGroup'] ?? null;
        $plan->shippable = (bool)$row['shippable'];
        $plan->enabled = (bool)$row['enabled'];
        $plan->sortOrder = isset($row['sortOrder']) ? (int)$row['sortOrder'] : null;
        $plan->dateCreated = isset($row['dateCreated']) ? DateTimeHelper::toDateTime($row['dateCreated']) ?: null : null;
        $plan->dateUpdated = isset($row['dateUpdated']) ? DateTimeHelper::toDateTime($row['dateUpdated']) ?: null : null;
        $plan->uid = $row['uid'] ?? null;

        return $plan;
    }
}
