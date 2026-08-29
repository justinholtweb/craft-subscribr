<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\models;

use craft\base\Model;
use craft\commerce\base\Gateway;
use craft\commerce\base\GatewayInterface;
use craft\commerce\base\SubscriptionGatewayInterface;

/**
 * What a gateway can actually do, and therefore how Subscribr will renew on it.
 *
 * This class is the whole argument of the plugin, in one place. Core Commerce asks a single
 * question — "does this gateway implement `SubscriptionGatewayInterface`?" — and that question has
 * exactly one affirmative answer in practice, which is why subscriptions in Craft are a Stripe
 * feature rather than a Commerce feature.
 *
 * Subscribr asks a different, smaller question: **can this gateway charge a stored token without
 * the customer present?** That is `supportsPaymentSources()` plus `supportsPurchase()`, and it is
 * true of very nearly every gateway written for Commerce — Braintree, Mollie, Authorize.Net,
 * Sage Pay, eWay, Stripe, and any Omnipay-derived gateway with a `createCard` request. The ones
 * where it is false are not shut out either; they renew *manually*, by raising the order and
 * mailing a payment link, which is how the offline and bank-transfer cases have always worked.
 *
 * Nothing here special-cases Stripe. A Stripe gateway is a gateway with payment sources.
 */
class GatewayCapability extends Model
{
    /** Can charge a stored source with nobody present. Renews on its own. */
    public const AUTOMATIC = 'automatic';

    /** Can take a payment, but not without the customer. Renews by invoice and a pay link. */
    public const MANUAL = 'manual';

    /** Cannot take a payment at all. Cannot carry a subscription. */
    public const NONE = 'none';

    public ?int $gatewayId = null;
    public string $name = '';
    public string $handle = '';
    public string $mode = self::NONE;

    public bool $supportsPaymentSources = false;
    public bool $supportsPurchase = false;
    public bool $supportsAuthorize = false;
    public bool $supportsCapture = false;
    public bool $supportsRefund = false;

    /**
     * Whether the gateway also implements Commerce's own subscription interface.
     *
     * Informational only. Subscribr runs its own engine on such a gateway just like any other, so
     * that one store does not have two irreconcilable subscription systems. It is surfaced in the
     * CP so a merchant understands why their Stripe plans and their Subscribr plans are separate
     * lists.
     */
    public bool $isCommerceSubscriptionGateway = false;

    /** Why the mode is what it is, in words a merchant can act on. */
    public ?string $reason = null;

    public static function forGateway(GatewayInterface $gateway): self
    {
        $capability = new self([
            'gatewayId' => (int)$gateway->id,
            'name' => (string)$gateway->name,
            'handle' => (string)$gateway->handle,
            'supportsPaymentSources' => $gateway->supportsPaymentSources(),
            'supportsPurchase' => $gateway->supportsPurchase(),
            'supportsAuthorize' => $gateway->supportsAuthorize(),
            'supportsCapture' => $gateway->supportsCapture(),
            'supportsRefund' => $gateway->supportsRefund(),
            'isCommerceSubscriptionGateway' => $gateway instanceof SubscriptionGatewayInterface,
        ]);

        $capability->mode = $capability->_resolveMode();

        return $capability;
    }

    public function getIsAutomatic(): bool
    {
        return $this->mode === self::AUTOMATIC;
    }

    public function getCanCarrySubscriptions(): bool
    {
        return $this->mode !== self::NONE;
    }

    /**
     * Whether a renewal on this gateway will leave money merely authorised rather than taken.
     *
     * Commerce picks authorise-vs-purchase from the gateway's configured `paymentType`, so a
     * gateway that supports both can still be set to authorise — and a subscription that is only
     * ever authorised is never actually paid. Subscribr captures immediately in that case; this
     * is what tells the CP to say so.
     */
    public function getWillAuthorizeOnly(Gateway|GatewayInterface|null $gateway = null): bool
    {
        if (!$gateway instanceof Gateway) {
            return false;
        }

        return $gateway->paymentType === 'authorize' && $this->supportsCapture;
    }

    private function _resolveMode(): string
    {
        if (!$this->supportsPurchase && !$this->supportsAuthorize) {
            $this->reason = 'This gateway cannot take a payment, so it cannot carry a subscription.';

            return self::NONE;
        }

        if (!$this->supportsPaymentSources) {
            $this->reason = 'This gateway cannot store a payment method, so renewals are raised as unpaid orders and the subscriber is emailed a link to pay them.';

            return self::MANUAL;
        }

        $this->reason = 'Renewals are charged against the subscriber’s stored payment method with nobody present.';

        return self::AUTOMATIC;
    }
}
