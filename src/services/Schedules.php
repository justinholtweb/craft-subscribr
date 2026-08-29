<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use justinholtweb\subscribr\db\Table;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\models\LogEntry;
use justinholtweb\subscribr\models\ScheduledAction;
use justinholtweb\subscribr\Plugin;
use justinholtweb\subscribr\records\ScheduleRecord;
use yii\base\Component;

/**
 * Changes booked for a future cycle.
 *
 * Self-service is mostly *not* "do this now" — it is "do this next time". Skip my next box, pause
 * from March, put the decaf in cycle 7, move me down a plan at the boundary. Booking those rather
 * than mutating the subscription is what makes them reversible: the subscriber changes their mind
 * by cancelling a booking, and nothing has to be undone.
 *
 * It also gives the renewal engine one place to ask what this cycle is supposed to be, instead of
 * reading columns that somebody quietly rewrote a fortnight ago.
 */
class Schedules extends Component
{
    /**
     * Book an action.
     */
    public function book(
        Subscription $subscription,
        string $action,
        ?int $cycle = null,
        array $payload = [],
        ?DateTime $applyAt = null,
    ): ScheduledAction {
        // One pending action of a kind per cycle. Booking a second skip for the same box should
        // replace the first, not queue two skips that between them jump a cycle nobody meant to.
        $this->cancelPending($subscription, $action, $cycle);

        $record = new ScheduleRecord();
        $record->subscriptionId = $subscription->id;
        $record->action = $action;
        $record->cycle = $cycle;
        $record->applyAt = Db::prepareDateForDb($applyAt);
        $record->payload = $payload === [] ? null : Json::encode($payload);
        $record->createdByUserId = Craft::$app->getUser()->getIdentity()?->id;
        $record->save(false);

        return $this->_toModel($record->toArray());
    }

