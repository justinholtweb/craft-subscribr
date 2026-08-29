<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use justinholtweb\subscribr\db\Table;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\models\Gift;
use justinholtweb\subscribr\models\LogEntry;
use justinholtweb\subscribr\Plugin;
use justinholtweb\subscribr\records\GiftRecord;
use yii\base\Component;

/**
 * Gift subscriptions.
 *
 * The hard part of a gift is not the payment, it is that the buyer and the user are different
 * people and the second one may not exist yet.
 *
 * So a gifted subscription is created `pending` against the **purchaser**, and the claim moves it.
 * Three things follow, all of them things a customer would otherwise complain about:
 *
 * - The clock does not start at purchase. A subscription bought in November for Christmas has not
 *   quietly used a month of itself by the time it is opened.
 * - The gift is visible and refundable before it is claimed. It belongs to somebody.
 * - It does not renew. `autoRenew` is off and the cycle count is what was paid for. A gift that
 *   silently started billing the giver's card in month thirteen would be indefensible; the
 *   recipient can turn renewal on themselves, on their own card, once they own it.
 */
class Gifts extends Component
{
    public function createFromOrder(Subscription $subscription, Order $order, array $attributes): Gift
    {
        $gift = new Gift([
            'subscriptionId' => $subscription->id,
            'orderId' => $order->id,
            'purchaserId' => $order->getCustomer()?->id,
            'recipientEmail' => (string)($attributes['recipientEmail'] ?? ''),
            'recipientName' => $attributes['recipientName'] ?? null,
            'senderName' => $attributes['senderName'] ?? (string)($order->getCustomer() ?? ''),
            'message' => $attributes['message'] ?? null,
            'cycles' => max(1, (int)($attributes['cycles'] ?? 1)),
            'token' => Gift::generateToken(),
        ]);

        if (!empty($attributes['deliverOn'])) {
            $gift->dateDeliver = DateTimeHelper::toDateTime($attributes['deliverOn']) ?: null;
        }

        // A year to claim it. Not for ever: an unclaimed gift is a liability on the store's books
        // and one that never expires never leaves them.
        $gift->dateExpires = (new DateTime())->modify('+1 year');

        $this->saveGift($gift);

        $subscription->autoRenew = false;
        $subscription->prepaidCyclesRemaining = max(0, $gift->cycles - 1);
        Plugin::getInstance()->getSubscriptions()->save($subscription);

        Plugin::getInstance()->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_GIFTED,
            Craft::t('subscribr', 'Bought as a gift for {email}; {n, plural, =1{one cycle} other{# cycles}}.', [
                'email' => $gift->recipientEmail,
                'n' => $gift->cycles,
            ]),
            ['giftId' => $gift->id],
        );

        // Delivered now unless it is booked for a date. The console command sweeps the booked ones.
        if ($gift->dateDeliver === null) {
            $this->deliver($gift);
        }

