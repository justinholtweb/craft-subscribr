<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\models;

use craft\base\Model;

/**
 * Subscribr's settings.
 *
 * Nothing here is `required`. A settings model with a required attribute cannot be saved on a
 * fresh install, because Craft validates it before the first edit.
 */
class Settings extends Model
{
    // Renewals
    // -------------------------------------------------------------------------

    /**
     * Whether the renewal sweep runs from Craft's queue on its own. Off means renewals only ever
     * happen when `subscribr/renewals/run` is called — which is what a site with a real cron
     * wants, and what a staging clone of a production database very much wants.
     */
    public bool $autoRenew = true;

    /**
     * How many subscriptions one sweep will renew before it stops and leaves the rest for the next
     * one. A renewal is a payment; a runaway loop is a lot of payments.
     */
    public int $renewalBatchSize = 50;

    /**
     * How far ahead of the due date a renewal order may be raised, in hours. Zero renews on the
     * dot. A box store usually wants a day or two so pick-and-pack has the order before the
     * shipment is due.
     */
    public int $renewalLeadHours = 0;

    /**
     * How long after the due date a renewal is still attempted before it is treated as abandoned,
     * in days. Guards the case where the queue was off for a month: without it, turning it back on
     * bills every subscriber for every cycle they missed.
     */
    public int $renewalCatchUpDays = 7;

    /**
     * Whether a missed cycle is billed when the sweep finally runs, or silently skipped and the
     * schedule moved on. Skipping is the safe default: nobody wants a surprise triple charge.
     */
    public bool $billMissedCycles = false;

    // Money
    // -------------------------------------------------------------------------

    /**
     * Whether an authorize-only gateway has its renewal authorisation captured immediately.
     * Commerce decides authorise-vs-purchase from the gateway's own `paymentType`; a subscription
     * that is only ever authorised is never actually paid.
     */
    public bool $captureAuthorizedRenewals = true;

    /** The order status renewal orders are set to once they are paid. Handle, or null for Commerce's default. */
    public ?string $renewalOrderStatus = null;

    /** The order status a renewal order gets while it is unpaid and being retried. */
    public ?string $pastDueOrderStatus = null;

    // Self-service
    // -------------------------------------------------------------------------

    /** Whether the front-end portal actions are available at all. */
    public bool $enablePortal = true;

    /**
     * How close to the renewal a subscriber may still change something, in hours. Inside the
     * window the portal shows the change as locked rather than failing after they have made it.
     */
    public int $changeLockHours = 12;

    /** Whether cancelling offers a pause instead, before it is accepted. */
    public bool $offerPauseOnCancel = true;

    /** Cancel reasons offered in the portal. An empty list turns the question off. */
    public array $cancelReasons = [
        'too-expensive' => 'Too expensive',
        'too-much' => 'I have too much already',
        'quality' => 'Not happy with the products',
        'moving' => 'Moving or travelling',
        'other' => 'Something else',
    ];

    // Dunning
    // -------------------------------------------------------------------------

    /** Whether dunning retries run at all. Off leaves a failed subscription past-due until someone acts. */
    public bool $enableDunning = true;

    /**
     * Retry offsets in hours from the first failure, used when a plan has no dunning profile.
     * The last stage is followed by whatever `dunningFinalAction` says.
     */
    public array $defaultRetryHours = [24, 72, 168];

    /** cancel | pause | expire — what happens when the retries are exhausted. */
    public string $dunningFinalAction = 'cancel';

    // Email
    // -------------------------------------------------------------------------

    public bool $sendEmails = true;
    public ?string $emailFromName = null;
    public ?string $emailFromAddress = null;

    /**
     * Template overrides, keyed by message. Anything left empty falls back to Subscribr's own
     * template, so a site that wants to change one email does not have to provide all of them.
     */
    public array $emailTemplates = [];

    /** Where the portal lives, for links in emails. Site-relative. */
    public string $portalPath = 'account/subscriptions';

    /** Where a gift is claimed. `{token}` is replaced. */
    public string $giftClaimPath = 'gift/{token}';

    // Boxes
    // -------------------------------------------------------------------------

    /**
     * Whether a box whose subscriber never chose their contents ships the merchant's defaults, or
     * fails the cycle. Shipping the defaults is right for a curation business and wrong for one
     * where every item is an allergy question, so it is a setting rather than a decision.
     */
    public bool $shipDefaultsWhenUnchosen = true;

    public function defineRules(): array
    {
        return [
            [['renewalBatchSize'], 'integer', 'min' => 1, 'max' => 1000],
            [['renewalLeadHours', 'changeLockHours'], 'integer', 'min' => 0, 'max' => 8760],
            [['renewalCatchUpDays'], 'integer', 'min' => 0, 'max' => 365],
            [['dunningFinalAction'], 'in', 'range' => ['cancel', 'pause', 'expire']],
            [['emailFromAddress'], 'email', 'skipOnEmpty' => true],
        ];
    }

    /**
     * The retry offsets, cleaned: positive integers, ascending, de-duplicated. A profile editor
     * that lets someone type "24, 12, 24" must not produce a retry schedule that goes backwards.
     */
    public function getDefaultRetryHours(): array
    {
        $hours = array_map('intval', $this->defaultRetryHours);
        $hours = array_values(array_unique(array_filter($hours, static fn(int $h): bool => $h > 0)));
        sort($hours);

        return $hours;
    }
}
