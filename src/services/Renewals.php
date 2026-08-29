<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use DateTime;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\events\RenewalEvent;
use justinholtweb\subscribr\models\Attempt;
use justinholtweb\subscribr\models\LogEntry;
use justinholtweb\subscribr\models\Plan;
use justinholtweb\subscribr\models\RenewalResult;
use justinholtweb\subscribr\models\ScheduledAction;
use justinholtweb\subscribr\Plugin;
use Throwable;
use yii\base\Component;

/**
 * The renewal engine.
 *
 * **`renew()` is the only place a cycle advances.** The console, the queue, the CP's "renew now"
 * button and the recovery of a manually-paid invoice all come through here, so a preview of what
 * will happen and what actually happens cannot disagree.
 *
 * ## A renewal is a real Commerce order
 *
 * Not a bespoke invoice, not a row in a Subscribr table. A real `craft\commerce\elements\Order`,
 * with real line items. That single decision buys tax, shipping, discounts, coupons, order
 * statuses, emails, PDFs, the order index, reporting, refunds and every Commerce plugin the store
 * already has — none of which Subscribr then has to reimplement, and all of which would otherwise
 * be subtly wrong for subscription revenue.
 *
 * ## Where the schedule comes from
 *
 * The next payment date is computed from the date that **was due**, never from `now`. A sweep that
 * runs at 03:07 because the queue was busy must not move a subscriber's billing date seven minutes
 * later every month until it has walked around the clock.
 */
class Renewals extends Component
{
    public const EVENT_BEFORE_RENEW = 'beforeRenew';
    public const EVENT_AFTER_RENEW = 'afterRenew';

    /**
     * Renew everything that is due.
     *
     * @return RenewalResult[]
     */
    public function runDue(?int $limit = null, int $leadHours = 0): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $limit ??= $settings->renewalBatchSize;
        $leadHours = $leadHours ?: $settings->renewalLeadHours;

        $results = [];

