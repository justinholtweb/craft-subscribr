<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\elements;

use Craft;
use craft\base\Element;
use craft\commerce\base\GatewayInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\PaymentSource;
use craft\commerce\Plugin as Commerce;
use craft\elements\Address;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use DateTime;
use justinholtweb\subscribr\elements\db\SubscriptionQuery;
use justinholtweb\subscribr\helpers\Money;
use justinholtweb\subscribr\models\Box;
use justinholtweb\subscribr\models\Cadence;
use justinholtweb\subscribr\models\GatewayCapability;
use justinholtweb\subscribr\models\Item;
use justinholtweb\subscribr\models\Plan;
use justinholtweb\subscribr\Plugin;
use justinholtweb\subscribr\records\SubscriptionRecord;

/**
 * A subscription.
 *
 * Deliberately **not** `craft\commerce\elements\Subscription`. That element is a local mirror of
 * something a gateway is holding: `getGateway()` returns a `SubscriptionGatewayInterface`,
 * `getNextPaymentAmount()` asks the gateway, `getAlternativePlans()` asks the gateway, and the
 * whole thing fatals on a gateway that does not implement that interface. It cannot be made to
 * describe a subscription the store is running itself, which is what Subscribr does.
 *
 * So this element is the authoritative record. The dates are real dates, not a cached copy of
 * Stripe's; the price is the store's price; and the gateway is asked one question only, at renewal
 * time — *take this much money off this stored card*.
 *
 * ## Statuses
 *
 * - **pending** — created, not yet started. An unclaimed gift, or a signup whose first payment
 *   has not cleared.
 * - **trialing** — started, in its trial, not yet charged.
 * - **active** — running.
 * - **paused** — the subscriber (or dunning) stopped the clock. No orders, no charges, and the
 *   next payment date moves forward with the pause rather than accruing behind it.
 * - **pastDue** — a payment failed and dunning has it.
 * - **canceled** — cancelled but still inside the period that was paid for. Still delivers.
 * - **expired** — over. The end of a cancelled term, an exhausted `maxCycles`, a used-up gift, or
 *   the end of a dunning sequence.
 *
 * The distinction between `canceled` and `expired` is the one that stops a store taking away
 * something a customer has already paid for.
 *
 * @property-read Plan|null $plan
 * @property-read Item[] $items
 * @property-read Cadence $cadence
 * @property-read User|null $subscriber
 */
