<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\models;

use craft\base\Model;
use craft\commerce\base\Purchasable;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Json;
use DateTime;

/**
 * One recurring line on a subscription.
 *
 * `cycle` is what makes swap-a-shipment work. A row with a null cycle is what ships every time; a
 * row pinned to cycle 7 replaces the standing set for cycle 7 only, and is consumed when that
 * cycle renews. So "swap next month's coffee for the decaf" is a set of rows, not a mutation of
 * the subscription, and the month after goes back to normal without anybody having to remember to
 * put it back.
 */
class Item extends Model
{
    public ?int $id = null;
    public ?int $subscriptionId = null;
    public ?int $purchasableId = null;
    public ?int $boxSlotId = null;
    public ?int $cycle = null;
    public int $qty = 1;
    public float $price = 0.0;
    public ?string $description = null;
    public ?string $sku = null;
    public array $options = [];
    public array $snapshot = [];
    public ?int $sortOrder = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function setOptions(array|string|null $options): void
    {
        if (is_string($options)) {
            $options = Json::decodeIfJson($options);
        }

        $this->options = is_array($options) ? $options : [];
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setSnapshot(array|string|null $snapshot): void
    {
        if (is_string($snapshot)) {
            $snapshot = Json::decodeIfJson($snapshot);
        }

        $this->snapshot = is_array($snapshot) ? $snapshot : [];
    }

    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    public function getSubtotal(): float
    {
        return round($this->price * $this->qty, 4);
    }

    /**
     * The live purchasable, or null if it has been deleted since.
     *
     * A deleted purchasable is not fatal — the description, SKU and price are all on the row, so a
     * renewal can still be billed and the subscriber still knows what they paid for. It is the
     * *shipment* that has a problem, and the renewal reports it rather than throwing.
     */
    public function getPurchasable(): ?Purchasable
    {
        if (!$this->purchasableId) {
            return null;
        }

        return Commerce::getInstance()->getPurchasables()->getPurchasableById($this->purchasableId);
    }

    public function getIsStanding(): bool
    {
        return $this->cycle === null;
    }

    protected function defineRules(): array
    {
        return [
            [['qty'], 'integer', 'min' => 1, 'max' => 9999],
            [['price'], 'number', 'min' => 0],
        ];
    }
}