        foreach (Plugin::getInstance()->getSubscriptions()->getDue($limit, $leadHours) as $subscription) {
            try {
                $results[] = $this->renew($subscription);
            } catch (Throwable $e) {
                // One broken subscription must not end a run of five hundred. Recorded against the
                // subscription so it is findable, and the sweep carries on.
                Craft::error(
                    sprintf('Renewal of subscription %d failed: %s', $subscription->id, $e->getMessage()),
                    __METHOD__,
                );

                Plugin::getInstance()->getLedger()->log(
                    (int)$subscription->id,
                    LogEntry::TYPE_PAYMENT_FAILED,
                    Craft::t('subscribr', 'Renewal could not be attempted: {message}', ['message' => $e->getMessage()]),
                );

                $results[] = RenewalResult::make(RenewalResult::ERROR, (int)$subscription->id, $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Push the due subscriptions onto the queue, one job each.
     *
     * The right shape for a store with thousands of subscribers: the expensive, failure-prone part
     * is talking to a gateway, and one job per subscription means a retry re-attempts exactly one
     * cycle instead of re-charging everyone the sweep had already billed.
     */
    public function queueDue(?int $limit = null, int $leadHours = 0): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $subscriptions = Plugin::getInstance()->getSubscriptions()->getDue(
            $limit ?? $settings->renewalBatchSize,
            $leadHours ?: $settings->renewalLeadHours,
        );

        foreach ($subscriptions as $subscription) {
            Craft::$app->getQueue()->push(new \justinholtweb\subscribr\queue\jobs\RenewSubscription([
                'subscriptionId' => (int)$subscription->id,
            ]));
        }

        return count($subscriptions);
    }

    /**
     * Renew one subscription.
     */
    public function renew(Subscription $subscription, ?DateTime $now = null, bool $force = false): RenewalResult
    {
        $now ??= new DateTime();
        $settings = Plugin::getInstance()->getSettings();
        $plan = $subscription->getPlan();

        if ($plan === null) {
            return RenewalResult::make(RenewalResult::ERROR, (int)$subscription->id, 'The plan this subscription was on no longer exists.');
        }

        if (!$force && !$subscription->getIsDue($now, $settings->renewalLeadHours)) {
            return RenewalResult::make(RenewalResult::NOT_DUE, (int)$subscription->id);
        }

        // The date that was actually due. Everything downstream is computed from this, not `now`.
        $due = $subscription->dateNextPayment ?? $now;

        if ($result = $this->_checkTermination($subscription, $plan, $due, $now)) {
            return $result;
        }

        if ($result = $this->_applyScheduledActions($subscription, $plan, $due, $now)) {
            return $result;
        }

        if (!$force && ($result = $this->_checkCatchUp($subscription, $plan, $due, $now))) {
            return $result;
        }

        $cycle = $subscription->cycleCount + 1;

        $order = $this->buildRenewalOrder($subscription, $cycle);

        $event = new RenewalEvent([
            'subscription' => $subscription,
            'order' => $order,
            'cycle' => $cycle,
        ]);
        $this->trigger(self::EVENT_BEFORE_RENEW, $event);

        if (!$event->isValid) {
            // A veto, not a cancellation: the schedule is untouched and the next sweep tries again.
            Craft::$app->getElements()->deleteElement($order, true);

            return RenewalResult::make(RenewalResult::NOT_DUE, (int)$subscription->id, 'Renewal vetoed by an event handler.');
        }

        $result = $subscription->prepaidCyclesRemaining > 0
            ? $this->_fulfilPrepaid($subscription, $order, $cycle, $due)
            : $this->_takePayment($subscription, $order, $cycle, $due);

        $this->trigger(self::EVENT_AFTER_RENEW, new RenewalEvent([
            'subscription' => $subscription,
            'order' => $result->order,
            'cycle' => $cycle,
            'result' => $result,
        ]));

        return $result;
    }

    /**
     * Build the order for a cycle, without paying it.
     *
     * Public because the CP preview uses it to show a merchant exactly what the next renewal will
     * charge, tax and shipping included — computed by Commerce, not estimated by Subscribr.
     * The caller owns the order it gets back.
     */
    public function buildRenewalOrder(Subscription $subscription, int $cycle): Order
    {
        $commerce = Commerce::getInstance();
        $plan = $subscription->getPlan();
        $subscriber = $subscription->getSubscriber();

        $order = new Order();
        $order->number = $commerce->getCarts()->generateCartNumber();
        $order->currency = $subscription->currency;
        $order->paymentCurrency = $subscription->currency;
        $order->orderLanguage = Craft::$app->language;
        $order->origin = Order::ORIGIN_REMOTE;

        if ($subscriber !== null) {
            $order->setCustomer($subscriber);
            $order->email = $subscriber->email;
        }

        if (!Craft::$app->getElements()->saveElement($order, false)) {
            throw new \RuntimeException('Could not create the renewal order.');
        }

        // Addresses are copied rather than referenced: an address element belongs to the customer
        // and they are free to edit or delete it, and a two-year-old order that silently changes
        // where it was shipped is not a record of anything.
        if ($address = $subscription->getShippingAddress()) {
            $order->setShippingAddress($address->toArray(['addressLine1', 'addressLine2', 'addressLine3', 'locality', 'administrativeArea', 'postalCode', 'countryCode', 'fullName', 'organization']));
        }

        if ($address = $subscription->getBillingAddress()) {
            $order->setBillingAddress($address->toArray(['addressLine1', 'addressLine2', 'addressLine3', 'locality', 'administrativeArea', 'postalCode', 'countryCode', 'fullName', 'organization']));
        }

        $items = Plugin::getInstance()->getBoxes()->contentsForCycle($subscription, $cycle);
        $lineItems = [];

        foreach ($items as $item) {
            if (!$item->purchasableId) {
                continue;
            }

            try {
                $lineItem = $commerce->getLineItems()->createLineItem(
                    $order,
                    $item->purchasableId,
                    array_merge($item->getOptions(), [
                        'subscribrSubscription' => $subscription->reference,
                        'subscribrCycle' => $cycle,
                    ]),
                    $item->qty * max(1, $subscription->quantity),
                );
            } catch (Throwable $e) {
                // The purchasable was deleted or is no longer available. The cycle still bills —
                // the subscriber agreed to a price, and the row carries its own description — but
                // it is recorded so somebody can fix the catalogue.
                Plugin::getInstance()->getLedger()->log(
                    (int)$subscription->id,
                    LogEntry::TYPE_NOTE,
                    Craft::t('subscribr', 'Item “{item}” is no longer purchasable and was left off cycle {cycle}.', [
                        'item' => $item->description ?? $item->sku ?? '?',
                        'cycle' => $cycle,
                    ]),
                    ['purchasableId' => $item->purchasableId, 'error' => $e->getMessage()],
                );

                continue;
            }

            // The plan's price wins over the catalogue's, unless the plan says to inherit it.
            if ($plan !== null && $plan->pricingMode !== Plan::PRICING_INHERIT) {
                $lineItem->setPrice($plan->priceFor($item->price, $item->price));
                $lineItem->setPromotionalPrice(null);
            } elseif ($plan !== null && $plan->discountPercent) {
                $lineItem->setPromotionalPrice($plan->priceFor((float)$lineItem->getPrice()));
            }

            $lineItems[] = $lineItem;
        }

        $order->setLineItems($lineItems);

        if ($plan?->shippable && $method = $this->_firstShippingMethod($order)) {
            $order->shippingMethodHandle = $method;
        }

        $order->recalculate();
        Craft::$app->getElements()->saveElement($order, false);

        return $order;
    }

    /**
     * Recover a manual renewal that the customer has now paid.
     *
     * Called from Commerce's order-complete event, which is what fires when they finish the
     * checkout the payment link dropped them into.
     */
    public function completeManualRenewal(Order $order): ?RenewalResult
    {
        $link = (new \craft\db\Query())
            ->select(['subscriptionId', 'cycle'])
            ->from([\justinholtweb\subscribr\db\Table::ORDERS])
            ->where(['orderId' => $order->id])
            ->one();

        if (!$link) {
            return null;
        }

        $subscription = Plugin::getInstance()->getSubscriptions()->getSubscriptionById((int)$link['subscriptionId']);

        if ($subscription === null || (int)$link['cycle'] <= $subscription->cycleCount) {
            // Already advanced. A customer who pays a link twice, or a webhook that arrives after
            // the sweep has already recovered the cycle, must not push the schedule forward twice.
            return null;
        }

        $due = $subscription->dateNextPayment ?? new DateTime();

        Plugin::getInstance()->getBilling()->recordNonPayment($subscription, $order, Attempt::SUCCESS, 'Paid by the subscriber from a renewal link.');

        $this->_advance($subscription, $order, (int)$link['cycle'], $due);

        Plugin::getInstance()->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_PAYMENT_RECOVERED,
            Craft::t('subscribr', 'Renewal {ref} paid by the subscriber.', ['ref' => $order->reference ?? $order->number]),
            ['orderId' => $order->id],
        );

        return new RenewalResult([
            'outcome' => RenewalResult::RENEWED,
            'subscriptionId' => (int)$subscription->id,
            'order' => $order,
            'cycle' => (int)$link['cycle'],
            'amount' => (float)$order->getTotalPrice(),
            'currency' => $order->currency,
            'nextPaymentDate' => $subscription->dateNextPayment,
        ]);
    }

    // Steps
    // -------------------------------------------------------------------------

    /**
     * Has this subscription simply run out?
     */
    private function _checkTermination(Subscription $subscription, Plan $plan, DateTime $due, DateTime $now): ?RenewalResult
    {
        $subscriptions = Plugin::getInstance()->getSubscriptions();

        if ($subscription->subscriptionStatus === Subscription::STATUS_CANCELED) {
            // A cancelled subscription still delivers what was paid for, and then stops. It never
            // renews: reaching a renewal date at all means the paid-for period is over.
            $subscriptions->expire($subscription, Craft::t('subscribr', 'Cancelled subscription reached the end of its paid period.'));

            return RenewalResult::make(RenewalResult::ENDED, (int)$subscription->id, 'Cancelled subscription ended.');
        }

        if ($plan->maxCycles > 0 && $subscription->cycleCount >= $plan->maxCycles) {
            $subscriptions->expire($subscription, Craft::t('subscribr', 'Completed all {n} cycles of the plan.', ['n' => $plan->maxCycles]));

            return RenewalResult::make(RenewalResult::ENDED, (int)$subscription->id, 'Plan term completed.');
        }

        if (!$subscription->autoRenew && $subscription->prepaidCyclesRemaining < 1) {
            $subscriptions->expire($subscription, Craft::t('subscribr', 'Renewal is switched off and there are no prepaid cycles left.'));

            return RenewalResult::make(RenewalResult::ENDED, (int)$subscription->id, 'Auto-renew is off.');
        }

        return null;
    }

    /**
     * Apply anything the subscriber booked for this boundary.
     *
     * Returns a result when the booked action *replaces* the renewal — a skip, a pause, a
     * cancellation. A swap, a switch or a quantity change changes what is billed and then the
     * renewal carries on.
     */
    private function _applyScheduledActions(Subscription $subscription, Plan $plan, DateTime $due, DateTime $now): ?RenewalResult
    {
        $schedules = Plugin::getInstance()->getSchedules();
        $cycle = $subscription->cycleCount + 1;

        foreach ($schedules->getDue($subscription, $cycle, $now) as $action) {
            switch ($action->action) {
                case ScheduledAction::SKIP:
                    $schedules->markApplied($action);
                    $subscription->skipsUsed++;
                    $this->_advanceSchedule($subscription, $plan, $due);
                    Plugin::getInstance()->getSubscriptions()->save($subscription);

                    Plugin::getInstance()->getLedger()->log(
                        (int)$subscription->id,
                        LogEntry::TYPE_SKIPPED,
                        Craft::t('subscribr', 'Cycle {cycle} skipped; next payment {date}.', [
                            'cycle' => $cycle,
                            'date' => $subscription->dateNextPayment?->format('j M Y'),
                        ]),
                    );

                    return RenewalResult::make(RenewalResult::SKIPPED, (int)$subscription->id);

                case ScheduledAction::PAUSE:
                    $schedules->markApplied($action);
                    $until = isset($action->getPayload()['until'])
                        ? new DateTime($action->getPayload()['until'])
                        : null;
                    Plugin::getInstance()->getSubscriptions()->pause($subscription, $until);

                    return RenewalResult::make(RenewalResult::PAUSED, (int)$subscription->id);

                case ScheduledAction::CANCEL:
                    $schedules->markApplied($action);
                    Plugin::getInstance()->getSubscriptions()->cancel($subscription, $action->getPayload()['reason'] ?? null, true);

                    return RenewalResult::make(RenewalResult::ENDED, (int)$subscription->id);

                default:
                    // Swap, switch, quantity, address: they change the cycle rather than replace
                    // it, and the renewal continues with the new shape.
                    $schedules->apply($subscription, $action);
                    break;
            }
        }

        return null;
    }

    /**
     * Guard against a queue that was off for a month.
     *
     * Turning it back on must not bill a subscriber for every cycle they missed. Past the catch-up
     * window the schedule is moved forward to the next real boundary, once, and the missed cycles
     * are recorded rather than charged.
     */
    private function _checkCatchUp(Subscription $subscription, Plan $plan, DateTime $due, DateTime $now): ?RenewalResult
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->billMissedCycles || $settings->renewalCatchUpDays <= 0) {
            return null;
        }