    /** @return ScheduledAction[] */
    public function getPending(int $subscriptionId): array
    {
        $rows = $this->_query()
            ->where(['subscriptionId' => $subscriptionId, 'appliedAt' => null, 'canceledAt' => null])
            ->orderBy(['cycle' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map(fn(array $row): ScheduledAction => $this->_toModel($row), $rows);
    }

    public function getPendingOfType(int $subscriptionId, string $action): ?ScheduledAction
    {
        foreach ($this->getPending($subscriptionId) as $pending) {
            if ($pending->action === $action) {
                return $pending;
            }
        }

        return null;
    }

    /**
     * Everything booked that is due at this boundary.
     *
     * @return ScheduledAction[]
     */
    public function getDue(Subscription $subscription, int $cycle, ?DateTime $now = null): array
    {
        $now ??= new DateTime();

        return array_values(array_filter(
            $this->getPending((int)$subscription->id),
            static fn(ScheduledAction $action): bool => $action->isDue($cycle, $now),
        ));
    }

    public function getById(int $id): ?ScheduledAction
    {
        $row = $this->_query()->where(['id' => $id])->one();

        return $row ? $this->_toModel($row) : null;
    }

    public function cancel(ScheduledAction $action): bool
    {
        if (!$action->id) {
            return false;
        }

        Craft::$app->getDb()->createCommand()
            ->update(Table::SCHEDULES, ['canceledAt' => Db::prepareDateForDb(new DateTime())], ['id' => $action->id])
            ->execute();

        return true;
    }

    public function cancelPending(Subscription $subscription, string $action, ?int $cycle = null): int
    {
        $condition = [
            'subscriptionId' => $subscription->id,
            'action' => $action,
            'appliedAt' => null,
            'canceledAt' => null,
        ];

        if ($cycle !== null) {
            $condition['cycle'] = $cycle;
        }

        return (int)Craft::$app->getDb()->createCommand()
            ->update(Table::SCHEDULES, ['canceledAt' => Db::prepareDateForDb(new DateTime())], $condition)
            ->execute();
    }

    public function markApplied(ScheduledAction $action): void
    {
        if (!$action->id) {
            return;
        }

        $now = new DateTime();
        $action->appliedAt = $now;

        Craft::$app->getDb()->createCommand()
            ->update(Table::SCHEDULES, ['appliedAt' => Db::prepareDateForDb($now)], ['id' => $action->id])
            ->execute();
    }

    /**
     * Apply a booked change that alters the cycle rather than replacing it.
     *
     * Swaps are deliberately *not* applied here: a swap is already a set of cycle-pinned items
     * written when it was booked, and the renewal reads them directly. Applying it again would
     * mean writing the same rows twice.
     */
    public function apply(Subscription $subscription, ScheduledAction $action): void
    {
        $plugin = Plugin::getInstance();
        $payload = $action->getPayload();

        switch ($action->action) {
            case ScheduledAction::SWITCH_PLAN:
                $plan = isset($payload['planId']) ? $plugin->getPlans()->getPlanById((int)$payload['planId']) : null;

                if ($plan !== null) {
                    $plugin->getProration()->applySwitch($subscription, $plan, true);
                }
                break;

            case ScheduledAction::QUANTITY:
                if (isset($payload['quantity'])) {
                    $plugin->getSubscriptions()->setQuantity($subscription, (int)$payload['quantity']);
                }
                break;

            case ScheduledAction::ADDRESS:
                if (isset($payload['shippingAddressId'])) {
                    $subscription->shippingAddressId = (int)$payload['shippingAddressId'];
                }

                if (isset($payload['billingAddressId'])) {
                    $subscription->billingAddressId = (int)$payload['billingAddressId'];
                }

                $plugin->getSubscriptions()->save($subscription);
                break;

            case ScheduledAction::RESUME:
                $plugin->getSubscriptions()->resume($subscription);
                break;

            case ScheduledAction::SWAP:
                $plugin->getLedger()->log(
                    (int)$subscription->id,
                    LogEntry::TYPE_SWAPPED,
                    Craft::t('subscribr', 'Swapped contents shipped for cycle {cycle}.', ['cycle' => $action->cycle]),
                );
                break;
        }

        $this->markApplied($action);
    }

    /**
     * Whether the subscriber is still allowed to change the next cycle.
     *
     * Two locks, and they are different: the store's own change window, and the box's pick-and-pack
     * lock. The tighter of the two wins, and the reason is returned so the portal can say which.
     *
     * @return array{0: bool, 1: string|null}
     */
    public function changeWindow(Subscription $subscription, ?DateTime $now = null): array
    {
        $now ??= new DateTime();
        $settings = Plugin::getInstance()->getSettings();

        if ($subscription->dateNextPayment === null) {
            return [true, null];
        }

        if (Plugin::getInstance()->getBoxes()->contentsAreLocked($subscription, $now)) {
            return [false, Craft::t('subscribr', 'Your next box is already being packed, so it can’t be changed now.')];
        }

        $lockFrom = (clone $subscription->dateNextPayment)->modify('-' . $settings->changeLockHours . ' hours');

        if ($now >= $lockFrom) {
            return [false, Craft::t('subscribr', 'Your next renewal is too close to change. Changes take effect from the one after.')];
        }

        return [true, null];
    }

    private function _query(): Query
    {
        return (new Query())->select('*')->from([Table::SCHEDULES]);
    }

    private function _toModel(array $row): ScheduledAction
    {
        $action = new ScheduledAction();
        $action->id = (int)$row['id'];
        $action->subscriptionId = (int)$row['subscriptionId'];
        $action->action = (string)$row['action'];
        $action->cycle = isset($row['cycle']) ? (int)$row['cycle'] : null;
        $action->applyAt = isset($row['applyAt']) ? DateTimeHelper::toDateTime($row['applyAt']) ?: null : null;
        $action->setPayload($row['payload'] ?? null);
        $action->appliedAt = isset($row['appliedAt']) ? DateTimeHelper::toDateTime($row['appliedAt']) ?: null : null;
        $action->canceledAt = isset($row['canceledAt']) ? DateTimeHelper::toDateTime($row['canceledAt']) ?: null : null;
        $action->createdByUserId = isset($row['createdByUserId']) ? (int)$row['createdByUserId'] : null;
        $action->dateCreated = isset($row['dateCreated']) ? DateTimeHelper::toDateTime($row['dateCreated']) ?: null : null;
        $action->uid = $row['uid'] ?? null;

        return $action;
    }
}
