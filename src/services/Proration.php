<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\services;

use Craft;
use craft\commerce\elements\Order;
use DateTime;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\events\ProrationEvent;
use justinholtweb\subscribr\helpers\Money;
use justinholtweb\subscribr\models\Attempt;
use justinholtweb\subscribr\models\LogEntry;
use justinholtweb\subscribr\models\Plan;
use justinholtweb\subscribr\models\Proration as ProrationModel;
use justinholtweb\subscribr\models\ScheduledAction;
use justinholtweb\subscribr\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Mid-cycle plan changes, and the arithmetic behind them.
 *
 * **`preview()` is the only place the numbers are computed**, and `applySwitch()` calls it. The
 * confirmation screen, the portal's "here's what you'll pay" panel, the CP's preview and the
 * charge that is actually taken are the same object, so a subscriber cannot be shown £12.40 and
 * billed £13.10.
 *
 * ## How the credit is worked out
 *
 * Time-based, on whole days, against the current period:
 *
 *     credit = oldCycleAmount × (daysRemaining ÷ daysInPeriod)
 *     charge = newCycleAmount × (daysRemaining ÷ daysInPeriod)
 *     due    = charge − credit
 *
 * Whole days rather than seconds, because a customer can check whole days and a proration nobody
 * can check is a support ticket. The day the change happens counts as used — they had the old plan
 * for part of it.
 *
 * ## When the store owes money
 *
 * A downgrade produces a negative total. Subscribr does **not** refund it automatically: an
 * automatic refund to a card is a real movement of money that a merchant should authorise, and
 * plenty of downgrades are followed by an upgrade a week later. The credit is carried and applied
 * to the next renewal, which is stated plainly in the preview so nobody is surprised.
 */
class Proration extends Component
{
    public const EVENT_AFTER_PRORATE = 'afterProrate';