        $deadline = (clone $due)->modify('+' . $settings->renewalCatchUpDays . ' days');

        if ($now <= $deadline) {
            return null;
        }

        $skipped = 0;
        $cadence = $plan->getCadence();
        $next = $due;

        while ($next < $now && $skipped < 500) {
            $next = $cadence->next($next);
            $skipped++;
        }

        $subscription->dateCurrentPeriodStart = $now;
        $subscription->dateNextPayment = $next;
        Plugin::getInstance()->getSubscriptions()->save($subscription);

        Plugin::getInstance()->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_NOTE,
            Craft::t('subscribr', '{n} missed {n, plural, =1{cycle} other{cycles}} were not billed; the schedule moved to {date}. Renewals had not run since {due}.', [
                'n' => $skipped,
                'date' => $next->format('j M Y'),
                'due' => $due->format('j M Y'),
            ]),
            ['missedCycles' => $skipped],
        );

        return RenewalResult::make(RenewalResult::ABANDONED, (int)$subscription->id, 'Missed cycles were skipped rather than billed.');
    }

    /**
     * A prepaid cycle: there is a shipment, but the money was taken months ago.
     */
    private function _fulfilPrepaid(Subscription $subscription, Order $order, int $cycle, DateTime $due): RenewalResult
    {
        // Zeroed rather than left at its catalogue price. The order exists so the warehouse has
        // something to pick and the customer has something to look at, and a £30 order with no
        // payment against it would show up in every revenue report the store has.
        foreach ($order->getLineItems() as $lineItem) {
            $lineItem->setPrice(0);
            $lineItem->setPromotionalPrice(null);
        }

        // The shipping method goes too, and this is a deliberate decision rather than an oversight:
        // a prepaid cycle must cost **nothing**, or it is not prepaid and somebody has to be asked
        // for money for a delivery they were told was already paid for. Prepaid pricing is expected
        // to cover the whole term, delivery included — which is the same arrangement as any other
        // "six months for the price of five" offer.
        $order->shippingMethodHandle = null;

        // Adjustments only. A full recalculation calls `LineItem::refresh()`, which restores every
        // price from the catalogue — so zeroing the lines and then recalculating quietly puts the
        // money back, and a prepaid cycle arrives as a bill.
        $order->setRecalculationMode(Order::RECALCULATION_MODE_ADJUSTMENTS_ONLY);
        $order->recalculate();
        Craft::$app->getElements()->saveElement($order, false);
        $order->markAsComplete();

        $subscription->prepaidCyclesRemaining--;

        Plugin::getInstance()->getBilling()->recordNonPayment($subscription, $order, Attempt::SKIPPED, 'Fulfilled from a prepaid cycle.');
        $this->_advance($subscription, $order, $cycle, $due, 'prepaid', true);

        Plugin::getInstance()->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_PREPAID_DRAWN,
            Craft::t('subscribr', 'Cycle {cycle} shipped from prepaid credit; {n} left.', [
                'cycle' => $cycle,
                'n' => $subscription->prepaidCyclesRemaining,
            ]),
        );

        return new RenewalResult([
            'outcome' => RenewalResult::PREPAID,
            'subscriptionId' => (int)$subscription->id,
            'order' => $order,
            'cycle' => $cycle,
            'amount' => 0.0,
            'currency' => $subscription->currency,
            'nextPaymentDate' => $subscription->dateNextPayment,
        ]);
    }

    private function _takePayment(Subscription $subscription, Order $order, int $cycle, DateTime $due): RenewalResult
    {
        $billing = Plugin::getInstance()->getBilling();

        if (!$billing->canChargeAutomatically($subscription)) {
            return $this->_handOffToManual($subscription, $order, $cycle, $due, $billing->recordNonPayment(
                $subscription,
                $order,
                Attempt::MANUAL,
                'This gateway cannot charge without the customer, so the renewal was invoiced.',
            ));
        }

        // Completed *before* the charge. Commerce's payment machinery works on completed orders,
        // and an order that is charged and then completed has a window in which it is paid but not
        // an order — which is the window a webhook arrives in.
        $order->markAsComplete();
        $order = Order::find()->id($order->id)->status(null)->one() ?? $order;

        $attempt = $billing->charge($subscription, $order, $subscription->dunningStage);

        if ($attempt->outcome === Attempt::SUCCESS) {
            $this->_advance($subscription, $order, $cycle, $due);
            $this->_applyRenewalStatus($order);

            Plugin::getInstance()->getLedger()->log(
                (int)$subscription->id,
                LogEntry::TYPE_RENEWED,
                Craft::t('subscribr', 'Cycle {cycle} renewed for {amount}; next payment {date}.', [
                    'cycle' => $cycle,
                    'amount' => \justinholtweb\subscribr\helpers\Money::format((float)$order->getTotalPrice(), $order->currency),
                    'date' => $subscription->dateNextPayment?->format('j M Y'),
                ]),
                ['orderId' => $order->id, 'attemptId' => $attempt->id],
            );

            return new RenewalResult([
                'outcome' => RenewalResult::RENEWED,
                'subscriptionId' => (int)$subscription->id,
                'order' => $order,
                'cycle' => $cycle,
                'amount' => (float)$order->getTotalPrice(),
                'currency' => $order->currency,
                'nextPaymentDate' => $subscription->dateNextPayment,
                'attempt' => $attempt,
            ]);
        }

        if (in_array($attempt->outcome, [Attempt::MANUAL, Attempt::REDIRECT], true)) {
            return $this->_handOffToManual($subscription, $order, $cycle, $due, $attempt);
        }

        // A genuine decline. Dunning owns the subscription from here, and the schedule does not
        // move — the cycle is still owed.
        Plugin::getInstance()->getSubscriptions()->linkOrder($subscription, $order, 'renewal', $cycle);
        Plugin::getInstance()->getDunning()->recordFailure($subscription, $attempt, $order);

        return new RenewalResult([
            'outcome' => RenewalResult::FAILED,
            'subscriptionId' => (int)$subscription->id,
            'order' => $order,
            'cycle' => $cycle,
            'amount' => (float)$order->getTotalPrice(),
            'currency' => $order->currency,
            'message' => $attempt->message,
            'attempt' => $attempt,
        ]);
    }

    /**
     * Invoice the cycle instead of charging it.
     *
     * The order is left **incomplete** on purpose: an incomplete order is a cart, and a cart has a
     * load-cart URL, which drops the subscriber straight into the store's own checkout with the
     * renewal already in it. No bespoke payment page, no second checkout to keep working.
     *
     * The schedule advances, because the subscriber has been invoiced and the delivery is on its
     * way; if they never pay, dunning cancels them on the same timetable as a declined card.
     */
    private function _handOffToManual(Subscription $subscription, Order $order, int $cycle, DateTime $due, Attempt $attempt): RenewalResult
    {
        $subscription->isManual = true;
        $this->_advance($subscription, $order, $cycle, $due, 'manual');

        Plugin::getInstance()->getDunning()->recordFailure($subscription, $attempt, $order, false);

        Plugin::getInstance()->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_NOTE,
            Craft::t('subscribr', 'Cycle {cycle} invoiced for the subscriber to pay: {reason}', [
                'cycle' => $cycle,
                'reason' => $attempt->message ?? '',
            ]),
            ['orderId' => $order->id],
        );

        Plugin::getInstance()->getNotifications()->sendManualRenewal($subscription, $order);

        return new RenewalResult([
            'outcome' => RenewalResult::MANUAL,
            'subscriptionId' => (int)$subscription->id,
            'order' => $order,
            'cycle' => $cycle,
            'amount' => (float)$order->getTotalPrice(),
            'currency' => $order->currency,
            'nextPaymentDate' => $subscription->dateNextPayment,
            'message' => $attempt->message,
            'attempt' => $attempt,
        ]);
    }

    // Schedule
    // -------------------------------------------------------------------------

    private function _advance(
        Subscription $subscription,
        Order $order,
        int $cycle,
        DateTime $due,
        string $kind = 'renewal',
        bool $fromPrepaid = false,
    ): void {
        $plan = $subscription->getPlan();

        $subscription->cycleCount = $cycle;
        $subscription->dateLastPayment = new DateTime();
        $subscription->failureCount = 0;
        $subscription->dunningStage = 0;
        $subscription->dateNextRetry = null;
        $subscription->lastFailureMessage = null;

        if ($subscription->subscriptionStatus !== Subscription::STATUS_CANCELED) {
            $subscription->subscriptionStatus = Subscription::STATUS_ACTIVE;
        }

        if ($plan !== null) {
            $this->_advanceSchedule($subscription, $plan, $due);
        }

        // The cycle's own items have been consumed; a swap is for one shipment, not for ever.
        Plugin::getInstance()->getSubscriptions()->saveItems($subscription, [], $cycle);

        Plugin::getInstance()->getSubscriptions()->save($subscription);
        Plugin::getInstance()->getSubscriptions()->linkOrder($subscription, $order, $kind, $cycle, $fromPrepaid);
        Plugin::getInstance()->getSubscriptions()->refreshRenewalPrice($subscription);
    }

    /**
     * Move the dates on, from the date that was due.
     */
    private function _advanceSchedule(Subscription $subscription, Plan $plan, DateTime $due): void
    {
        $subscription->dateCurrentPeriodStart = $due;
        $subscription->dateNextPayment = $plan->getCadence()->next($due);

        if ($plan->maxCycles > 0 && $subscription->cycleCount >= $plan->maxCycles) {
            // The last cycle of a fixed-term plan. Left with an end date rather than a next
            // payment, so it stops rather than being charged one more time.
            $subscription->dateEnds = $subscription->dateNextPayment;
            $subscription->dateNextPayment = null;
        }
    }

    private function _applyRenewalStatus(Order $order): void
    {
        $handle = Plugin::getInstance()->getSettings()->renewalOrderStatus;

        if (!$handle) {
            return;
        }

        $status = Commerce::getInstance()->getOrderStatuses()->getOrderStatusByHandle($handle, $order->storeId);

        if ($status === null || (int)$order->orderStatusId === (int)$status->id) {
            return;
        }

        $order->orderStatusId = (int)$status->id;
        Craft::$app->getElements()->saveElement($order, false);
    }

    private function _firstShippingMethod(Order $order): ?string
    {
        try {
            $options = Commerce::getInstance()->getShippingMethods()->getMatchingShippingMethods($order);
        } catch (Throwable) {
            return null;
        }

        $first = reset($options);

        return $first ? $first->getHandle() : null;
    }
}
