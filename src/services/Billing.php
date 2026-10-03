<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\services;

use Craft;
use craft\commerce\base\GatewayInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\models\Attempt;
use justinholtweb\subscribr\models\GatewayCapability;
use justinholtweb\subscribr\Plugin;
use justinholtweb\subscribr\records\AttemptRecord;
use Throwable;
use yii\base\Component;

/**
 * Taking money.
 *
 * **This is the only place in Subscribr where money moves**, and it is deliberately small, because
 * the whole argument of the plugin is that it does not need to be big.
 *
 * Commerce already knows how to charge a stored card off-session: `Payments::processPayment()`
 * with a payment form populated from a `PaymentSource`. That is the same call the checkout makes
 * when a returning customer picks a saved card, minus the customer. It does not require a gateway
 * to implement `SubscriptionGatewayInterface`; it requires it to implement
 * `supportsPaymentSources()`, which nearly all of them do.
 *
 * So Subscribr does not integrate with gateways. It asks Commerce to take a payment, and lets
 * Commerce integrate with gateways, exactly as it does for every other order in the store.
 *
 * ## Three things this has to get right
 *
 * 1. **Ownership.** `Order::setPaymentSource()` throws if the source is not owned by the order's
 *    customer, so the customer is set first — and a renewal for a subscription whose subscriber
 *    was deleted fails cleanly here rather than fataling.
 * 2. **Authorise vs purchase.** Commerce picks between them from the gateway's own `paymentType`,
 *    and a subscription that is only ever authorised is never actually paid. When the gateway is
 *    set to authorise, the authorisation is captured immediately.
 * 3. **Redirects.** An off-session charge that comes back wanting the customer's browser (3-D
 *    Secure, an issuer challenge) has not failed — it has asked for something a cron job cannot
 *    provide. That is a distinct outcome, and it turns the cycle into a manual one with a payment
 *    link rather than counting against the retry budget.
 */
class Billing extends Component
{
    /** @var array<int, GatewayCapability> */
    private array $_capabilities = [];

    // Capability
    // -------------------------------------------------------------------------

    public function getCapability(GatewayInterface|int $gateway): ?GatewayCapability
    {
        $id = is_int($gateway) ? $gateway : (int)$gateway->id;

        if (!isset($this->_capabilities[$id])) {
            $resolved = is_int($gateway)
                ? Commerce::getInstance()->getGateways()->getGatewayById($gateway)
                : $gateway;

            if ($resolved === null) {
                return null;
            }

            $this->_capabilities[$id] = GatewayCapability::forGateway($resolved);
        }

        return $this->_capabilities[$id];
    }

