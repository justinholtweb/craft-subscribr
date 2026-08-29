<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\elements\db;

use craft\db\QueryAbortedException;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use DateTime;
use justinholtweb\subscribr\elements\Subscription;

/**
 * @method Subscription[]|array all($db = null)
 * @method Subscription|array|null one($db = null)
 * @method Subscription|array|null nth(int $n, $db = null)
 */
class SubscriptionQuery extends ElementQuery
{
    public mixed $planId = null;
    public mixed $userId = null;
    public mixed $gatewayId = null;
    public mixed $boxId = null;
    public mixed $orderId = null;
    public mixed $reference = null;
    public mixed $subscriptionStatus = null;
    public mixed $dateNextPayment = null;
    public mixed $dateNextRetry = null;
    public mixed $isManual = null;
    public mixed $autoRenew = null;
    public mixed $hasFailures = null;
    public mixed $isPrepaid = null;

    /** Sugar for "due within N days", which is the query every dashboard actually wants. */
    public ?int $renewingWithin = null;

    /** Everything the renewal sweep should look at right now. */
    public bool $due = false;

    /** Lead time, in hours, applied to `due`. */
    public int $dueLeadHours = 0;

    protected array $defaultOrderBy = ['subscribr_subscriptions.dateNextPayment' => SORT_ASC];

    public function planId(mixed $value): static
    {
        $this->planId = $value;

        return $this;
    }

    public function userId(mixed $value): static
    {
        $this->userId = $value;

        return $this;
    }

    public function gatewayId(mixed $value): static
    {
        $this->gatewayId = $value;

        return $this;
    }

    public function boxId(mixed $value): static
    {
        $this->boxId = $value;

        return $this;
    }

    public function orderId(mixed $value): static
    {
        $this->orderId = $value;

        return $this;
    }

    public function reference(mixed $value): static
    {
        $this->reference = $value;

        return $this;
    }

    public function subscriptionStatus(mixed $value): static
    {
        $this->subscriptionStatus = $value;

        return $this;
    }

    public function dateNextPayment(mixed $value): static
    {
        $this->dateNextPayment = $value;

        return $this;
    }

    public function dateNextRetry(mixed $value): static
    {
        $this->dateNextRetry = $value;

        return $this;
    }

    public function isManual(mixed $value = true): static
    {
        $this->isManual = $value;

        return $this;
    }

    public function autoRenew(mixed $value = true): static
    {
        $this->autoRenew = $value;

        return $this;
    }

    public function hasFailures(mixed $value = true): static
    {
        $this->hasFailures = $value;

        return $this;
    }

    public function isPrepaid(mixed $value = true): static
    {
        $this->isPrepaid = $value;

        return $this;
    }

    public function renewingWithin(?int $days): static
    {
        $this->renewingWithin = $days;

        return $this;
    }

    public function due(bool $value = true, int $leadHours = 0): static
    {
        $this->due = $value;
        $this->dueLeadHours = $leadHours;

        return $this;
    }

    /**
     * @throws QueryAbortedException
     */
    protected function beforePrepare(): bool
    {
        if ($this->elementType === null) {
            $this->elementType = Subscription::class;
        }

        $this->joinElementTable('subscribr_subscriptions');

        $this->query->select([
            'subscribr_subscriptions.planId',
            'subscribr_subscriptions.userId',
            'subscribr_subscriptions.gatewayId',
            'subscribr_subscriptions.paymentSourceId',
            'subscribr_subscriptions.orderId',
            'subscribr_subscriptions.boxId',
            'subscribr_subscriptions.billingAddressId',
            'subscribr_subscriptions.shippingAddressId',
            'subscribr_subscriptions.reference',
            'subscribr_subscriptions.status as subscriptionStatus',
            'subscribr_subscriptions.quantity',
            'subscribr_subscriptions.currency',
            'subscribr_subscriptions.renewalPrice',
            'subscribr_subscriptions.dateStarted',
            'subscribr_subscriptions.dateTrialEnds',
            'subscribr_subscriptions.dateNextPayment',
            'subscribr_subscriptions.dateCurrentPeriodStart',
            'subscribr_subscriptions.dateCanceled',
            'subscribr_subscriptions.dateEnds',
            'subscribr_subscriptions.dateEnded',
            'subscribr_subscriptions.datePaused',
            'subscribr_subscriptions.dateResumes',
            'subscribr_subscriptions.dateLastPayment',
            'subscribr_subscriptions.cycleCount',
            'subscribr_subscriptions.prepaidCyclesRemaining',
            'subscribr_subscriptions.skipsUsed',
            'subscribr_subscriptions.pausesUsed',
            'subscribr_subscriptions.failureCount',
            'subscribr_subscriptions.dunningStage',
            'subscribr_subscriptions.dateNextRetry',
            'subscribr_subscriptions.lastFailureMessage',
            'subscribr_subscriptions.isManual',
            'subscribr_subscriptions.autoRenew',
            'subscribr_subscriptions.cancelReason',
            'subscribr_subscriptions.note',
        ]);

        $this->_applyParam('planId', $this->planId);
        $this->_applyParam('userId', $this->userId);
        $this->_applyParam('gatewayId', $this->gatewayId);
        $this->_applyParam('boxId', $this->boxId);
        $this->_applyParam('orderId', $this->orderId);
        $this->_applyParam('reference', $this->reference);
        $this->_applyParam('status', $this->subscriptionStatus);

        if ($this->dateNextPayment !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('subscribr_subscriptions.dateNextPayment', $this->dateNextPayment));
        }