        return $gift;
    }

    public function saveGift(Gift $gift, bool $runValidation = true): bool
    {
        if ($runValidation && !$gift->validate()) {
            return false;
        }

        $record = $gift->id ? GiftRecord::findOne($gift->id) : new GiftRecord();

        if ($record === null) {
            $record = new GiftRecord();
        }

        $record->subscriptionId = $gift->subscriptionId;
        $record->orderId = $gift->orderId;
        $record->purchaserId = $gift->purchaserId;
        $record->recipientId = $gift->recipientId;
        $record->recipientEmail = $gift->recipientEmail;
        $record->recipientName = $gift->recipientName;
        $record->senderName = $gift->senderName;
        $record->message = $gift->message;
        $record->token = $gift->token ?: Gift::generateToken();
        $record->cycles = $gift->cycles;
        $record->dateDeliver = Db::prepareDateForDb($gift->dateDeliver);
        $record->dateDelivered = Db::prepareDateForDb($gift->dateDelivered);
        $record->dateClaimed = Db::prepareDateForDb($gift->dateClaimed);
        $record->dateExpires = Db::prepareDateForDb($gift->dateExpires);

        if (!$record->save()) {
            $gift->addErrors($record->getErrors());

            return false;
        }

        $gift->id = (int)$record->id;
        $gift->token = $record->token;

        return true;
    }

    public function getGiftByToken(string $token): ?Gift
    {
        $row = $this->_query()->where(['token' => $token])->one();

        return $row ? $this->_toModel($row) : null;
    }

    public function getGiftBySubscriptionId(int $subscriptionId): ?Gift
    {
        $row = $this->_query()->where(['subscriptionId' => $subscriptionId])->one();

        return $row ? $this->_toModel($row) : null;
    }

    /** @return Gift[] */
    public function getGiftsForPurchaser(int $userId): array
    {
        $rows = $this->_query()->where(['purchaserId' => $userId])->orderBy(['dateCreated' => SORT_DESC])->all();

        return array_map(fn(array $row): Gift => $this->_toModel($row), $rows);
    }

    /**
     * Gifts whose delivery date has come.
     *
     * @return Gift[]
     */
    public function getDeliverable(?DateTime $now = null): array
    {
        $now ??= new DateTime();

        $rows = $this->_query()
            ->where(['dateDelivered' => null])
            ->andWhere(['not', ['dateDeliver' => null]])
            ->andWhere(['<=', 'dateDeliver', Db::prepareDateForDb($now)])
            ->all();

        return array_map(fn(array $row): Gift => $this->_toModel($row), $rows);
    }

    public function deliver(Gift $gift): bool
    {
        if ($gift->getIsDelivered()) {
            return true;
        }

        $gift->dateDelivered = new DateTime();
        $this->saveGift($gift, false);

        Plugin::getInstance()->getNotifications()->sendGift($gift);

        return true;
    }

    /**
     * Claim a gift.
     *
     * The subscription changes hands: the recipient becomes the subscriber and the clock starts.
     * The purchaser's payment details do **not** come with it — the gift is prepaid, and if the
     * recipient later chooses to keep it going they do so on their own card.
     *
     * @return array{0: Subscription|null, 1: string|null}
     */
    public function claim(Gift $gift, User $recipient): array
    {
        if (!$gift->getIsClaimable()) {
            return [null, $gift->getIsClaimed()
                ? Craft::t('subscribr', 'This gift has already been claimed.')
                : Craft::t('subscribr', 'This gift is no longer available.')];
        }

        $plugin = Plugin::getInstance();
        $subscription = $plugin->getSubscriptions()->getSubscriptionById((int)$gift->subscriptionId);

        if ($subscription === null) {
            return [null, Craft::t('subscribr', 'This gift no longer points at a subscription.')];
        }

        $gift->recipientId = (int)$recipient->id;
        $gift->dateClaimed = new DateTime();
        $this->saveGift($gift, false);

        $subscription->userId = (int)$recipient->id;
        // The purchaser's stored card does not transfer. A gift is paid for; nothing about it may
        // charge anybody again without them asking.
        $subscription->paymentSourceId = null;
        $subscription->autoRenew = false;
        $plugin->getSubscriptions()->save($subscription);

        $plugin->getSubscriptions()->activate($subscription);

        $plugin->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_GIFT_CLAIMED,
            Craft::t('subscribr', 'Gift claimed by {name}.', ['name' => (string)$recipient]),
            ['giftId' => $gift->id, 'userId' => $recipient->id],
            null,
            (int)$recipient->id,
        );

        return [$subscription, null];
    }

    private function _query(): Query
    {
        return (new Query())->select('*')->from([Table::GIFTS]);
    }

    private function _toModel(array $row): Gift
    {
        $gift = new Gift();
        $gift->id = (int)$row['id'];
        $gift->subscriptionId = isset($row['subscriptionId']) ? (int)$row['subscriptionId'] : null;
        $gift->orderId = isset($row['orderId']) ? (int)$row['orderId'] : null;
        $gift->purchaserId = isset($row['purchaserId']) ? (int)$row['purchaserId'] : null;
        $gift->recipientId = isset($row['recipientId']) ? (int)$row['recipientId'] : null;
        $gift->recipientEmail = (string)$row['recipientEmail'];
        $gift->recipientName = $row['recipientName'] ?? null;
        $gift->senderName = $row['senderName'] ?? null;
        $gift->message = $row['message'] ?? null;
        $gift->token = (string)$row['token'];
        $gift->cycles = (int)$row['cycles'];
        $gift->dateDeliver = isset($row['dateDeliver']) ? DateTimeHelper::toDateTime($row['dateDeliver']) ?: null : null;
        $gift->dateDelivered = isset($row['dateDelivered']) ? DateTimeHelper::toDateTime($row['dateDelivered']) ?: null : null;
        $gift->dateClaimed = isset($row['dateClaimed']) ? DateTimeHelper::toDateTime($row['dateClaimed']) ?: null : null;
        $gift->dateExpires = isset($row['dateExpires']) ? DateTimeHelper::toDateTime($row['dateExpires']) ?: null : null;
        $gift->dateCreated = isset($row['dateCreated']) ? DateTimeHelper::toDateTime($row['dateCreated']) ?: null : null;
        $gift->uid = $row['uid'] ?? null;

        return $gift;
    }
}
