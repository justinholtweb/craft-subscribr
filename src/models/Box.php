<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\models;

use Craft;
use craft\base\Model;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\subscribr\Plugin;

/**
 * A subscription box: a set of slots, a way of filling them and a way of pricing them.
 *
 * The three modes are genuinely different products, not settings on one:
 *
 * - **curated** — the merchant fills the box for each cycle. The subscriber gets a surprise and
 *   Subscribr never asks them anything.
 * - **choice** — the subscriber fills the box within the slot rules. This is build-a-box.
 * - **surprise** — Subscribr fills the box from the slot sources at random, avoiding whatever the
 *   subscriber has had in the last `avoidRepeatCycles` cycles.
 *
 * @property-read BoxSlot[] $slots
 */
class Box extends Model
{
    public const MODE_CURATED = 'curated';
    public const MODE_CHOICE = 'choice';
    public const MODE_SURPRISE = 'surprise';

    public const MODES = [self::MODE_CURATED, self::MODE_CHOICE, self::MODE_SURPRISE];

    public const PRICING_CONTENTS = 'contents';
    public const PRICING_FIXED = 'fixed';
    public const PRICING_BASE = 'base';

    public const PRICING_MODES = [self::PRICING_CONTENTS, self::PRICING_FIXED, self::PRICING_BASE];

    public ?int $id = null;
    public ?int $storeId = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;
    public string $mode = self::MODE_CHOICE;
    public string $pricing = self::PRICING_CONTENTS;
    public ?float $boxPrice = null;
    public ?int $minItems = null;
    public ?int $maxItems = null;
    public bool $allowSwap = true;
    public int $lockHours = 24;
    public int $avoidRepeatCycles = 0;
    public bool $enabled = true;
    public ?int $sortOrder = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /** @var BoxSlot[]|null */
    private ?array $_slots = null;

    public function __toString(): string
    {
        return $this->name;
    }

    /** @return BoxSlot[] */
    public function getSlots(): array
    {
        if ($this->_slots === null) {
            $this->_slots = $this->id
                ? Plugin::getInstance()->getBoxes()->getSlotsByBoxId($this->id)
                : [];
        }

        return $this->_slots;
    }

    /** @param BoxSlot[] $slots */
    public function setSlots(array $slots): void
    {
        $this->_slots = array_values($slots);
    }

    public function getSlotById(int $id): ?BoxSlot
    {
        foreach ($this->getSlots() as $slot) {
            if ($slot->id === $id) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Whether the subscriber picks the contents.
     */
    public function getIsSubscriberChoice(): bool
    {
        return $this->mode === self::MODE_CHOICE;
    }

    /**
     * The smallest and largest number of items a valid selection may hold.
     *
     * The box's own min and max win when they are set, because a box that says "any 6 items" over
     * slots that each say "1 to 3" is a real configuration and the slots are then only a guide to
     * what may be picked, not a quota.
     */
    public function getBounds(): array
    {
        $slotMin = 0;
        $slotMax = 0;

        foreach ($this->getSlots() as $slot) {
            $slotMin += $slot->minItems;
            $slotMax += $slot->maxItems;
        }

        return [
            $this->minItems ?? $slotMin,
            $this->maxItems ?? $slotMax,
        ];
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('subscribr/boxes/' . $this->id);
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['handle'], 'craft\validators\HandleValidator'],
            [['mode'], 'in', 'range' => self::MODES],
            [['pricing'], 'in', 'range' => self::PRICING_MODES],
            [['boxPrice'], 'number', 'min' => 0],
            [['lockHours'], 'integer', 'min' => 0, 'max' => 8760],
            [['avoidRepeatCycles'], 'integer', 'min' => 0, 'max' => 60],
            [['minItems', 'maxItems'], 'integer', 'min' => 0, 'max' => 999],
            [
                'boxPrice',
                'required',
                'when' => fn(self $box): bool => in_array($box->pricing, [self::PRICING_FIXED, self::PRICING_BASE], true),
                'message' => Craft::t('subscribr', 'A fixed-price box needs a price.'),
                // Yii skips an inline/conditional validator when the attribute is empty, which is
                // exactly the case this rule exists to catch.
                'skipOnEmpty' => false,
            ],
        ];
    }
}
