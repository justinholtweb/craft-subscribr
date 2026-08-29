<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\models;

use craft\base\Model;
use craft\commerce\base\Purchasable;
use craft\commerce\elements\Variant;
use craft\helpers\Json;

/**
 * One slot in a subscription box: "pick 2 coffees", "pick 1 mug", "add-ons, up to 3".
 */
class BoxSlot extends Model
{
    public ?int $id = null;
    public ?int $boxId = null;
    public string $name = '';
    public int $minItems = 1;
    public int $maxItems = 1;
    public bool $isAddOn = false;
    public ?int $sortOrder = null;
    public ?string $uid = null;

    /** @var array{purchasableIds?: int[], productTypeIds?: int[], categoryIds?: int[]} */
    public array $sources = [];

    public function setSources(array|string|null $sources): void
    {
        if (is_string($sources)) {
            $sources = Json::decodeIfJson($sources);
        }

        $this->sources = is_array($sources) ? $sources : [];
    }

    public function getSources(): array
    {
        return $this->sources;
    }

    /** @return int[] */
    public function getPurchasableIds(): array
    {
        return array_map('intval', $this->sources['purchasableIds'] ?? []);
    }

    /** @return int[] */
    public function getProductTypeIds(): array
    {
        return array_map('intval', $this->sources['productTypeIds'] ?? []);
    }

    /**
     * Whether a purchasable is allowed in this slot.
     *
     * An empty source list means "anything", which is the useful default for an add-on slot and a
     * deliberate one: a slot that allowed nothing until it was configured would make a
     * half-finished box silently unfulfillable.
     */
    public function accepts(Purchasable|int $purchasable): bool
    {
        $id = is_int($purchasable) ? $purchasable : (int)$purchasable->id;
        $ids = $this->getPurchasableIds();
        $typeIds = $this->getProductTypeIds();

        if ($ids === [] && $typeIds === []) {
            return true;
        }

        if (in_array($id, $ids, true)) {
            return true;
        }

        if ($typeIds === []) {
            return false;
        }

        $element = is_int($purchasable)
            ? Variant::find()->id($id)->status(null)->one()
            : $purchasable;

        if (!$element instanceof Variant) {
            return false;
        }

        return in_array((int)$element->getOwner()?->typeId, $typeIds, true);
    }

    protected function defineRules(): array
    {
        return [
            [['name'], 'required'],
            [['minItems', 'maxItems'], 'integer', 'min' => 0, 'max' => 999],
            [
                'maxItems',
                'compare',
                'compareAttribute' => 'minItems',
                'operator' => '>=',
                'message' => 'The most a slot may hold cannot be fewer than the least it must hold.',
                'skipOnEmpty' => false,
            ],
        ];
    }
}
