<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use DateTime;
use justinholtweb\subscribr\db\Table;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\errors\SubscribrException;
use justinholtweb\subscribr\events\SubscriptionEvent;
use justinholtweb\subscribr\helpers\Money;
use justinholtweb\subscribr\models\Item;
use justinholtweb\subscribr\models\LogEntry;
use justinholtweb\subscribr\models\Plan;
use justinholtweb\subscribr\Plugin;
use justinholtweb\subscribr\records\ItemRecord;
use justinholtweb\subscribr\records\SubscriptionOrderRecord;
use yii\base\Component;

/**
 * Subscriptions: the record, its items, its orders, and every transition between states.
 *
 * **Every state change goes through this service, and every one of them writes to the ledger.**
 * The CP, the portal, the console and the renewal engine all call the same methods, so there is no
 * path by which a subscription changes state without a line of history saying who changed it.
 */
class Subscriptions extends Component
{
    public const EVENT_BEFORE_ACTIVATE = 'beforeActivateSubscription';
    public const EVENT_AFTER_ACTIVATE = 'afterActivateSubscription';
    public const EVENT_AFTER_PAUSE = 'afterPauseSubscription';
    public const EVENT_AFTER_RESUME = 'afterResumeSubscription';
    public const EVENT_AFTER_CANCEL = 'afterCancelSubscription';
    public const EVENT_AFTER_EXPIRE = 'afterExpireSubscription';

    // Fetching
    // -------------------------------------------------------------------------

    public function getSubscriptionById(int $id): ?Subscription
    {
        return Subscription::find()->id($id)->status(null)->one();
    }

    public function getSubscriptionByReference(string $reference): ?Subscription
    {
        return Subscription::find()->reference($reference)->status(null)->one();
    }

    /** @return Subscription[] */
    public function getSubscriptionsForUser(int $userId, bool $liveOnly = false): array
    {
        $query = Subscription::find()->userId($userId)->status(null);

        if ($liveOnly) {
            $query->subscriptionStatus(Subscription::LIVE_STATUSES);
        }

        return $query->orderBy(['subscribr_subscriptions.dateNextPayment' => SORT_ASC])->all();
    }

    /**
     * The subscriptions a renewal sweep should look at.
     *
     * @return Subscription[]
     */
    public function getDue(int $limit = 50, int $leadHours = 0): array
    {
        return Subscription::find()
            ->status(null)
            ->due(true, $leadHours)
            ->limit($limit)
            ->all();
    }

    // Creating
    // -------------------------------------------------------------------------

    /**
     * Create a subscription.
     *
     * Created `pending` and started separately, because the two happen at different moments often
     * enough that folding them together would be wrong more than it was convenient: a gift is
     * created at purchase and started at the claim; a signup on a redirect gateway is created
     * before the payment and started by the webhook.
     *
     * @param Item[] $items
     */
    public function createSubscription(
        Plan $plan,
        ?int $userId,
        array $items,
        array $attributes = [],
    ): Subscription {
        $subscription = new Subscription();
        $subscription->setPlan($plan);
        $subscription->userId = $userId;
        $subscription->boxId = $plan->boxId;
        $subscription->currency = $attributes['currency'] ?? Money::storeCurrency($plan->storeId);
        $subscription->reference = Subscription::generateReference();
        $subscription->subscriptionStatus = Subscription::STATUS_PENDING;

        foreach ($attributes as $key => $value) {
            if ($subscription->canSetProperty($key)) {
                $subscription->$key = $value;
            }
        }

        if (!Craft::$app->getElements()->saveElement($subscription, false)) {
            throw new SubscribrException('Could not save the subscription: ' . Json::encode($subscription->getErrors()));
        }

        $this->saveItems($subscription, $items);
        $this->refreshRenewalPrice($subscription);

        Plugin::getInstance()->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_CREATED,
            Craft::t('subscribr', 'Subscription created on {plan}.', ['plan' => $plan->name]),
            ['planId' => $plan->id, 'items' => count($items)],
        );

