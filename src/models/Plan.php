<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\models;

use Craft;
use craft\base\Model;
use craft\commerce\base\Purchasable;
use craft\helpers\UrlHelper;
use craft\validators\HandleValidator;
use DateTime;
use justinholtweb\subscribr\Plugin;

/**
 * A recurring plan.
 *
 * A Subscribr plan is **not** a plan held at a gateway. It is the store's own description of a
 * recurring arrangement — how often, for how long, at what price, with what the subscriber is
 * allowed to do to it. Nothing in here needs a gateway's permission to exist, which is what makes
 * a subscription possible on a gateway that has never heard of subscriptions.
 *
 * A plan is attached to a purchasable at the point it goes in the cart, not owned by one, so the
 * same coffee can be sold once, monthly, or in a box, from one product.
 *
 * @property-read Cadence $cadence
 * @property-read Box|null $box
 * @property-read DunningProfile|null $dunningProfile
 */
class Plan extends Model
{
    public const PRICING_INHERIT = 'inherit';
    public const PRICING_LOCKED = 'locked';
    public const PRICING_OVERRIDE = 'override';

    public const PRICING_MODES = [self::PRICING_INHERIT, self::PRICING_LOCKED, self::PRICING_OVERRIDE];

    public const CANCEL_END = 'end';
    public const CANCEL_IMMEDIATE = 'immediate';

    public const SWITCH_PRORATE = 'prorate';
    public const SWITCH_END = 'end';

    public ?int $id = null;
    public ?int $storeId = null;
    public ?int $boxId = null;
    public ?int $dunningId = null;

    public string $name = '';
    public string $handle = '';
    public ?string $description = null;

    public string $interval = Cadence::MONTH;
    public int $intervalCount = 1;
    public ?int $anchorDay = null;
    public int $trialDays = 0;
    public ?float $signupFee = null;
    public int $maxCycles = 0;

    public string $pricingMode = self::PRICING_INHERIT;
    public ?float $planPrice = null;
    public ?float $discountPercent = null;

    public ?string $prepaidOptions = null;
    public ?float $prepaidDiscountPercent = null;

    public bool $allowPause = true;
    public int $maxPauseCycles = 0;
    public bool $allowSkip = true;
    public int $maxSkipsPerYear = 0;
    public bool $allowSwap = true;
    public bool $allowSwitch = true;
    public bool $allowCancel = true;
    public bool $allowGift = false;
    public bool $allowQuantityChange = true;

    public string $cancelMode = self::CANCEL_END;
    public string $switchMode = self::SWITCH_PRORATE;
    public ?string $switchGroup = null;

    public bool $shippable = true;
    public bool $enabled = true;
    public ?int $sortOrder = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?Cadence $_cadence = null;

    public function __toString(): string
    {
        return $this->name;
    }

    public function getCadence(): Cadence
    {
        return $this->_cadence ??= Cadence::fromPlan($this);
    }

    public function getBox(): ?Box
    {
        return $this->boxId ? Plugin::getInstance()->getBoxes()->getBoxById($this->boxId) : null;
    }

    public function getDunningProfile(): ?DunningProfile
    {
        return $this->dunningId ? Plugin::getInstance()->getDunning()->getProfileById($this->dunningId) : null;
    }

    public function getHasTrial(): bool
    {
        return $this->trialDays > 0;
    }

    public function getIsPrepayable(): bool
    {
        return $this->getPrepaidCycleOptions() !== [];
    }