    /**
     * What moving to `$newPlan` would cost, right now.
     */
    public function preview(Subscription $subscription, Plan $newPlan, ?DateTime $now = null): ProrationModel
    {
        $now ??= new DateTime();
        $oldPlan = $subscription->getPlan();

        $proration = new ProrationModel([
            'currency' => $subscription->currency,
            'oldPlan' => $oldPlan,
            'newPlan' => $newPlan,
            'effectiveDate' => $now,
            'mode' => $oldPlan?->switchMode === Plan::SWITCH_END
                ? ProrationModel::MODE_END
                : ProrationModel::MODE_IMMEDIATE,
        ]);

        $periodStart = $subscription->dateCurrentPeriodStart ?? $subscription->dateStarted ?? $now;
        $periodEnd = $subscription->dateNextPayment ?? $oldPlan?->getCadence()->next($periodStart) ?? $now;

        $proration->periodStart = $periodStart;
        $proration->periodEnd = $periodEnd;
        $proration->daysInPeriod = max(1, (int)$periodStart->diff($periodEnd)->days);
        $proration->daysRemaining = max(0, (int)$now->diff($periodEnd)->days);

        if ($periodEnd < $now) {
            $proration->daysRemaining = 0;
        }

        $proration->daysUsed = max(0, $proration->daysInPeriod - $proration->daysRemaining);

        $proration->oldCycleAmount = $subscription->getRenewalSubtotal();
        $proration->newCycleAmount = $this->_amountOnPlan($subscription, $newPlan);

        // A subscription still in its trial has paid nothing, so there is nothing to credit and
        // nothing to charge: the switch is free and the trial carries on. Charging a proration
        // against a trial is one of the most common ways to lose a customer on day three.
        if ($subscription->getIsOnTrial()) {
            $proration->mode = ProrationModel::MODE_IMMEDIATE;
            $proration->addLine(Craft::t('subscribr', 'Still on trial — nothing to pay today'), 0.0);
            $proration->addLine(
                Craft::t('subscribr', 'From {date}, {amount} {cadence}', [
                    'date' => $periodEnd->format('j M Y'),
                    'amount' => Money::format($proration->newCycleAmount, $subscription->currency),
                    'cadence' => $newPlan->getCadence()->describe(),
                ]),
                0.0,
            );

            $this->_afterProrate($subscription, $proration);

            return $proration;
        }

        if ($proration->mode === ProrationModel::MODE_END) {
            // Nothing changes hands now; the new price simply starts at the boundary.
            $proration->effectiveDate = $periodEnd;
            $proration->addLine(
                Craft::t('subscribr', 'Change takes effect {date}', ['date' => $periodEnd->format('j M Y')]),
                0.0,
                Craft::t('subscribr', 'You keep {plan} until then.', ['plan' => $oldPlan?->name ?? '']),
            );
            $proration->addLine(
                Craft::t('subscribr', 'Then {amount} {cadence}', [
                    'amount' => Money::format($proration->newCycleAmount, $subscription->currency),
                    'cadence' => $newPlan->getCadence()->describe(),
                ]),
                0.0,
            );

            $this->_afterProrate($subscription, $proration);

            return $proration;
        }

        $fraction = $proration->daysInPeriod > 0
            ? $proration->daysRemaining / $proration->daysInPeriod
            : 0.0;

        // Prepaid cycles are *not* prorated away. They are cycles the subscriber has bought and
        // still owns; the switch changes what arrives in them, and the difference is settled at
        // the end of the prepaid run rather than clawed back now.
        $proration->credit = Money::round($proration->oldCycleAmount * $fraction);
        $proration->charge = Money::round($proration->newCycleAmount * $fraction);

        $proration->addLine(
            Craft::t('subscribr', 'Unused time on {plan}', ['plan' => $oldPlan?->name ?? '']),
            -$proration->credit,
            Craft::t('subscribr', '{n} of {total} days remaining', [
                'n' => $proration->daysRemaining,
                'total' => $proration->daysInPeriod,
            ]),
        );

        $proration->addLine(
            Craft::t('subscribr', '{plan} for the rest of this period', ['plan' => $newPlan->name]),
            $proration->charge,
            Craft::t('subscribr', 'Until {date}', ['date' => $periodEnd->format('j M Y')]),
        );

        if ($newPlan->signupFee) {
            $proration->charge = Money::round($proration->charge + $newPlan->signupFee);
            $proration->addLine(Craft::t('subscribr', 'Plan fee'), (float)$newPlan->signupFee);
        }

        if ($proration->getIsCredit()) {
            $proration->carriedCredit = abs($proration->getNetDue());
            $proration->addLine(
                Craft::t('subscribr', 'Credit carried to your next payment'),
                0.0,
                Money::format($proration->carriedCredit, $subscription->currency),
            );
        }

        $proration->addLine(
            Craft::t('subscribr', 'From {date}, {amount} {cadence}', [
                'date' => $periodEnd->format('j M Y'),
                'amount' => Money::format($proration->newCycleAmount, $subscription->currency),
                'cadence' => $newPlan->getCadence()->describe(),
            ]),
            0.0,
        );

        $this->_afterProrate($subscription, $proration);

        return $proration;
    }