class Subscription extends Element
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_PAST_DUE = 'pastDue';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_EXPIRED = 'expired';

    /** Statuses in which the subscription is still a going concern. */
    public const LIVE_STATUSES = [
        self::STATUS_TRIALING,
        self::STATUS_ACTIVE,
        self::STATUS_PAST_DUE,
        self::STATUS_CANCELED,
    ];

    public ?int $planId = null;
    public ?int $userId = null;
    public ?int $gatewayId = null;
    public ?int $paymentSourceId = null;
    public ?int $orderId = null;
    public ?int $boxId = null;
    public ?int $billingAddressId = null;
    public ?int $shippingAddressId = null;

    public string $reference = '';
    public string $subscriptionStatus = self::STATUS_PENDING;

    public int $quantity = 1;
    public string $currency = 'USD';
    public float $renewalPrice = 0.0;

    public ?DateTime $dateStarted = null;
    public ?DateTime $dateTrialEnds = null;
    public ?DateTime $dateNextPayment = null;
    public ?DateTime $dateCurrentPeriodStart = null;
    public ?DateTime $dateCanceled = null;
    public ?DateTime $dateEnds = null;
    public ?DateTime $dateEnded = null;
    public ?DateTime $datePaused = null;
    public ?DateTime $dateResumes = null;
    public ?DateTime $dateLastPayment = null;

    public int $cycleCount = 0;
    public int $prepaidCyclesRemaining = 0;
    public int $skipsUsed = 0;
    public int $pausesUsed = 0;

    public int $failureCount = 0;
    public int $dunningStage = 0;
    public ?DateTime $dateNextRetry = null;
    public ?string $lastFailureMessage = null;

    public bool $isManual = false;
    public bool $autoRenew = true;

    public ?string $cancelReason = null;
    public ?string $note = null;

    /** @var Item[]|null */
    private ?array $_items = null;
    private ?Plan $_plan = null;

    // Identity
    // -------------------------------------------------------------------------

    public static function displayName(): string
    {
        return Craft::t('subscribr', 'Subscription');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('subscribr', 'subscription');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('subscribr', 'Subscriptions');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('subscribr', 'subscriptions');
    }

    public static function refHandle(): ?string
    {
        return 'subscribr';
    }

    public static function hasTitles(): bool
    {
        return false;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return false;
    }

    public static function find(): SubscriptionQuery
    {
        return new SubscriptionQuery(static::class);
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE => ['label' => Craft::t('subscribr', 'Active'), 'color' => Color::Green],
            self::STATUS_TRIALING => ['label' => Craft::t('subscribr', 'Trialing'), 'color' => Color::Teal],
            self::STATUS_PENDING => ['label' => Craft::t('subscribr', 'Pending'), 'color' => Color::Gray],
            self::STATUS_PAUSED => ['label' => Craft::t('subscribr', 'Paused'), 'color' => Color::Blue],
            self::STATUS_PAST_DUE => ['label' => Craft::t('subscribr', 'Past due'), 'color' => Color::Orange],
            self::STATUS_CANCELED => ['label' => Craft::t('subscribr', 'Cancelling'), 'color' => Color::Amber],
            self::STATUS_EXPIRED => ['label' => Craft::t('subscribr', 'Ended'), 'color' => Color::Red],
        ];
    }

    /**
     * Columns Craft has to hydrate as dates rather than strings.
     *
     * Missing one is not a type error you notice: the element loads, and every comparison against
     * that attribute silently becomes a string comparison that is right often enough to hide.
     */
    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), [
            'dateStarted',
            'dateTrialEnds',
            'dateNextPayment',
            'dateCurrentPeriodStart',
            'dateCanceled',
            'dateEnds',
            'dateEnded',
            'datePaused',
            'dateResumes',
            'dateLastPayment',
            'dateNextRetry',
        ]);
    }

    public function __toString(): string
    {
        return $this->getName();
    }

    public function getName(): string
    {
        $plan = $this->getPlan();
        $name = $plan->name ?? Craft::t('subscribr', 'Subscription');

        return $this->quantity > 1 ? $name . ' × ' . $this->quantity : $name;
    }

    // Status
    // -------------------------------------------------------------------------

    /**
     * The stored status, corrected for the clock.
     *
     * Computed at read time rather than only by the sweep, so a trial that ended an hour ago on a
     * site whose cron is broken is already reported as active, and a cancelled subscription whose
     * paid-for period has run out already reports as ended. The sweep still writes the column —
     * this is what keeps the reader honest in between.
     */
    public function getStatus(): ?string
    {
        $now = new DateTime();

        if ($this->subscriptionStatus === self::STATUS_TRIALING
            && $this->dateTrialEnds !== null
            && $this->dateTrialEnds <= $now) {
            return self::STATUS_ACTIVE;
        }

        if ($this->subscriptionStatus === self::STATUS_CANCELED
            && $this->dateEnds !== null
            && $this->dateEnds <= $now) {
            return self::STATUS_EXPIRED;
        }

        if ($this->subscriptionStatus === self::STATUS_PAUSED
            && $this->dateResumes !== null
            && $this->dateResumes <= $now) {
            return self::STATUS_ACTIVE;
        }

        return $this->subscriptionStatus;
    }

    public function getIsLive(): bool
    {
        return in_array($this->getStatus(), self::LIVE_STATUSES, true);
    }

    public function getIsOnTrial(): bool
    {
        return $this->getStatus() === self::STATUS_TRIALING;
    }

    public function getIsPaused(): bool
    {
        return $this->getStatus() === self::STATUS_PAUSED;
    }

    public function getIsCanceled(): bool
    {
        return in_array($this->getStatus(), [self::STATUS_CANCELED, self::STATUS_EXPIRED], true);
    }

    public function getIsPrepaid(): bool
    {
        return $this->prepaidCyclesRemaining > 0;
    }

    /**
     * Whether a renewal is due now.
     *
     * `$leadHours` lets a box store raise the order a day or two early so pick-and-pack has it
     * before the shipment is meant to leave. It moves the *order*, never the schedule: the next
     * payment date after the renewal is still computed from the date that was due.
     */
    public function getIsDue(?DateTime $now = null, int $leadHours = 0): bool
    {
        if ($this->dateNextPayment === null) {
            return false;
        }

        if (!in_array($this->getStatus(), [self::STATUS_ACTIVE, self::STATUS_TRIALING, self::STATUS_CANCELED], true)) {
            return false;
        }

        $now = $now ?? new DateTime();

        if ($leadHours > 0) {
            $now = (clone $now)->modify('+' . $leadHours . ' hours');
        }

        return $this->dateNextPayment <= $now;
    }

    /** Whole days until the next payment, or null when there is not one. */
    public function getDaysUntilRenewal(): ?int
    {
        if ($this->dateNextPayment === null) {
            return null;
        }

        $diff = $this->dateNextPayment->getTimestamp() - time();

        return $diff <= 0 ? 0 : (int)ceil($diff / 86400);
    }

    // Relations
    // -------------------------------------------------------------------------

    public function getPlan(): ?Plan
    {
        if ($this->_plan === null && $this->planId) {
            $this->_plan = Plugin::getInstance()->getPlans()->getPlanById($this->planId);
        }

        return $this->_plan;
    }

    public function setPlan(?Plan $plan): void
    {
        $this->_plan = $plan;
        $this->planId = $plan?->id;
    }

    public function getCadence(): Cadence
    {
        return $this->getPlan()?->getCadence() ?? new Cadence();
    }

    public function getBox(): ?Box
    {
        $boxId = $this->boxId ?? $this->getPlan()?->boxId;

        return $boxId ? Plugin::getInstance()->getBoxes()->getBoxById($boxId) : null;
    }

    public function getSubscriber(): ?User
    {
        return $this->userId ? User::find()->id($this->userId)->status(null)->one() : null;
    }

    public function getOrder(): ?Order
    {
        return $this->orderId ? Order::find()->id($this->orderId)->status(null)->one() : null;
    }

    public function getGateway(): ?GatewayInterface
    {
        return $this->gatewayId ? Commerce::getInstance()->getGateways()->getGatewayById($this->gatewayId) : null;
    }

    public function getPaymentSource(): ?PaymentSource
    {
        return $this->paymentSourceId
            ? Commerce::getInstance()->getPaymentSources()->getPaymentSourceById($this->paymentSourceId)
            : null;
    }

    public function getGatewayCapability(): ?GatewayCapability
    {
        $gateway = $this->getGateway();

        return $gateway ? GatewayCapability::forGateway($gateway) : null;
    }

    public function getBillingAddress(): ?Address
    {
        return $this->billingAddressId ? Address::find()->id($this->billingAddressId)->one() : null;
    }

    public function getShippingAddress(): ?Address
    {
        return $this->shippingAddressId ? Address::find()->id($this->shippingAddressId)->one() : null;
    }

    /**
     * The standing items — what ships every cycle.
     *
     * @return Item[]
     */
    public function getItems(): array
    {
        if ($this->_items === null) {
            $this->_items = $this->id
                ? Plugin::getInstance()->getSubscriptions()->getItems((int)$this->id)
                : [];
        }

        return $this->_items;
    }

    /** @param Item[] $items */
    public function setItems(array $items): void
    {
        $this->_items = array_values($items);
    }

    /**
     * What ships for a given cycle: the standing items, with anything swapped in for that cycle
     * replacing them.
     *
     * @return Item[]
     */
    public function getItemsForCycle(?int $cycle = null): array
    {
        $cycle ??= $this->cycleCount + 1;

        return Plugin::getInstance()->getSubscriptions()->getItemsForCycle((int)$this->id, $cycle);
    }

    /** @return \justinholtweb\subscribr\models\ScheduledAction[] */
    public function getPendingActions(): array
    {
        return $this->id ? Plugin::getInstance()->getSchedules()->getPending((int)$this->id) : [];
    }

    /** @return \justinholtweb\subscribr\models\LogEntry[] */
    public function getHistory(int $limit = 100): array
    {
        return $this->id ? Plugin::getInstance()->getLedger()->getEntries((int)$this->id, $limit) : [];
    }

    /** Every order this subscription has produced, newest first. @return Order[] */
    public function getOrders(): array
    {
        return $this->id ? Plugin::getInstance()->getSubscriptions()->getOrders((int)$this->id) : [];
    }

    // Money
    // -------------------------------------------------------------------------

    /**
     * What the next charge will be, before tax, shipping and discounts.
     *
     * Deliberately *before* those. Tax on a subscription depends on an address and a date that do
     * not exist until the renewal order is built, and quoting a total that later changes by the
     * VAT rate is worse than quoting a subtotal and saying so.
     */
    public function getRenewalSubtotal(): float
    {
        $total = 0.0;

        foreach ($this->getItemsForCycle() as $item) {
            $total += $item->getSubtotal();
        }

        return Money::round($total * max(1, $this->quantity));
    }

    public function getRenewalPriceAsCurrency(): string
    {
        return Money::format($this->renewalPrice, $this->currency);
    }

    public function getNextPaymentAmount(): float
    {
        if ($this->prepaidCyclesRemaining > 0) {
            return 0.0;
        }

        return $this->getRenewalSubtotal();
    }

    // Permissions and URLs
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can('subscribr-manageSubscriptions') || $user->can('subscribr-viewSubscriptions');
    }

    public function canSave(User $user): bool
    {
        return $user->can('subscribr-manageSubscriptions');
    }

    public function canDelete(User $user): bool
    {
        return $user->can('subscribr-manageSubscriptions');
    }

    public function getCpEditUrl(): ?string
    {
        return UrlHelper::cpUrl('subscribr/subscriptions/' . $this->id);
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return Craft::$app->getFields()->getLayoutByType(self::class);
    }

    // Saving
    // -------------------------------------------------------------------------

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = $isNew ? new SubscriptionRecord() : SubscriptionRecord::findOne($this->id);

            if ($record === null) {
                // Restored from the trash, or a row that went missing. Written back rather than
                // failing the save and leaving a subscription with no schedule at all.
                $record = new SubscriptionRecord();
                $isNew = true;
            }

            if ($isNew) {
                $record->id = $this->id;
                $this->reference = $this->reference ?: self::generateReference();
            }

            $record->planId = $this->planId;
            $record->userId = $this->userId;
            $record->gatewayId = $this->gatewayId;
            $record->paymentSourceId = $this->paymentSourceId;
            $record->orderId = $this->orderId;
            $record->boxId = $this->boxId;
            $record->billingAddressId = $this->billingAddressId;
            $record->shippingAddressId = $this->shippingAddressId;
            $record->reference = $this->reference;
            $record->status = $this->subscriptionStatus;
            $record->quantity = $this->quantity;
            $record->currency = $this->currency;
            $record->renewalPrice = $this->renewalPrice;
            $record->dateStarted = Db::prepareDateForDb($this->dateStarted);
            $record->dateTrialEnds = Db::prepareDateForDb($this->dateTrialEnds);
            $record->dateNextPayment = Db::prepareDateForDb($this->dateNextPayment);
            $record->dateCurrentPeriodStart = Db::prepareDateForDb($this->dateCurrentPeriodStart);
            $record->dateCanceled = Db::prepareDateForDb($this->dateCanceled);
            $record->dateEnds = Db::prepareDateForDb($this->dateEnds);
            $record->dateEnded = Db::prepareDateForDb($this->dateEnded);
            $record->datePaused = Db::prepareDateForDb($this->datePaused);
            $record->dateResumes = Db::prepareDateForDb($this->dateResumes);
            $record->dateLastPayment = Db::prepareDateForDb($this->dateLastPayment);
            $record->cycleCount = $this->cycleCount;
            $record->prepaidCyclesRemaining = $this->prepaidCyclesRemaining;
            $record->skipsUsed = $this->skipsUsed;
            $record->pausesUsed = $this->pausesUsed;
            $record->failureCount = $this->failureCount;
            $record->dunningStage = $this->dunningStage;
            $record->dateNextRetry = Db::prepareDateForDb($this->dateNextRetry);
            $record->lastFailureMessage = $this->lastFailureMessage;
            $record->isManual = $this->isManual;
            $record->autoRenew = $this->autoRenew;
            $record->cancelReason = $this->cancelReason;
            $record->note = $this->note;
            $record->save(false);
        }

        parent::afterSave($isNew);
    }

    public static function generateReference(): string
    {
        return strtoupper(StringHelper::randomString(4) . '-' . StringHelper::randomString(4));
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['quantity'], 'integer', 'min' => 1, 'max' => 9999],
            [['renewalPrice'], 'number', 'min' => 0],
            [['currency'], 'string', 'length' => 3],
            [['subscriptionStatus'], 'in', 'range' => array_keys(self::statuses())],
        ]);
    }

    // Element index
    // -------------------------------------------------------------------------

    protected static function defineSources(string $context): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('subscribr', 'All subscriptions'),
                'criteria' => [],
                'defaultSort' => ['dateNextPayment', 'asc'],
            ],
            ['heading' => Craft::t('subscribr', 'Status')],
        ];

        foreach ([
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_TRIALING => 'Trialing',
            self::STATUS_PAST_DUE => 'Past due',
            self::STATUS_PAUSED => 'Paused',
            self::STATUS_CANCELED => 'Cancelling',
            self::STATUS_EXPIRED => 'Ended',
        ] as $status => $label) {
            $sources[] = [
                'key' => 'status:' . $status,
                'label' => Craft::t('subscribr', $label),
                'criteria' => ['subscriptionStatus' => $status],
                'defaultSort' => ['dateNextPayment', 'asc'],
            ];
        }

        $sources[] = [
            'key' => 'renewing',
            'label' => Craft::t('subscribr', 'Renewing in 7 days'),
            'criteria' => ['renewingWithin' => 7],
            'defaultSort' => ['dateNextPayment', 'asc'],
        ];

        $plans = Plugin::getInstance()->getPlans()->getAllPlans();

        if ($plans !== []) {
            $sources[] = ['heading' => Craft::t('subscribr', 'Plans')];

            foreach ($plans as $plan) {
                $sources[] = [
                    'key' => 'plan:' . $plan->id,
                    'label' => $plan->name,
                    'criteria' => ['planId' => $plan->id],
                ];
            }
        }

        return $sources;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'reference' => ['label' => Craft::t('subscribr', 'Reference')],
            'subscriber' => ['label' => Craft::t('subscribr', 'Subscriber')],
            'plan' => ['label' => Craft::t('subscribr', 'Plan')],
            'renewalPrice' => ['label' => Craft::t('subscribr', 'Renewal')],
            'cadence' => ['label' => Craft::t('subscribr', 'Cadence')],
            'dateNextPayment' => ['label' => Craft::t('subscribr', 'Next payment')],
            'cycleCount' => ['label' => Craft::t('subscribr', 'Cycles')],
            'gateway' => ['label' => Craft::t('subscribr', 'Gateway')],
            'failureCount' => ['label' => Craft::t('subscribr', 'Failures')],
            'dateStarted' => ['label' => Craft::t('subscribr', 'Started')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['subscriber', 'plan', 'renewalPrice', 'cadence', 'dateNextPayment', 'cycleCount'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'dateNextPayment' => Craft::t('subscribr', 'Next payment'),
            'dateStarted' => Craft::t('subscribr', 'Started'),
            'renewalPrice' => Craft::t('subscribr', 'Renewal price'),
            'cycleCount' => Craft::t('subscribr', 'Cycles'),
            'failureCount' => Craft::t('subscribr', 'Failures'),
            'elements.dateCreated' => Craft::t('app', 'Date Created'),
        ];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['reference', 'note'];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'reference' => Html::tag('code', $this->reference),
            'subscriber' => Html::encode((string)($this->getSubscriber() ?? Craft::t('subscribr', 'Guest'))),
            'plan' => Html::encode($this->getPlan()->name ?? '—'),
            'renewalPrice' => $this->getRenewalPriceAsCurrency(),
            'cadence' => Html::encode($this->getCadence()->describe()),
            'gateway' => Html::encode($this->getGateway()->name ?? '—'),
            'failureCount' => $this->failureCount > 0
                ? Html::tag('span', (string)$this->failureCount, ['class' => 'error'])
                : '0',
            default => parent::attributeHtml($attribute),
        };
    }

    protected static function defineActions(string $source): array
    {
        return [
            \justinholtweb\subscribr\elements\actions\PauseSubscription::class,
            \justinholtweb\subscribr\elements\actions\ResumeSubscription::class,
            \justinholtweb\subscribr\elements\actions\CancelSubscription::class,
            \justinholtweb\subscribr\elements\actions\RetryPayment::class,
        ];
    }
}