        if ($this->dateNextRetry !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('subscribr_subscriptions.dateNextRetry', $this->dateNextRetry));
        }

        if ($this->isManual !== null) {
            $this->subQuery->andWhere(['subscribr_subscriptions.isManual' => (bool)$this->isManual]);
        }

        if ($this->autoRenew !== null) {
            $this->subQuery->andWhere(['subscribr_subscriptions.autoRenew' => (bool)$this->autoRenew]);
        }

        if ($this->hasFailures !== null) {
            $this->subQuery->andWhere([
                $this->hasFailures ? '>' : '=',
                'subscribr_subscriptions.failureCount',
                0,
            ]);
        }

        if ($this->isPrepaid !== null) {
            $this->subQuery->andWhere([
                $this->isPrepaid ? '>' : '=',
                'subscribr_subscriptions.prepaidCyclesRemaining',
                0,
            ]);
        }

        if ($this->renewingWithin !== null) {
            $until = (new DateTime())->modify('+' . $this->renewingWithin . ' days');
            $this->subQuery->andWhere(['<=', 'subscribr_subscriptions.dateNextPayment', Db::prepareDateForDb($until)]);
            $this->subQuery->andWhere(['not', ['subscribr_subscriptions.dateNextPayment' => null]]);
            $this->subQuery->andWhere([
                'subscribr_subscriptions.status' => [
                    Subscription::STATUS_ACTIVE,
                    Subscription::STATUS_TRIALING,
                    Subscription::STATUS_CANCELED,
                ],
            ]);
        }

        if ($this->due) {
            $now = new DateTime();

            if ($this->dueLeadHours > 0) {
                $now->modify('+' . $this->dueLeadHours . ' hours');
            }

            // Ordered oldest-due first so a backlog is worked through in the order it accrued, not
            // in element-ID order — a subscriber who has been waiting three days should not be
            // overtaken by one who fell due this morning.
            $this->subQuery
                ->andWhere(['not', ['subscribr_subscriptions.dateNextPayment' => null]])
                ->andWhere(['<=', 'subscribr_subscriptions.dateNextPayment', Db::prepareDateForDb($now)])
                ->andWhere([
                    'subscribr_subscriptions.status' => [
                        Subscription::STATUS_ACTIVE,
                        Subscription::STATUS_TRIALING,
                        Subscription::STATUS_CANCELED,
                    ],
                ]);
        }

        return parent::beforePrepare();
    }

    protected function statusCondition(string $status): mixed
    {
        // The element index's status sources map onto the plugin's own status column rather than
        // Craft's enabled/disabled, because "past due" is not a kind of disabled.
        if (in_array($status, array_keys(Subscription::statuses()), true)) {
            return ['subscribr_subscriptions.status' => $status];
        }

        return parent::statusCondition($status);
    }

    private function _applyParam(string $column, mixed $value): void
    {
        if ($value !== null) {
            $this->subQuery->andWhere(Db::parseParam('subscribr_subscriptions.' . $column, $value));
        }
    }
}