    /**
     * Move a subscription onto another plan.
     *
     * `$atBoundary` forces the deferred form regardless of the plan's mode, which is what a booked
     * switch uses when the renewal engine finally reaches it.
     *
     * @return array{0: bool, 1: ProrationModel, 2: string|null}
     */
    public function applySwitch(Subscription $subscription, Plan $newPlan, bool $atBoundary = false, ?DateTime $now = null): array
    {
        $plugin = Plugin::getInstance();
        $oldPlan = $subscription->getPlan();

        if ($oldPlan !== null && !$oldPlan->canSwitchTo($newPlan)) {
            return [false, new ProrationModel(), Craft::t('subscribr', 'That plan isn’t available from this one.')];
        }

        $proration = $this->preview($subscription, $newPlan, $now);

        if (!$atBoundary && $proration->mode === ProrationModel::MODE_END) {
            // Booked rather than done. The subscriber keeps what they paid for until the boundary.
            $plugin->getSchedules()->book(
                $subscription,
                ScheduledAction::SWITCH_PLAN,
                $subscription->cycleCount + 1,
                ['planId' => $newPlan->id],
            );

            $plugin->getLedger()->log(
                (int)$subscription->id,
                LogEntry::TYPE_SWITCHED,
                Craft::t('subscribr', 'Moving to {plan} on {date}.', [
                    'plan' => $newPlan->name,
                    'date' => $proration->effectiveDate?->format('j M Y'),
                ]),
                ['toPlanId' => $newPlan->id, 'deferred' => true],
            );

            return [true, $proration, null];
        }

        $order = null;

        if (!$proration->getIsFree() && !$proration->getIsCredit()) {
            [$charged, $order, $error] = $this->_chargeDifference($subscription, $proration);

            if (!$charged) {
                return [false, $proration, $error];
            }
        }

        $subscription->setPlan($newPlan);
        $subscription->boxId = $newPlan->boxId;

        // The items are re-priced onto the new plan. Without this the subscription would carry the
        // old plan's prices for ever and the "switch" would only change the cadence.
        $items = $subscription->getItems();

        foreach ($items as $item) {
            $item->price = $newPlan->priceFor((float)($item->getSnapshot()['listPrice'] ?? $item->price), $item->price);
        }

        $plugin->getSubscriptions()->saveItems($subscription, $items);
        $plugin->getSubscriptions()->save($subscription);
        $plugin->getSubscriptions()->refreshRenewalPrice($subscription);

        $plugin->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_SWITCHED,
            Craft::t('subscribr', 'Moved from {from} to {to}; {due} due today.', [
                'from' => $oldPlan?->name ?? '—',
                'to' => $newPlan->name,
                'due' => Money::format(max(0, $proration->getNetDue()), $subscription->currency),
            ]),
            [
                'fromPlanId' => $oldPlan?->id,
                'toPlanId' => $newPlan->id,
                'credit' => $proration->credit,
                'charge' => $proration->charge,
                'netDue' => $proration->getNetDue(),
                'carriedCredit' => $proration->carriedCredit,
                'orderId' => $order?->id,
            ],
        );

        return [true, $proration, null];
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * What this subscription's items would cost on another plan.
     */
    private function _amountOnPlan(Subscription $subscription, Plan $plan): float
    {
        if ($plan->pricingMode === Plan::PRICING_OVERRIDE) {
            return Money::round((float)($plan->planPrice ?? 0) * max(1, $subscription->quantity));
        }

        $total = 0.0;

        foreach ($subscription->getItems() as $item) {
            $listPrice = (float)($item->getSnapshot()['listPrice'] ?? $item->price);
            $total += $plan->priceFor($listPrice, $item->price) * $item->qty;
        }

        return Money::round($total * max(1, $subscription->quantity));
    }

