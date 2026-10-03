<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use DateTime;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\helpers\Money;
use justinholtweb\subscribr\models\Item;
use justinholtweb\subscribr\models\LogEntry;
use justinholtweb\subscribr\models\Plan;
use justinholtweb\subscribr\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Mixed carts: one-off products and subscriptions in the same basket, checked out together.
 *
 * Core Commerce cannot do this at all — a subscription there is a separate flow that never touches
 * a cart, so a store selling both makes the customer check out twice and pay two lots of postage.
 *
 * ## How it works, and why it is so little code
 *
 * A recurring line is an ordinary line item with a few keys in its `options`. That is the whole
 * mechanism, and it works because of something Commerce already does: **line items are de-duplicated
 * by purchasable ID *and* a hash of their options.** So a bag of coffee bought once and the same
 * bag of coffee bought monthly are automatically two separate lines that can hold different
 * quantities and different prices, without Subscribr touching the cart machinery at all.
 *
 * The keys are flat and scalar (`subscribrPlan`, not `subscribr.plan`) for two reasons: a dotted
 * key is unreachable from a Twig object template, which is what Commerce renders line-item
 * descriptions with, and a nested array hashes differently depending on key order.
 *
 * ## What happens at checkout
 *
 * The adjuster handles the money — trials, signup fees, prepayment. This service handles what
 * happens *after*: when the order completes, every recurring line becomes a subscription, and the
 * order becomes that subscription's signup order.
 */
class Carts extends Component
{
    public const OPTION_PLAN = 'subscribrPlan';
    public const OPTION_PREPAID = 'subscribrPrepaid';
    public const OPTION_GIFT_EMAIL = 'subscribrGiftEmail';
    public const OPTION_GIFT_NAME = 'subscribrGiftName';
    public const OPTION_GIFT_MESSAGE = 'subscribrGiftMessage';
    public const OPTION_GIFT_DELIVER = 'subscribrGiftDeliver';
    public const OPTION_BOX_SELECTION = 'subscribrBox';

    /**
     * The options that mark a line item as recurring.
     *
     * Returned rather than set, so the caller merges them into whatever else it is putting on the
     * line — a cart controller has its own options and should not have to know Subscribr's.
     */
    public function optionsFor(Plan $plan, array $extra = []): array
    {
        return array_merge([self::OPTION_PLAN => $plan->handle], array_filter($extra, static fn($v): bool => $v !== null && $v !== ''));
    }

    public function getPlanForLineItem(LineItem $lineItem): ?Plan
    {
        $handle = $lineItem->getOptions()[self::OPTION_PLAN] ?? null;

        return $handle ? Plugin::getInstance()->getPlans()->getPlanByHandle((string)$handle) : null;
    }

    public function isRecurring(LineItem $lineItem): bool
    {
        return $this->getPlanForLineItem($lineItem) !== null;
    }

    /**
     * Every recurring line in a cart.
     *
     * @return LineItem[]
     */
    public function getRecurringLineItems(Order $order): array
    {
        return array_values(array_filter($order->getLineItems(), fn(LineItem $li): bool => $this->isRecurring($li)));
    }

    public function getHasRecurringItems(Order $order): bool
    {
        return $this->getRecurringLineItems($order) !== [];
    }