        return $subscription;
    }

    /**
     * Start a subscription: set the clock running.
     *
     * `$startedAt` is when the period began, which is not always now — a renewal that was raised
     * early with `renewalLeadHours` still starts its period on the date that was due.
     */
    public function activate(Subscription $subscription, ?DateTime $startedAt = null): bool
    {
        $plan = $subscription->getPlan();

        if ($plan === null) {
            throw new SubscribrException('Cannot activate a subscription with no plan.');
        }

        $event = new SubscriptionEvent(['subscription' => $subscription]);
        $this->trigger(self::EVENT_BEFORE_ACTIVATE, $event);

        if (!$event->isValid) {
            return false;
        }

        $now = $startedAt ?? new DateTime();
        $subscription->dateStarted ??= $now;
        $subscription->dateCurrentPeriodStart = $now;

        if ($plan->getHasTrial() && $subscription->cycleCount === 0) {
            $subscription->dateTrialEnds = (clone $now)->modify('+' . $plan->trialDays . ' days');
            $subscription->dateNextPayment = $subscription->dateTrialEnds;
            $subscription->subscriptionStatus = Subscription::STATUS_TRIALING;
        } else {
            $subscription->dateNextPayment = $plan->getCadence()->next($now);
            $subscription->subscriptionStatus = Subscription::STATUS_ACTIVE;
        }

        $this->save($subscription);

        Plugin::getInstance()->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_ACTIVATED,
            $subscription->getIsOnTrial()
                ? Craft::t('subscribr', 'Trial started; first payment {date}.', ['date' => $subscription->dateNextPayment?->format('j M Y')])
                : Craft::t('subscribr', 'Subscription started; next payment {date}.', ['date' => $subscription->dateNextPayment?->format('j M Y')]),
        );

        $this->trigger(self::EVENT_AFTER_ACTIVATE, new SubscriptionEvent(['subscription' => $subscription]));

        return true;
    }

    // Transitions
    // -------------------------------------------------------------------------

    /**
     * Pause a subscription.
     *
     * **The clock stops; it does not accrue.** When it resumes, the next payment is a full cadence
     * away, not the date it would have been had the pause never happened. Anything else means a
     * subscriber who paused for three months comes back to three months of catching up, which is
     * the behaviour that makes people cancel instead of pausing — and a pause is a customer you
     * still have.
     */
    public function pause(Subscription $subscription, ?DateTime $until = null, ?string $reason = null): bool
    {
        if ($subscription->getIsPaused()) {
            return true;
        }

        $subscription->datePaused = new DateTime();
        $subscription->dateResumes = $until;
        $subscription->subscriptionStatus = Subscription::STATUS_PAUSED;
        $subscription->pausesUsed++;

        $this->save($subscription);

        Plugin::getInstance()->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_PAUSED,
            $until
                ? Craft::t('subscribr', 'Paused until {date}.', ['date' => $until->format('j M Y')])
                : Craft::t('subscribr', 'Paused indefinitely.'),
            array_filter(['reason' => $reason, 'until' => $until?->format(DATE_ATOM)]),
        );

        $this->trigger(self::EVENT_AFTER_PAUSE, new SubscriptionEvent(['subscription' => $subscription]));

        return true;
    }

    public function resume(Subscription $subscription): bool
    {
        if (!$subscription->getIsPaused() && $subscription->subscriptionStatus !== Subscription::STATUS_PAUSED) {
            return true;
        }

        $plan = $subscription->getPlan();
        $now = new DateTime();

        $subscription->datePaused = null;
        $subscription->dateResumes = null;
        $subscription->dateCurrentPeriodStart = $now;
        $subscription->subscriptionStatus = Subscription::STATUS_ACTIVE;
        // A full cadence from now. See the note on pause().
        $subscription->dateNextPayment = $plan?->getCadence()->next($now) ?? $now;

        $this->save($subscription);

        Plugin::getInstance()->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_RESUMED,
            Craft::t('subscribr', 'Resumed; next payment {date}.', ['date' => $subscription->dateNextPayment?->format('j M Y')]),
        );

        $this->trigger(self::EVENT_AFTER_RESUME, new SubscriptionEvent(['subscription' => $subscription]));

        return true;
    }

    /**
     * Cancel a subscription.
     *
     * Under the plan's default (`end`) this does **not** stop anything today. It stops the renewal
     * and lets the period the subscriber has already paid for run out. They keep what they bought;
     * the store keeps the money it was owed for it; nobody has to argue about a refund.
     *
     * `$immediately` overrides that, and is what a support agent uses when they have agreed to
     * end it now.
     */
    public function cancel(Subscription $subscription, ?string $reason = null, bool $immediately = false): bool
    {
        if ($subscription->getIsCanceled()) {
            return true;
        }

        $plan = $subscription->getPlan();
        $now = new DateTime();
        $immediately = $immediately || $plan?->cancelMode === Plan::CANCEL_IMMEDIATE;

        $subscription->dateCanceled = $now;
        $subscription->cancelReason = $reason;
        $subscription->autoRenew = false;

        if ($immediately) {
            $subscription->subscriptionStatus = Subscription::STATUS_EXPIRED;
            $subscription->dateEnds = $now;
            $subscription->dateEnded = $now;
            $subscription->dateNextPayment = null;
        } else {
            $subscription->subscriptionStatus = Subscription::STATUS_CANCELED;
            // The end of the period already paid for. A prepaid subscription runs to the end of
            // everything it prepaid, not to the end of the current cycle.
            $subscription->dateEnds = $this->_paidThrough($subscription);
            $subscription->dateNextPayment = null;
        }

        $this->save($subscription);

        Plugin::getInstance()->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_CANCELED,
            $immediately
                ? Craft::t('subscribr', 'Cancelled immediately.')
                : Craft::t('subscribr', 'Cancelled; runs until {date}.', ['date' => $subscription->dateEnds?->format('j M Y')]),
            array_filter(['reason' => $reason, 'immediate' => $immediately]),
        );

        $this->trigger(self::EVENT_AFTER_CANCEL, new SubscriptionEvent(['subscription' => $subscription]));

        return true;
    }

    /**
     * Undo a cancellation that has not run out yet.
     *
     * Only possible while the subscription is still `canceled` rather than `expired` — once it has
     * actually ended there is nothing to reactivate and the subscriber signs up again, which is a
     * new agreement at today's price rather than a resurrection of an old one.
     */
    public function uncancel(Subscription $subscription): bool
    {
        if ($subscription->getStatus() !== Subscription::STATUS_CANCELED) {
            return false;
        }

        $plan = $subscription->getPlan();

        $subscription->dateNextPayment = $subscription->dateEnds ?? $plan?->getCadence()->next(new DateTime());
        $subscription->dateCanceled = null;
        $subscription->dateEnds = null;
        $subscription->cancelReason = null;
        $subscription->autoRenew = true;
        $subscription->subscriptionStatus = Subscription::STATUS_ACTIVE;

        $this->save($subscription);

        Plugin::getInstance()->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_UNCANCELED,
            Craft::t('subscribr', 'Cancellation reversed; next payment {date}.', ['date' => $subscription->dateNextPayment?->format('j M Y')]),
        );

        return true;
    }

    public function expire(Subscription $subscription, ?string $reason = null): bool
    {
        if ($subscription->subscriptionStatus === Subscription::STATUS_EXPIRED) {
            return true;
        }

        $now = new DateTime();
        $subscription->subscriptionStatus = Subscription::STATUS_EXPIRED;
        $subscription->dateEnded = $now;
        $subscription->dateEnds ??= $now;
        $subscription->dateNextPayment = null;
        $subscription->dateNextRetry = null;

        $this->save($subscription);

        Plugin::getInstance()->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_EXPIRED,
            $reason ?? Craft::t('subscribr', 'Subscription ended.'),
        );

        $this->trigger(self::EVENT_AFTER_EXPIRE, new SubscriptionEvent(['subscription' => $subscription]));

        return true;
    }

    /**
     * Change the quantity of a running subscription.
     *
     * Takes effect at the next renewal rather than now, because the current period has been paid
     * for at the old quantity. A store that wants the change billed today does it as a plan switch
     * with proration, which is the same machinery and shows the customer the number first.
     */
    public function setQuantity(Subscription $subscription, int $quantity): bool
    {
        $quantity = max(1, $quantity);

        if ($quantity === $subscription->quantity) {
            return true;
        }

        $was = $subscription->quantity;
        $subscription->quantity = $quantity;
        $this->save($subscription);
        $this->refreshRenewalPrice($subscription);

        Plugin::getInstance()->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_QUANTITY,
            Craft::t('subscribr', 'Quantity changed from {was} to {now}, from the next renewal.', ['was' => $was, 'now' => $quantity]),
            ['from' => $was, 'to' => $quantity],
        );

        return true;
    }

    public function setPaymentSource(Subscription $subscription, int $paymentSourceId, ?int $gatewayId = null): bool
    {
        $subscription->paymentSourceId = $paymentSourceId;

        if ($gatewayId !== null) {
            $subscription->gatewayId = $gatewayId;
        }

        // Somebody fixing their card is the single most valuable event in dunning, and the retry
        // should not wait for the next scheduled stage to find out. Clearing the retry date makes
        // the very next sweep pick it up.
        if ($subscription->subscriptionStatus === Subscription::STATUS_PAST_DUE) {
            $subscription->dateNextRetry = new DateTime();
        }

        $this->save($subscription);

        Plugin::getInstance()->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_PAYMENT_SOURCE,
            Craft::t('subscribr', 'Payment method updated.'),
        );

        return true;
    }

    public function save(Subscription $subscription): bool
    {
        return Craft::$app->getElements()->saveElement($subscription, false);
    }

    // Items
    // -------------------------------------------------------------------------

    /**
     * The standing items — the ones with no cycle pinned to them.
     *
     * @return Item[]
     */
    public function getItems(int $subscriptionId): array
    {
        return $this->_itemModels(
            (new Query())->select('*')->from([Table::ITEMS])
                ->where(['subscriptionId' => $subscriptionId, 'cycle' => null])
                ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
                ->all()
        );
    }

    /**
     * What ships for one cycle: the cycle's own items if it has any, otherwise the standing ones.
     *
     * A swap is a *replacement*, not an addition — a subscriber who swapped one thing out of a
     * three-item box selects all three, and a partial swap that silently kept the other two would
     * make "swap my coffee for the decaf" ambiguous the first time somebody removed an item.
     *
     * @return Item[]
     */
    public function getItemsForCycle(int $subscriptionId, int $cycle): array
    {
        $pinned = $this->_itemModels(
            (new Query())->select('*')->from([Table::ITEMS])
                ->where(['subscriptionId' => $subscriptionId, 'cycle' => $cycle])
                ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
                ->all()
        );

        return $pinned !== [] ? $pinned : $this->getItems($subscriptionId);
    }

    /**
     * Replace a subscription's items.
     *
     * @param Item[] $items
     */
    public function saveItems(Subscription $subscription, array $items, ?int $cycle = null): void
    {
        $db = Craft::$app->getDb();

        $db->createCommand()->delete(Table::ITEMS, [
            'subscriptionId' => $subscription->id,
            'cycle' => $cycle,
        ])->execute();

        foreach ($items as $order => $item) {
            $record = new ItemRecord();
            $record->subscriptionId = $subscription->id;
            $record->purchasableId = $item->purchasableId;
            $record->boxSlotId = $item->boxSlotId;
            $record->cycle = $cycle;
            $record->qty = $item->qty;
            $record->price = $item->price;
            $record->description = $item->description;
            $record->sku = $item->sku;
            $record->options = $item->getOptions() === [] ? null : Json::encode($item->getOptions());
            $record->snapshot = $item->getSnapshot() === [] ? null : Json::encode($item->getSnapshot());
            $record->sortOrder = $item->sortOrder ?? $order;
            $record->save(false);

            $item->id = (int)$record->id;
        }

        $subscription->setItems($cycle === null ? $items : $subscription->getItems());
    }

    /**
     * Recompute and store the cached renewal price.
     *
     * The column exists so the element index and the MRR figures do not have to sum the items
     * table per row. It is a cache, and `getRenewalSubtotal()` is the truth.
     */
    public function refreshRenewalPrice(Subscription $subscription): float
    {
        $price = $subscription->getRenewalSubtotal();

        if (abs($price - $subscription->renewalPrice) > 0.0001) {
            $subscription->renewalPrice = $price;
            $this->save($subscription);
        }

        return $price;
    }

    // Orders
    // -------------------------------------------------------------------------

    public function linkOrder(Subscription $subscription, Order $order, string $kind, int $cycle, bool $fromPrepaid = false): void
    {
        $record = new SubscriptionOrderRecord();
        $record->subscriptionId = $subscription->id;
        $record->orderId = $order->id;
        $record->kind = $kind;
        $record->cycle = $cycle;
        $record->isPaidFromPrepaid = $fromPrepaid;
        $record->save(false);
    }

    /** @return Order[] */
    public function getOrders(int $subscriptionId): array
    {
        $ids = (new Query())
            ->select(['orderId'])
            ->from([Table::ORDERS])
            ->where(['subscriptionId' => $subscriptionId])
            ->orderBy(['cycle' => SORT_DESC, 'id' => SORT_DESC])
            ->column();

        if ($ids === []) {
            return [];
        }

        return Order::find()->id(array_map('intval', $ids))->status(null)->fixedOrder(true)->all();
    }

    /**
     * The subscriptions an order produced or renewed.
     *
     * @return Subscription[]
     */
    public function getSubscriptionsForOrder(int $orderId): array
    {
        $ids = (new Query())
            ->select(['subscriptionId'])
            ->from([Table::ORDERS])
            ->where(['orderId' => $orderId])
            ->column();

        if ($ids === []) {
            return [];
        }

        return Subscription::find()->id(array_map('intval', $ids))->status(null)->all();
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * The end of the period the subscriber has already paid for.
     *
     * Prepaid cycles count. Someone who paid for six months and cancels in month two keeps four
     * more months, and a cancellation that ended it at the boundary of the *current* cycle would
     * be keeping four months of their money.
     */
    private function _paidThrough(Subscription $subscription): ?DateTime
    {
        $plan = $subscription->getPlan();

        if ($plan === null) {
            return $subscription->dateNextPayment;
        }

        $through = $subscription->dateNextPayment ?? new DateTime();

        for ($i = 0; $i < $subscription->prepaidCyclesRemaining; $i++) {
            $through = $plan->getCadence()->next($through);
        }

        return $through;
    }

    /** @return Item[] */
    private function _itemModels(array $rows): array
    {
        return array_map(static function (array $row): Item {
            $item = new Item();
            $item->id = (int)$row['id'];
            $item->subscriptionId = (int)$row['subscriptionId'];
            $item->purchasableId = isset($row['purchasableId']) ? (int)$row['purchasableId'] : null;
            $item->boxSlotId = isset($row['boxSlotId']) ? (int)$row['boxSlotId'] : null;
            $item->cycle = isset($row['cycle']) ? (int)$row['cycle'] : null;
            $item->qty = (int)$row['qty'];
            $item->price = (float)$row['price'];
            $item->description = $row['description'] ?? null;
            $item->sku = $row['sku'] ?? null;
            $item->setOptions($row['options'] ?? null);
            $item->setSnapshot($row['snapshot'] ?? null);
            $item->sortOrder = isset($row['sortOrder']) ? (int)$row['sortOrder'] : null;
            $item->dateCreated = isset($row['dateCreated']) ? DateTimeHelper::toDateTime($row['dateCreated']) ?: null : null;
            $item->uid = $row['uid'] ?? null;

            return $item;
        }, $rows);
    }
}
