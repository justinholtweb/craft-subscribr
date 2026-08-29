<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\models;

use craft\base\Model;
use craft\commerce\elements\Order;
use DateTime;

/**
 * One attempt to take a renewal payment.
 *
 * Every attempt is recorded, including the ones that succeeded first time, because the dunning
 * dashboard's only honest number — how much of what failed was eventually recovered — cannot be
 * computed from failures alone.
 */
class Attempt extends Model
{
    public const SUCCESS = 'success';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    public const MANUAL = 'manual';
    public const REDIRECT = 'redirect';

    public ?int $id = null;
    public ?int $subscriptionId = null;
    public ?int $orderId = null;
    public ?int $transactionId = null;
    public int $stage = 0;
    public string $outcome = self::FAILED;
    public ?float $amount = null;
    public ?string $currency = null;
    public ?string $message = null;
    public ?string $gatewayCode = null;
    public ?DateTime $dateCreated = null;
    public ?string $uid = null;

    public function getOrder(): ?Order
    {
        return $this->orderId ? Order::find()->id($this->orderId)->status(null)->one() : null;
    }

    public function getIsSuccess(): bool
    {
        return $this->outcome === self::SUCCESS;
    }

    /**
     * Whether the failure looks like something a retry could fix.
     *
     * A card that is expired or reported stolen will not start working on Thursday, and retrying
     * it three more times is how a store gets its merchant account reviewed. Gateways do not agree
     * on codes, so this matches the substrings they do agree on and defaults to *retryable* —
     * failing to retry a recoverable payment loses a customer, which is worse than one wasted call.
     */
    public function getIsRetryable(): bool
    {
        $haystack = strtolower(($this->gatewayCode ?? '') . ' ' . ($this->message ?? ''));

        foreach ([
            'stolen',
            'lost_card',
            'lost card',
            'pickup_card',
            'do not honor',
            'do_not_honor',
            'card_not_supported',
            'revocation',
            'closed account',
            'invalid_account',
            'fraudulent',
        ] as $terminal) {
            if (str_contains($haystack, $terminal)) {
                return false;
            }
        }

        return true;
    }
}
