<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\services;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use DateTime;
use justinholtweb\subscribr\db\Table;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\helpers\Money;
use justinholtweb\subscribr\models\Box;
use justinholtweb\subscribr\models\BoxSlot;
use justinholtweb\subscribr\models\Item;
use justinholtweb\subscribr\Plugin;
use justinholtweb\subscribr\records\BoxRecord;
use justinholtweb\subscribr\records\BoxSlotRecord;
use yii\base\Component;

/**
 * Subscription boxes.
 *
 * Three jobs: keep the definitions, decide whether a proposed selection is legal, and produce the
 * contents of a given cycle.
 *
 * The last one is where the modes stop being cosmetic. `curated` means the merchant's standing
 * items ship; `choice` means the subscriber's do, and the merchant's are the fallback for a
 * subscriber who never picked; `surprise` means Subscribr picks, avoiding what they have already
 * had. All three go through `contentsForCycle()`, so the renewal engine does not know or care.
 */
class Boxes extends Component
{
    /** @var Box[]|null */
    private ?array $_allBoxes = null;

    /** @var array<int, BoxSlot[]> */
    private array $_slotsByBox = [];

    // Storage
    // -------------------------------------------------------------------------

    /** @return Box[] */
    public function getAllBoxes(): array
    {
        if ($this->_allBoxes === null) {
            $rows = (new Query())->select('*')->from([Table::BOXES])
                ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
                ->all();

            $this->_allBoxes = array_map(fn(array $row): Box => $this->_toBox($row), $rows);
        }

        return $this->_allBoxes;
    }

    public function getBoxById(int $id): ?Box
    {
        foreach ($this->getAllBoxes() as $box) {
            if ($box->id === $id) {
                return $box;
            }
        }

        return null;
    }

    public function getBoxByHandle(string $handle): ?Box
    {
        foreach ($this->getAllBoxes() as $box) {
            if ($box->handle === $handle) {
                return $box;
            }
        }

        return null;
    }

    /** @return BoxSlot[] */
    public function getSlotsByBoxId(int $boxId): array
    {
        if (!isset($this->_slotsByBox[$boxId])) {
            $rows = (new Query())->select('*')->from([Table::BOXSLOTS])
                ->where(['boxId' => $boxId])
                ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
                ->all();

            $this->_slotsByBox[$boxId] = array_map(fn(array $row): BoxSlot => $this->_toSlot($row), $rows);
        }

        return $this->_slotsByBox[$boxId];
    }

    public function saveBox(Box $box, bool $runValidation = true): bool
    {
        if ($runValidation && !$box->validate()) {
            return false;
        }

        $record = $box->id ? BoxRecord::findOne($box->id) : new BoxRecord();

        if ($record === null) {
            $record = new BoxRecord();
        }

        $record->storeId = $box->storeId ?? Commerce::getInstance()?->getStores()->getPrimaryStore()->id;
        $record->name = $box->name;
        $record->handle = $box->handle;
        $record->description = $box->description;
        $record->mode = $box->mode;
        $record->pricing = $box->pricing;
        $record->boxPrice = $box->boxPrice;
        $record->minItems = $box->minItems;
        $record->maxItems = $box->maxItems;
        $record->allowSwap = $box->allowSwap;
        $record->lockHours = $box->lockHours;
        $record->avoidRepeatCycles = $box->avoidRepeatCycles;
        $record->enabled = $box->enabled;
        $record->sortOrder = $box->sortOrder ?? ((int)(new Query())->from([Table::BOXES])->max('[[sortOrder]]') + 1);

        if (!$record->save()) {
            $box->addErrors($record->getErrors());

            return false;
        }

        $box->id = (int)$record->id;
        $box->uid = $record->uid;

        $this->_saveSlots($box);

        $this->_allBoxes = null;
        unset($this->_slotsByBox[$box->id]);

        return true;
    }