    /**
     * The cycle counts a subscriber may pay up front for, cleaned and sorted.
     *
     * @return int[]
     */
    public function getPrepaidCycleOptions(): array
    {
        if (!$this->prepaidOptions) {
            return [];
        }

        $options = array_map('intval', preg_split('/[\s,]+/', $this->prepaidOptions, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        // One cycle is not a prepayment, it is a subscription. Anything under two is noise.
        $options = array_values(array_unique(array_filter($options, static fn(int $n): bool => $n >= 2)));
        sort($options);

        return $options;
    }

    /**
     * What one cycle of `$purchasable` costs on this plan.
     *
     * `$lockedPrice` is what it cost when the subscription started, and is only consulted in
     * `locked` mode. It is passed in rather than read from the subscription so that the signup
     * preview — where there is no subscription yet — computes the same number.
     */
    public function priceFor(Purchasable|float $purchasable, ?float $lockedPrice = null): float
    {
        $base = match ($this->pricingMode) {
            self::PRICING_OVERRIDE => (float)($this->planPrice ?? 0),
            self::PRICING_LOCKED => $lockedPrice ?? $this->_basePrice($purchasable),
            default => $this->_basePrice($purchasable),
        };

        if ($this->discountPercent) {
            $base -= $base * ($this->discountPercent / 100);
        }

        return round(max(0, $base), 4);
    }

    /**
     * The multiplier applied to a prepaid signup: `$cycles` cycles, less the prepaid discount.
     */
    public function prepaidMultiplier(int $cycles): float
    {
        if ($cycles < 2) {
            return 1.0;
        }

        $multiplier = (float)$cycles;

        if ($this->prepaidDiscountPercent) {
            $multiplier -= $multiplier * ($this->prepaidDiscountPercent / 100);
        }

        return round(max(0, $multiplier), 6);
    }

    /**
     * Whether a subscriber may switch onto `$other`.
     *
     * An empty switch group means "any plan", which reads as permissive but is not: `allowSwitch`
     * still has to be on, and a store that never sets a group has one ladder of plans anyway.
     */
    public function canSwitchTo(self $other): bool
    {
        if (!$this->allowSwitch || !$other->enabled || $other->id === $this->id) {
            return false;
        }

        if (!$this->switchGroup) {
            return true;
        }

        return $this->switchGroup === $other->switchGroup;
    }

    public function getIsBox(): bool
    {
        return $this->boxId !== null;
    }

    public function describe(): string
    {
        return $this->getCadence()->describe();
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('subscribr/plans/' . $this->id);
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['handle'], HandleValidator::class],
            [['interval'], 'in', 'range' => Cadence::INTERVALS],
            [['intervalCount'], 'integer', 'min' => 1, 'max' => 365],
            [['anchorDay'], 'integer', 'min' => 0, 'max' => 28],
            [['trialDays'], 'integer', 'min' => 0, 'max' => 3650],
            [['maxCycles', 'maxPauseCycles', 'maxSkipsPerYear'], 'integer', 'min' => 0, 'max' => 3650],
            [['signupFee', 'planPrice'], 'number', 'min' => 0],
            [['discountPercent', 'prepaidDiscountPercent'], 'number', 'min' => 0, 'max' => 100],
            [['pricingMode'], 'in', 'range' => self::PRICING_MODES],
            [['cancelMode'], 'in', 'range' => [self::CANCEL_END, self::CANCEL_IMMEDIATE]],
            [['switchMode'], 'in', 'range' => [self::SWITCH_PRORATE, self::SWITCH_END]],
            [
                'planPrice',
                'required',
                'when' => fn(self $plan): bool => $plan->pricingMode === self::PRICING_OVERRIDE,
                'message' => Craft::t('subscribr', 'A plan that overrides the price needs one.'),
                'skipOnEmpty' => false,
            ],
            [
                'anchorDay',
                'validateAnchorDay',
                'skipOnEmpty' => false,
            ],
        ];
    }

    /**
     * A weekly plan anchored to "the 15th" is not a thing, and neither is a monthly one anchored
     * to "day 0". Both are easy to produce by switching the interval after setting the anchor,
     * which is why this is checked rather than assumed.
     */
    public function validateAnchorDay(string $attribute): void
    {
        if ($this->anchorDay === null) {
            return;
        }

        if ($this->interval === Cadence::DAY) {
            $this->addError($attribute, Craft::t('subscribr', 'A daily plan bills every day; it cannot be anchored.'));
            return;
        }

        if ($this->interval === Cadence::WEEK && $this->anchorDay > 6) {
            $this->addError($attribute, Craft::t('subscribr', 'A weekly plan is anchored to a day of the week, 0–6.'));
            return;
        }

        if (in_array($this->interval, [Cadence::MONTH, Cadence::YEAR], true) && ($this->anchorDay < 1 || $this->anchorDay > 28)) {
            // 29, 30 and 31 are excluded rather than clamped: a plan anchored to the 31st would
            // silently mean "the 28th" for one month in four, and a merchant should be told that
            // rather than discover it in February.
            $this->addError($attribute, Craft::t('subscribr', 'A monthly plan is anchored to a day from 1 to 28, so that every month has one.'));
        }
    }

    private function _basePrice(Purchasable|float $purchasable): float
    {
        return is_float($purchasable) ? $purchasable : (float)$purchasable->getPrice();
    }
}