    public function getHasOneOffItems(Order $order): bool
    {
        foreach ($order->getLineItems() as $lineItem) {
            if (!$this->isRecurring($lineItem)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the cart commits the customer to *after* today.
     *
     * The number a mixed cart has to show, and the one nobody shows: a basket with a £40 kettle
     * and a £12 monthly coffee costs £52 today and £12 a month for ever, and only saying "£52"
     * is how a subscription becomes a complaint.
     *
     * @return array<string, array{cadence: string, amount: float, plans: string[]}>
     */
    public function getRecurringSummary(Order $order): array
    {
        $summary = [];

        foreach ($this->getRecurringLineItems($order) as $lineItem) {
            $plan = $this->getPlanForLineItem($lineItem);

            if ($plan === null) {
                continue;
            }

            $key = $plan->interval . ':' . $plan->intervalCount . ':' . ($plan->anchorDay ?? '-');

            $summary[$key] ??= [
                'cadence' => $plan->getCadence()->describe(),
                'amount' => 0.0,
                'plans' => [],
            ];

            // The recurring amount is the *plan* price, not the line total. A signup fee, a trial
            // and a prepayment all change what is paid today and none of them change what is paid
            // every month afterwards.
            $summary[$key]['amount'] += $plan->priceFor((float)$lineItem->getPrice()) * $lineItem->qty;

            if (!in_array($plan->name, $summary[$key]['plans'], true)) {
                $summary[$key]['plans'][] = $plan->name;
            }
        }

        foreach ($summary as &$entry) {
            $entry['amount'] = Money::round($entry['amount']);
        }

        return $summary;
    }

    /**
     * Whether the cart can actually be checked out as a subscription.
     *
     * @return string[] Reasons it cannot. Empty means it can.
     */
    public function validate(Order $order): array
    {
        if (!$this->getHasRecurringItems($order)) {
            return [];
        }

        $errors = [];

        // A subscription needs somebody to bill next month. A guest checkout cannot be renewed,
        // and finding that out at the first renewal rather than at the checkout is much worse.
        if ($order->getCustomer() === null) {
            $errors[] = Craft::t('subscribr', 'A subscription needs an account. Please sign in or create one to continue.');
        }

        if (!Plugin::getInstance()->getBilling()->getHasCapableGateway()) {
            $errors[] = Craft::t('subscribr', 'No payment method in this store can take recurring payments.');
        }

        foreach ($this->getRecurringLineItems($order) as $lineItem) {
            $plan = $this->getPlanForLineItem($lineItem);

            if ($plan === null || !$plan->enabled) {
                $errors[] = Craft::t('subscribr', '“{item}” is no longer available as a subscription.', [
                    'item' => $lineItem->getDescription(),
                ]);
            }
        }

        return $errors;
    }

    /**
     * Turn a completed order's recurring lines into subscriptions.
     *
     * Called from Commerce's order-complete event. Idempotent: an order that has already been
     * materialised is left alone, because that event can fire more than once for one order and a
     * customer with two identical subscriptions is a refund and an apology.
     *
     * @return Subscription[]
     */
    public function materialize(Order $order): array
    {
        $plugin = Plugin::getInstance();

        if ($plugin->getSubscriptions()->getSubscriptionsForOrder((int)$order->id) !== []) {
            return [];
        }

        $lineItems = $this->getRecurringLineItems($order);

        if ($lineItems === []) {
            return [];
        }

        $subscriber = $order->getCustomer();
        $created = [];

        // One subscription per plan, not per line: three coffees on the same monthly plan are one
        // monthly delivery of three coffees, and billing them as three subscriptions would charge
        // three lots of postage and send three emails.
        $byPlan = [];

        foreach ($lineItems as $lineItem) {
            $plan = $this->getPlanForLineItem($lineItem);

            if ($plan === null) {
                continue;
            }

            $byPlan[$plan->id][] = $lineItem;
        }

        foreach ($byPlan as $planId => $planLineItems) {
            $plan = $plugin->getPlans()->getPlanById((int)$planId);

            if ($plan === null) {
                continue;
            }

            try {
                $created[] = $this->_createFromLineItems($order, $plan, $planLineItems, $subscriber);
            } catch (Throwable $e) {
                Craft::error(
                    sprintf('Could not create a subscription from order %s: %s', $order->number, $e->getMessage()),
                    __METHOD__,
                );
            }
        }

        return $created;
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param LineItem[] $lineItems
     */
    private function _createFromLineItems(Order $order, Plan $plan, array $lineItems, ?User $subscriber): Subscription
    {
        $plugin = Plugin::getInstance();
        $first = $lineItems[0];
        $options = $first->getOptions();

        $giftEmail = $options[self::OPTION_GIFT_EMAIL] ?? null;
        $prepaid = (int)($options[self::OPTION_PREPAID] ?? 0);

        $items = [];

        foreach ($lineItems as $sortOrder => $lineItem) {
            $item = new Item();
            $item->purchasableId = $lineItem->purchasableId;
            $item->qty = $lineItem->qty;
            // The *recurring* price, which is not what was paid today: a trial paid nothing, a
            // prepayment paid six of these, and a signup fee is not part of it at all.
            $item->price = $plan->priceFor((float)$lineItem->getPrice());
            $item->description = $lineItem->getDescription();
            $item->sku = $lineItem->getSku();
            $item->sortOrder = $sortOrder;
            $item->setOptions(array_diff_key($lineItem->getOptions(), array_flip([
                self::OPTION_PLAN,
                self::OPTION_PREPAID,
                self::OPTION_GIFT_EMAIL,
                self::OPTION_GIFT_NAME,
                self::OPTION_GIFT_MESSAGE,
                self::OPTION_GIFT_DELIVER,
                self::OPTION_BOX_SELECTION,
            ])));
            $item->setSnapshot(['listPrice' => (float)$lineItem->getPrice()]);

            $items[] = $item;
        }

        $paymentSource = $order->getPaymentSource();

        $subscription = $plugin->getSubscriptions()->createSubscription($plan, $subscriber?->id, $items, [
            'orderId' => $order->id,
            'currency' => $order->currency,
            'gatewayId' => $paymentSource->gatewayId ?? $order->gatewayId,
            'paymentSourceId' => $paymentSource?->id,
            'shippingAddressId' => $order->shippingAddressId,
            'billingAddressId' => $order->billingAddressId,
            'prepaidCyclesRemaining' => max(0, $prepaid - 1),
            // A gateway with no stored source can still carry the subscription; it just renews by
            // invoice. Decided once here rather than rediscovered at every renewal.
            'isManual' => $paymentSource === null,
        ]);

        $plugin->getSubscriptions()->linkOrder($subscription, $order, 'signup', 0);

        if ($giftEmail) {
            $plugin->getGifts()->createFromOrder($subscription, $order, [
                'recipientEmail' => (string)$giftEmail,
                'recipientName' => $options[self::OPTION_GIFT_NAME] ?? null,
                'message' => $options[self::OPTION_GIFT_MESSAGE] ?? null,
                'deliverOn' => $options[self::OPTION_GIFT_DELIVER] ?? null,
                'cycles' => max(1, $prepaid),
            ]);

            // A gift stays pending until it is claimed. Starting the clock at purchase would run
            // a Christmas present down before it was opened.
            return $subscription;
        }

        $plugin->getSubscriptions()->activate($subscription, $order->dateOrdered ?? new DateTime());
        $plugin->getNotifications()->sendWelcome($subscription);

        $plugin->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_NOTE,
            Craft::t('subscribr', 'Started from order {ref}.', ['ref' => $order->reference ?? $order->number]),
            ['orderId' => $order->id],
        );

        return $subscription;
    }
}