    public function deleteBoxById(int $id): bool
    {
        $record = BoxRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        $record->delete();
        $this->_allBoxes = null;
        unset($this->_slotsByBox[$id]);

        return true;
    }

    // Selection
    // -------------------------------------------------------------------------

    /**
     * Whether a proposed selection is legal, and why not if it is not.
     *
     * `$selection` is `[slotId => [purchasableId => qty]]`. Errors are returned rather than thrown
     * because the caller is always a form, and a form wants to say all of what is wrong at once.
     *
     * @return string[] Empty when the selection is valid.
     */
    public function validateSelection(Box $box, array $selection): array
    {
        $errors = [];
        $total = 0;

        foreach ($box->getSlots() as $slot) {
            $chosen = $selection[$slot->id] ?? [];
            $count = 0;

            foreach ($chosen as $purchasableId => $qty) {
                $qty = max(0, (int)$qty);

                if ($qty === 0) {
                    continue;
                }

                if (!$slot->accepts((int)$purchasableId)) {
                    $errors[] = Craft::t('subscribr', '“{slot}” does not take that item.', ['slot' => $slot->name]);
                    continue;
                }

                $count += $qty;
            }

            if ($count < $slot->minItems) {
                $errors[] = Craft::t('subscribr', '“{slot}” needs at least {n, plural, =1{one item} other{# items}}.', [
                    'slot' => $slot->name,
                    'n' => $slot->minItems,
                ]);
            }

            if ($count > $slot->maxItems) {
                $errors[] = Craft::t('subscribr', '“{slot}” takes at most {n, plural, =1{one item} other{# items}}.', [
                    'slot' => $slot->name,
                    'n' => $slot->maxItems,
                ]);
            }

            $total += $count;
        }

        [$min, $max] = $box->getBounds();

        if ($min > 0 && $total < $min) {
            $errors[] = Craft::t('subscribr', 'This box needs at least {n} items.', ['n' => $min]);
        }

        if ($max > 0 && $total > $max) {
            $errors[] = Craft::t('subscribr', 'This box holds at most {n} items.', ['n' => $max]);
        }

        return $errors;
    }