    /**
     * Bill the difference as a one-line order.
     *
     * A real order again, so the money shows up in the store's takings and the customer gets a
     * receipt they can find.
     *
     * The line item is the subscription's **own purchasable**, re-priced to the amount owed, and
     * not a synthetic custom line. Two reasons, and the second is the important one:
     *
     * - A custom line item arrives with no tax or shipping category, and a proration should be
     *   taxed exactly like the thing it is a proration of. Borrowing the purchasable's categories
     *   is not an approximation, it is the correct answer.
     * - The order is put in `ADJUSTMENTS_ONLY` recalculation mode before it is recalculated.
     *   Commerce's full recalculation calls `LineItem::refresh()`, which restores the catalogue
     *   price — so setting a price and then recalculating silently throws the price away, and the
     *   customer is charged a full cycle instead of the difference. Adjustments-only still runs
     *   tax and shipping; it just stops the line being re-fetched.
     *
     * A subscription with no purchasable at all — every item deleted from the catalogue — cannot
     * be billed this way, and the difference is carried to the next renewal rather than lost.
     *
     * @return array{0: bool, 1: Order|null, 2: string|null}
     */
    private function _chargeDifference(Subscription $subscription, ProrationModel $proration): array
    {
        $plugin = Plugin::getInstance();
        $due = $proration->getNetDue();

        if ($due <= 0) {
            return [true, null, null];
        }

        $subscriber = $subscription->getSubscriber();

        if ($subscriber === null) {
            return [false, null, Craft::t('subscribr', 'This subscription has no account to bill.')];
        }

        $purchasableId = null;

        foreach ($subscription->getItems() as $item) {
            if ($item->purchasableId && $item->getPurchasable() !== null) {
                $purchasableId = (int)$item->purchasableId;
                break;
            }
        }

        if ($purchasableId === null) {
            return [false, null, Craft::t('subscribr', 'Nothing on this subscription can be billed — its products are no longer in the catalogue.')];
        }

        try {
            $commerce = \craft\commerce\Plugin::getInstance();

            $order = new Order();
            $order->number = $commerce->getCarts()->generateCartNumber();
            $order->currency = $subscription->currency;
            $order->paymentCurrency = $subscription->currency;
            $order->origin = Order::ORIGIN_REMOTE;
            $order->setCustomer($subscriber);
            $order->email = $subscriber->email;

            if ($address = $subscription->getBillingAddress()) {
                $order->setBillingAddress($address->toArray(['addressLine1', 'addressLine2', 'locality', 'administrativeArea', 'postalCode', 'countryCode', 'fullName', 'organization']));
            }

            if (!Craft::$app->getElements()->saveElement($order, false)) {
                return [false, null, Craft::t('subscribr', 'Could not create the order for the change.')];
            }

            $lineItem = $commerce->getLineItems()->createLineItem($order, $purchasableId, [
                'subscribrProration' => $subscription->reference,
            ], 1);

            $lineItem->setPrice($due);
            $lineItem->setPromotionalPrice(null);
            $lineItem->setDescription(Craft::t('subscribr', 'Plan change: {from} → {to}', [
                'from' => $proration->oldPlan?->name ?? '—',
                'to' => $proration->newPlan?->name ?? '—',
            ]));

            $order->setLineItems([$lineItem]);
            // Nothing to ship: this is the difference between two plans, not a delivery.
            $order->shippingMethodHandle = null;
            $order->setRecalculationMode(Order::RECALCULATION_MODE_ADJUSTMENTS_ONLY);
            $order->recalculate();
            Craft::$app->getElements()->saveElement($order, false);

            if (!$order->markAsComplete()) {
                return [false, $order, Craft::t('subscribr', 'Could not complete the order for the change.')];
            }

            $order = Order::find()->id($order->id)->status(null)->one() ?? $order;

            $attempt = $plugin->getBilling()->charge($subscription, $order);

            if ($attempt->outcome !== Attempt::SUCCESS) {
                // The plan is *not* changed when the difference cannot be taken. A subscriber moved
                // onto the more expensive plan and then not charged for it is a bug that pays for
                // itself in the wrong direction every month.
                return [false, $order, $attempt->message ?? Craft::t('subscribr', 'The payment for the change was declined.')];
            }

            $plugin->getSubscriptions()->linkOrder($subscription, $order, 'switch', $subscription->cycleCount);

            return [true, $order, null];
        } catch (Throwable $e) {
            Craft::error('Proration charge failed: ' . $e->getMessage(), __METHOD__);

            return [false, null, $e->getMessage()];
        }
    }

    private function _afterProrate(Subscription $subscription, ProrationModel $proration): void
    {
        if ($this->hasEventHandlers(self::EVENT_AFTER_PRORATE)) {
            $this->trigger(self::EVENT_AFTER_PRORATE, new ProrationEvent([
                'subscription' => $subscription,
                'proration' => $proration,
            ]));
        }
    }
}