    /**
     * Every gateway in the store, with what Subscribr can do on it.
     *
     * @return GatewayCapability[]
     */
    public function getAllCapabilities(): array
    {
        $capabilities = [];

        foreach (Commerce::getInstance()->getGateways()->getAllGateways() as $gateway) {
            $capability = $this->getCapability($gateway);

            if ($capability !== null) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }

    /**
     * Whether the store can run subscriptions at all.
     *
     * False means every configured gateway is payment-incapable, which is a real state — a store
     * with only a manual/offline gateway — and one the CP should say out loud rather than letting
     * a merchant build plans that can never bill.
     */
    public function getHasCapableGateway(): bool
    {
        foreach ($this->getAllCapabilities() as $capability) {
            if ($capability->getCanCarrySubscriptions()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this subscription can be renewed without anybody present.
     */
    public function canChargeAutomatically(Subscription $subscription): bool
    {
        if ($subscription->isManual || !$subscription->paymentSourceId) {
            return false;
        }

        $capability = $subscription->gatewayId ? $this->getCapability($subscription->gatewayId) : null;

        return $capability?->getIsAutomatic() === true;
    }

    // Charging
    // -------------------------------------------------------------------------

    /**
     * Charge a renewal order against the subscription's stored payment method.
     *
     * Never throws. Every outcome — success, decline, redirect, misconfiguration — comes back as a
     * recorded `Attempt`, because the caller is a sweep over many subscriptions and one bad card
     * must not end it.
     */
    public function charge(Subscription $subscription, Order $order, int $stage = 0): Attempt
    {
        $attempt = new Attempt([
            'subscriptionId' => (int)$subscription->id,
            'orderId' => (int)$order->id,
            'stage' => $stage,
            'amount' => (float)$order->getTotalPrice(),
            'currency' => $order->currency ?: $subscription->currency,
        ]);

        $source = $subscription->getPaymentSource();

        if ($source === null) {
            return $this->_record($attempt, Attempt::MANUAL, 'No stored payment method; the order was left unpaid.');
        }

        $gateway = $source->getGateway();

        if ($gateway === null) {
            return $this->_record($attempt, Attempt::FAILED, 'The gateway this payment method belongs to no longer exists.');
        }

        $capability = $this->getCapability($gateway);

        if ($capability === null || !$capability->getIsAutomatic()) {
            return $this->_record($attempt, Attempt::MANUAL, $capability->reason ?? 'This gateway cannot charge a stored payment method.');
        }

        try {
            $this->_prepareOrder($order, $subscription, $source);
        } catch (Throwable $e) {
            return $this->_record($attempt, Attempt::FAILED, $e->getMessage());
        }

        $form = $gateway->getPaymentFormModel();
        $form->populateFromPaymentSource($source);

        $redirect = null;
        $transaction = null;

        try {
            Commerce::getInstance()->getPayments()->processPayment($order, $form, $redirect, $transaction);
        } catch (Throwable $e) {
            $attempt->transactionId = $transaction?->id ? (int)$transaction->id : null;
            $attempt->gatewayCode = $transaction?->code;

            return $this->_record($attempt, Attempt::FAILED, $e->getMessage());
        }

        $attempt->transactionId = $transaction?->id ? (int)$transaction->id : null;
        $attempt->gatewayCode = $transaction?->code;

        // The gateway wants the customer's browser. Not a decline, and not something a retry in
        // three days will fix either — it needs a person. Handed to manual renewal.
        if ($redirect) {
            return $this->_record($attempt, Attempt::REDIRECT, 'The gateway asked for the customer to confirm the payment.');
        }

        if ($transaction !== null && $this->_shouldCapture($transaction)) {
            try {
                $capture = Commerce::getInstance()->getPayments()->captureTransaction($transaction);

                if ($capture->status !== TransactionRecord::STATUS_SUCCESS) {
                    return $this->_record($attempt, Attempt::FAILED, $capture->message ?: 'The authorisation could not be captured.');
                }

                $order->updateOrderPaidInformation();
            } catch (Throwable $e) {
                return $this->_record($attempt, Attempt::FAILED, 'Authorised but not captured: ' . $e->getMessage());
            }
        }

        // Reloaded rather than trusted: `updateOrderPaidInformation()` writes to the order and the
        // in-memory element that came out of processPayment is not always the one it wrote to.
        $fresh = Order::find()->id($order->id)->status(null)->one();

        if ($fresh === null || !$fresh->getIsPaid()) {
            return $this->_record($attempt, Attempt::FAILED, $transaction?->message ?: 'The payment did not complete.');
        }

        return $this->_record($attempt, Attempt::SUCCESS, null);
    }

    /**
     * Record an attempt that never reached the gateway — a skipped cycle, a prepaid draw.
     */
    public function recordNonPayment(Subscription $subscription, ?Order $order, string $outcome, ?string $message = null): Attempt
    {
        return $this->_record(new Attempt([
            'subscriptionId' => (int)$subscription->id,
            'orderId' => $order?->id ? (int)$order->id : null,
            'amount' => $order ? (float)$order->getTotalPrice() : 0.0,
            'currency' => $subscription->currency,
        ]), $outcome, $message);
    }

    /** @return Attempt[] */
    public function getAttempts(int $subscriptionId, int $limit = 50): array
    {
        $rows = AttemptRecord::find()
            ->where(['subscriptionId' => $subscriptionId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->asArray()
            ->all();

        return array_map(static function(array $row): Attempt {
            $attempt = new Attempt();
            $attempt->id = (int)$row['id'];
            $attempt->subscriptionId = (int)$row['subscriptionId'];
            $attempt->orderId = isset($row['orderId']) ? (int)$row['orderId'] : null;
            $attempt->transactionId = isset($row['transactionId']) ? (int)$row['transactionId'] : null;
            $attempt->stage = (int)$row['stage'];
            $attempt->outcome = (string)$row['outcome'];
            $attempt->amount = isset($row['amount']) ? (float)$row['amount'] : null;
            $attempt->currency = $row['currency'] ?? null;
            $attempt->message = $row['message'] ?? null;
            $attempt->gatewayCode = $row['gatewayCode'] ?? null;
            $attempt->dateCreated = isset($row['dateCreated'])
                ? \craft\helpers\DateTimeHelper::toDateTime($row['dateCreated']) ?: null
                : null;

            return $attempt;
        }, $rows);
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * Point the order at the right customer, gateway and stored card.
     *
     * The order in the customer's name is set *first*: `setPaymentSource()` compares the source's
     * owner against the order's customer and throws when they differ, so setting them the other
     * way round throws on every renewal of every subscription.
     */
    private function _prepareOrder(Order $order, Subscription $subscription, PaymentSource $source): void
    {
        $subscriber = $subscription->getSubscriber();

        if ($subscriber === null) {
            throw new \RuntimeException('The subscriber account no longer exists.');
        }

        if ((int)$order->getCustomer()?->id !== (int)$subscriber->id) {
            $order->setCustomer($subscriber);
        }

        $order->setPaymentSource($source);
    }

    /**
     * Whether a successful transaction still needs capturing.
     */
    private function _shouldCapture(Transaction $transaction): bool
    {
        if (!Plugin::getInstance()->getSettings()->captureAuthorizedRenewals) {
            return false;
        }

        return $transaction->type === TransactionRecord::TYPE_AUTHORIZE
            && $transaction->status === TransactionRecord::STATUS_SUCCESS
            && $transaction->getGateway()?->supportsCapture();
    }

    private function _record(Attempt $attempt, string $outcome, ?string $message): Attempt
    {
        $attempt->outcome = $outcome;
        $attempt->message = $message;

        $record = new AttemptRecord();
        $record->subscriptionId = $attempt->subscriptionId;
        $record->orderId = $attempt->orderId;
        $record->transactionId = $attempt->transactionId;
        $record->stage = $attempt->stage;
        $record->outcome = $attempt->outcome;
        $record->amount = $attempt->amount;
        $record->currency = $attempt->currency;
        $record->message = $attempt->message !== null ? mb_substr($attempt->message, 0, 2000) : null;
        $record->gatewayCode = $attempt->gatewayCode;
        $record->save(false);

        $attempt->id = (int)$record->id;

        if ($outcome === Attempt::FAILED) {
            Craft::warning(
                sprintf('Renewal payment failed for subscription %d: %s', $attempt->subscriptionId, $attempt->message),
                __METHOD__,
            );
        }

        return $attempt;
    }
}