    /**
     * Turn a selection into items.
     *
     * `$cycle` null makes them standing items — the new normal for every cycle. An integer makes
     * them a swap for that cycle only.
     *
     * @return Item[]
     */
    public function selectionToItems(Box $box, array $selection, ?int $cycle = null): array
    {
        $items = [];
        $purchasables = Commerce::getInstance()->getPurchasables();
        $sortOrder = 0;

        foreach ($box->getSlots() as $slot) {
            foreach (($selection[$slot->id] ?? []) as $purchasableId => $qty) {
                $qty = max(0, (int)$qty);

                if ($qty === 0) {
                    continue;
                }

                $purchasable = $purchasables->getPurchasableById((int)$purchasableId);

                if ($purchasable === null) {
                    continue;
                }

                $item = new Item();
                $item->purchasableId = (int)$purchasableId;
                $item->boxSlotId = $slot->id;
                $item->cycle = $cycle;
                $item->qty = $qty;
                // A slot's contents are priced at zero when the box has one price of its own; the
                // box line carries the money. Otherwise each item is priced normally, and an
                // add-on slot is always priced whatever the box's mode.
                $item->price = $this->_itemPrice($box, $slot, (float)$purchasable->getPrice());
                $item->description = (string)$purchasable->getDescription();
                $item->sku = (string)$purchasable->getSku();
                $item->sortOrder = $sortOrder++;
                $item->setSnapshot([
                    'slot' => $slot->name,
                    'boxHandle' => $box->handle,
                    'listPrice' => (float)$purchasable->getPrice(),
                ]);

                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * What actually ships for a cycle.
     *
     * @return Item[]
     */
    public function contentsForCycle(Subscription $subscription, int $cycle): array
    {
        $box = $subscription->getBox();

        if ($box === null) {
            return $subscription->getItemsForCycle($cycle);
        }

        $items = $subscription->getItemsForCycle($cycle);

        if ($items !== []) {
            return $items;
        }

        if ($box->mode === Box::MODE_SURPRISE) {
            return $this->surpriseSelection($subscription, $box, $cycle);
        }

        // A choice box whose subscriber never chose. The setting decides between shipping the
        // merchant's defaults and failing the cycle, because a curation business and an
        // allergy-sensitive one want opposite things and neither is wrong.
        if (!Plugin::getInstance()->getSettings()->shipDefaultsWhenUnchosen && $box->getIsSubscriberChoice()) {
            return [];
        }

        return $this->defaultsFor($box);
    }

    /**
     * The merchant's default contents: the first acceptable purchasable in each slot, up to its
     * minimum.
     *
     * @return Item[]
     */
    public function defaultsFor(Box $box): array
    {
        $selection = [];

        foreach ($box->getSlots() as $slot) {
            $ids = $slot->getPurchasableIds();

            if ($ids === [] || $slot->minItems < 1) {
                continue;
            }

            $selection[$slot->id] = [$ids[0] => $slot->minItems];
        }

        return $this->selectionToItems($box, $selection);
    }

    /**
     * A random legal selection, avoiding what the subscriber has had recently.
     *
     * @return Item[]
     */
    public function surpriseSelection(Subscription $subscription, Box $box, int $cycle): array
    {
        $recent = $this->_recentPurchasableIds($subscription, $box->avoidRepeatCycles);
        $selection = [];

        foreach ($box->getSlots() as $slot) {
            $pool = $slot->getPurchasableIds();

            if ($pool === []) {
                continue;
            }

            $fresh = array_values(array_diff($pool, $recent));

            // Exhausting the pool must not produce an empty box. When everything has been sent
            // recently, the whole pool comes back — a repeat is better than nothing in the parcel.
            $candidates = $fresh !== [] ? $fresh : $pool;
            shuffle($candidates);

            $take = array_slice($candidates, 0, max(1, $slot->minItems));
            $selection[$slot->id] = array_fill_keys($take, 1);
        }

        return $this->selectionToItems($box, $selection, $cycle);
    }

    /**
     * What a set of box contents costs, given the box's pricing mode.
     */
    public function priceContents(Box $box, array $items): float
    {
        $slots = [];

        foreach ($box->getSlots() as $slot) {
            $slots[$slot->id] = $slot;
        }

        $total = 0.0;

        foreach ($items as $item) {
            $total += $item->getSubtotal();
        }

        if ($box->pricing === Box::PRICING_CONTENTS) {
            return Money::round($total);
        }

        // Fixed and base both start from the box price. Under `base`, add-on slots are extra;
        // under `fixed`, nothing is.
        $price = (float)($box->boxPrice ?? 0);

        if ($box->pricing === Box::PRICING_BASE) {
            foreach ($items as $item) {
                $slot = $item->boxSlotId ? ($slots[$item->boxSlotId] ?? null) : null;

                if ($slot?->isAddOn) {
                    $price += $item->getSubtotal();
                }
            }
        }

        return Money::round($price);
    }

    /**
     * Whether the contents of the next cycle are still editable.
     *
     * Locking is a promise to the warehouse, not a punishment: past the lock the parcel is already
     * being picked, and a swap that arrived after the label was printed would be a swap the
     * subscriber was told had worked.
     */
    public function contentsAreLocked(Subscription $subscription, ?DateTime $now = null): bool
    {
        $box = $subscription->getBox();

        if ($box === null || $subscription->dateNextPayment === null) {
            return false;
        }

        $now ??= new DateTime();
        $lockFrom = (clone $subscription->dateNextPayment)->modify('-' . $box->lockHours . ' hours');

        return $now >= $lockFrom;
    }

    // Internals
    // -------------------------------------------------------------------------

    private function _itemPrice(Box $box, BoxSlot $slot, float $listPrice): float
    {
        if ($box->pricing === Box::PRICING_CONTENTS) {
            return $listPrice;
        }

        if ($box->pricing === Box::PRICING_BASE && $slot->isAddOn) {
            return $listPrice;
        }

        return 0.0;
    }

    /** @return int[] */
    private function _recentPurchasableIds(Subscription $subscription, int $cycles): array
    {
        if ($cycles < 1 || !$subscription->id) {
            return [];
        }

        $from = max(0, $subscription->cycleCount - $cycles);

        return array_map('intval', (new Query())
            ->select(['purchasableId'])
            ->from([Table::ITEMS])
            ->where(['subscriptionId' => $subscription->id])
            ->andWhere(['>=', 'cycle', $from])
            ->andWhere(['not', ['purchasableId' => null]])
            ->column());
    }

    private function _saveSlots(Box $box): void
    {
        $keptIds = [];

        foreach ($box->getSlots() as $order => $slot) {
            $record = $slot->id ? BoxSlotRecord::findOne($slot->id) : new BoxSlotRecord();

            if ($record === null) {
                $record = new BoxSlotRecord();
            }

            $record->boxId = $box->id;
            $record->name = $slot->name;
            $record->minItems = $slot->minItems;
            $record->maxItems = $slot->maxItems;
            $record->sources = Json::encode($slot->getSources());
            $record->isAddOn = $slot->isAddOn;
            $record->sortOrder = $order;
            $record->save(false);

            $slot->id = (int)$record->id;
            $keptIds[] = $slot->id;
        }

        // Anything the editor removed. Deleted rather than soft-deleted: a slot with no box is
        // not a thing, and the items that referenced it keep their own description and price.
        $condition = ['boxId' => $box->id];

        if ($keptIds !== []) {
            $condition = ['and', $condition, ['not in', 'id', $keptIds]];
        }

        Craft::$app->getDb()->createCommand()->delete(Table::BOXSLOTS, $condition)->execute();
    }

    private function _toBox(array $row): Box
    {
        $box = new Box();
        $box->id = (int)$row['id'];
        $box->storeId = isset($row['storeId']) ? (int)$row['storeId'] : null;
        $box->name = (string)$row['name'];
        $box->handle = (string)$row['handle'];
        $box->description = $row['description'] ?? null;
        $box->mode = (string)$row['mode'];
        $box->pricing = (string)$row['pricing'];
        $box->boxPrice = isset($row['boxPrice']) ? (float)$row['boxPrice'] : null;
        $box->minItems = isset($row['minItems']) ? (int)$row['minItems'] : null;
        $box->maxItems = isset($row['maxItems']) ? (int)$row['maxItems'] : null;
        $box->allowSwap = (bool)$row['allowSwap'];
        $box->lockHours = (int)$row['lockHours'];
        $box->avoidRepeatCycles = (int)$row['avoidRepeatCycles'];
        $box->enabled = (bool)$row['enabled'];
        $box->sortOrder = isset($row['sortOrder']) ? (int)$row['sortOrder'] : null;
        $box->dateCreated = isset($row['dateCreated']) ? DateTimeHelper::toDateTime($row['dateCreated']) ?: null : null;
        $box->uid = $row['uid'] ?? null;

        return $box;
    }

    private function _toSlot(array $row): BoxSlot
    {
        $slot = new BoxSlot();
        $slot->id = (int)$row['id'];
        $slot->boxId = (int)$row['boxId'];
        $slot->name = (string)$row['name'];
        $slot->minItems = (int)$row['minItems'];
        $slot->maxItems = (int)$row['maxItems'];
        $slot->setSources($row['sources'] ?? null);
        $slot->isAddOn = (bool)$row['isAddOn'];
        $slot->sortOrder = isset($row['sortOrder']) ? (int)$row['sortOrder'] : null;
        $slot->uid = $row['uid'] ?? null;

        return $slot;
    }
}
