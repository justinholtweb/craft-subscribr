<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use justinholtweb\subscribr\db\Table;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\models\Attempt;
use justinholtweb\subscribr\models\DunningProfile;
use justinholtweb\subscribr\models\DunningStage;
use justinholtweb\subscribr\models\LogEntry;
use justinholtweb\subscribr\models\RenewalResult;
use justinholtweb\subscribr\Plugin;
use justinholtweb\subscribr\records\DunningRecord;
use yii\base\Component;

/**
 * Dunning: what happens after a payment fails.
 *
 * A failed renewal is not a cancelled subscription — most of them are an expired card, and a store
 * that cancels on the first decline is throwing away customers who would happily have paid. But it
 * is not an indefinite free service either. Dunning is the schedule between those two, and the
 * point of surfacing it in the control panel is that **the merchant can see the money that is
 * currently at risk and how much of it is coming back**, which is a number no Stripe dashboard
 * will show them in the shape of their own store.
 *
 * ## The sequence
 *
 * Stages are offsets from the **first** failure, not from each other, so the whole timeline is
 * legible at a glance and reordering the stages cannot silently change the total. A stage either
 * retries, emails, or ends it. The default, when no profile is set, comes from the plugin
 * settings — a store should not have to build a profile before its first card declines.
 *
 * ## What is not retried
 *
 * A card reported stolen will not start working on Thursday. `Attempt::getIsRetryable()` reads the
 * gateway's own reason and skips straight to the terminal stage for the ones that are hopeless,
 * because retrying those is how a store's merchant account gets reviewed.
 */
class Dunning extends Component
{
    /** @var DunningProfile[]|null */
    private ?array $_profiles = null;

    // Profiles
    // -------------------------------------------------------------------------

    /** @return DunningProfile[] */
    public function getAllProfiles(): array
    {
        if ($this->_profiles === null) {
            $rows = (new Query())->select('*')->from([Table::DUNNING])->orderBy(['name' => SORT_ASC])->all();
            $this->_profiles = array_map(fn(array $row): DunningProfile => $this->_toProfile($row), $rows);
        }

        return $this->_profiles;
    }

    public function getProfileById(int $id): ?DunningProfile
    {
        foreach ($this->getAllProfiles() as $profile) {
            if ($profile->id === $id) {
                return $profile;
            }
        }

        return null;
    }

    public function getProfileByHandle(string $handle): ?DunningProfile
    {
        foreach ($this->getAllProfiles() as $profile) {
            if ($profile->handle === $handle) {
                return $profile;
            }
        }

        return null;
    }

    public function getDefaultProfile(): ?DunningProfile
    {
        foreach ($this->getAllProfiles() as $profile) {
            if ($profile->isDefault) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * The sequence that governs this subscription.
     *
     * A plan's profile, then the store default, then one synthesised from the plugin settings. The
     * fallback is not a nicety: without it, a store that never opened the dunning screen would
     * have declined subscriptions sitting past-due for ever.
     */
    public function getProfileFor(Subscription $subscription): DunningProfile
    {
        $profile = $subscription->getPlan()?->getDunningProfile() ?? $this->getDefaultProfile();

        if ($profile !== null && $profile->getStageCount() > 0) {
            return $profile;
        }

        return $this->getFallbackProfile();
    }

    public function getFallbackProfile(): DunningProfile
    {
        $settings = Plugin::getInstance()->getSettings();
        $stages = [];

        foreach ($settings->getDefaultRetryHours() as $hours) {
            $stages[] = ['offsetHours' => $hours, 'action' => DunningStage::ACTION_RETRY];
        }

        $last = $stages === [] ? 0 : end($stages)['offsetHours'];

        $stages[] = [
            'offsetHours' => $last + 24,
            'action' => match ($settings->dunningFinalAction) {
                'pause' => DunningStage::ACTION_PAUSE,
                'expire' => DunningStage::ACTION_EXPIRE,
                default => DunningStage::ACTION_CANCEL,
            },
        ];

        $profile = new DunningProfile([
            'name' => Craft::t('subscribr', 'Store default'),
            'handle' => 'default',
        ]);
        $profile->setStages($stages);

        return $profile;
    }

    public function saveProfile(DunningProfile $profile, bool $runValidation = true): bool
    {
        if ($runValidation && !$profile->validate()) {
            return false;
        }

        $record = $profile->id ? DunningRecord::findOne($profile->id) : new DunningRecord();

        if ($record === null) {
            $record = new DunningRecord();
        }

        $record->name = $profile->name;
        $record->handle = $profile->handle;
        $record->description = $profile->description;
        $record->stages = $profile->stagesToJson();
        $record->isDefault = $profile->isDefault;

        if (!$record->save()) {
            $profile->addErrors($record->getErrors());

            return false;
        }

        $profile->id = (int)$record->id;

        // Exactly one default. Enforced on write rather than read, so that "which profile applies"
        // has a single answer even if two rows were written by two people at once.
        if ($profile->isDefault) {
            Craft::$app->getDb()->createCommand()
                ->update(Table::DUNNING, ['isDefault' => false], ['not', ['id' => $profile->id]])
                ->execute();
        }

        $this->_profiles = null;

        return true;
    }

    public function deleteProfileById(int $id): bool
    {
        $record = DunningRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        $record->delete();
        $this->_profiles = null;

        return true;
    }

    // The sequence
    // -------------------------------------------------------------------------

    /**
     * Record a failed renewal and set the next retry.
     *
     * `$countAsFailure` is false for a manual hand-off: an invoice that has not been paid yet is
     * on the same timetable as a declined card, but it has not failed and should not be reported
     * as a decline in the recovery figures.
     */
    public function recordFailure(Subscription $subscription, Attempt $attempt, ?Order $order = null, bool $countAsFailure = true): void
    {
        $plugin = Plugin::getInstance();
        $profile = $this->getProfileFor($subscription);

        if ($countAsFailure) {
            $subscription->failureCount++;
            $subscription->subscriptionStatus = Subscription::STATUS_PAST_DUE;
        }

        $subscription->lastFailureMessage = $attempt->message;

        $firstFailure = $subscription->dateNextRetry !== null
            ? $this->_firstFailureDate($subscription)
            : new DateTime();

        $stageIndex = $subscription->dunningStage;

        // A hopeless decline does not deserve three more attempts. Straight to the end of the
        // sequence, where whatever the store decided happens — usually a cancellation with the
        // reason on it, which is at least an honest thing for the customer to receive.
        if ($countAsFailure && !$attempt->getIsRetryable()) {
            $stageIndex = max(0, $profile->getStageCount() - 1);

            $plugin->getLedger()->log(
                (int)$subscription->id,
                LogEntry::TYPE_DUNNING_STAGE,
                Craft::t('subscribr', 'The gateway reported a permanent failure, so the retries were skipped: {message}', ['message' => $attempt->message ?? '']),
            );
        }

        $stage = $profile->getStage($stageIndex);

        if ($stage === null) {
            $this->_finish($subscription, $profile);

            return;
        }

        $subscription->dunningStage = $stageIndex;
        $subscription->dateNextRetry = (clone $firstFailure)->modify('+' . $stage->offsetHours . ' hours');

        $plugin->getSubscriptions()->save($subscription);

        $plugin->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_PAYMENT_FAILED,
            Craft::t('subscribr', 'Payment failed: {message} Next attempt {date}.', [
                'message' => $attempt->message ?? '',
                'date' => $subscription->dateNextRetry->format('j M Y, H:i'),
            ]),
            ['orderId' => $order?->id, 'attemptId' => $attempt->id, 'stage' => $stageIndex],
        );

        if ($countAsFailure) {
            $plugin->getNotifications()->sendPaymentFailed($subscription, $order, $stage);
        }
    }

    /**
     * Work the dunning queue: everything whose retry is due.
     *
     * @return RenewalResult[]
     */
    public function run(?int $limit = null, ?DateTime $now = null): array
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->enableDunning) {
            return [];
        }

        $now ??= new DateTime();
        $limit ??= $plugin->getSettings()->renewalBatchSize;
        $results = [];

        $subscriptions = Subscription::find()
            ->status(null)
            ->subscriptionStatus(Subscription::STATUS_PAST_DUE)
            ->dateNextRetry(['and', '<= ' . $now->format('Y-m-d H:i:s')])
            ->limit($limit)
            ->all();

        foreach ($subscriptions as $subscription) {
            $results[] = $this->advance($subscription, $now);
        }

        return array_values(array_filter($results));
    }

    /**
     * Take the next step in a subscription's dunning sequence.
     */
    public function advance(Subscription $subscription, ?DateTime $now = null): ?RenewalResult
    {
        $plugin = Plugin::getInstance();
        $profile = $this->getProfileFor($subscription);
        $stage = $profile->getStage($subscription->dunningStage);

        if ($stage === null) {
            $this->_finish($subscription, $profile);

            return null;
        }

        switch ($stage->action) {
            case DunningStage::ACTION_RETRY:
                return $this->retry($subscription, $now);

            case DunningStage::ACTION_EMAIL:
                $plugin->getNotifications()->sendPaymentFailed($subscription, null, $stage);
                $this->_step($subscription, $profile);

                return null;

            case DunningStage::ACTION_NOTIFY_STAFF:
                $plugin->getNotifications()->sendStaffAlert($subscription, $stage);
                $this->_step($subscription, $profile);

                return null;

            case DunningStage::ACTION_PAUSE:
                $plugin->getSubscriptions()->pause($subscription, null, Craft::t('subscribr', 'Payment could not be taken.'));
                $this->_close($subscription);

                return RenewalResult::make(RenewalResult::PAUSED, (int)$subscription->id, 'Paused by dunning.');

            case DunningStage::ACTION_CANCEL:
                $plugin->getSubscriptions()->cancel($subscription, Craft::t('subscribr', 'Payment could not be taken.'), true);
                $this->_close($subscription);
                $plugin->getNotifications()->sendDunningEnded($subscription);

                return RenewalResult::make(RenewalResult::ENDED, (int)$subscription->id, 'Cancelled by dunning.');

            case DunningStage::ACTION_EXPIRE:
                $plugin->getSubscriptions()->expire($subscription, Craft::t('subscribr', 'Ended after the payment could not be recovered.'));
                $this->_close($subscription);
                $plugin->getNotifications()->sendDunningEnded($subscription);

                return RenewalResult::make(RenewalResult::ENDED, (int)$subscription->id, 'Ended by dunning.');
        }

        return null;
    }

    /**
     * Try the failed cycle again.
     *
     * Reuses the order that failed rather than raising a new one. A subscriber who gets four
     * invoices for one month's coffee has been billed once and told four times, and the store's
     * order index says otherwise.
     */
    public function retry(Subscription $subscription, ?DateTime $now = null): RenewalResult
    {
        $plugin = Plugin::getInstance();
        $order = $this->getOutstandingOrder($subscription);

        if ($order === null) {
            // Nothing outstanding: the cycle was never raised, or somebody deleted it. Fall back
            // to a fresh renewal, forced, so the subscriber is not left in past-due for ever.
            $subscription->dateNextPayment ??= $now ?? new DateTime();
            $result = $plugin->getRenewals()->renew($subscription, $now, true);

            // The renewal clears the dunning columns on its own, but it does not know it was a
            // recovery — and a recovery that is not recorded is one the recovery rate cannot count.
            if ($result->getIsSuccess()) {
                $plugin->getLedger()->log(
                    (int)$subscription->id,
                    LogEntry::TYPE_PAYMENT_RECOVERED,
                    Craft::t('subscribr', 'Payment recovered on a fresh renewal; next payment {date}.', [
                        'date' => $subscription->dateNextPayment?->format('j M Y'),
                    ]),
                    ['orderId' => $result->order?->id],
                );

                $plugin->getNotifications()->sendPaymentRecovered($subscription, $result->order);
            }

            return $result;
        }

        $attempt = $plugin->getBilling()->charge($subscription, $order, $subscription->dunningStage);

        if ($attempt->outcome === Attempt::SUCCESS) {
            return $this->recover($subscription, $order, $attempt);
        }

        $profile = $this->getProfileFor($subscription);
        $subscription->failureCount++;
        $subscription->lastFailureMessage = $attempt->message;
        $this->_step($subscription, $profile, $attempt);

        $plugin->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_PAYMENT_FAILED,
            Craft::t('subscribr', 'Retry {n} failed: {message}', ['n' => $subscription->failureCount, 'message' => $attempt->message ?? '']),
            ['attemptId' => $attempt->id],
        );

        return new RenewalResult([
            'outcome' => RenewalResult::FAILED,
            'subscriptionId' => (int)$subscription->id,
            'order' => $order,
            'message' => $attempt->message,
            'attempt' => $attempt,
        ]);
    }

    /**
     * A retry worked. Advance the cycle that was owed and clear the dunning state.
     */
    public function recover(Subscription $subscription, Order $order, Attempt $attempt): RenewalResult
    {
        $plugin = Plugin::getInstance();
        $plan = $subscription->getPlan();
        $due = $subscription->dateNextPayment ?? new DateTime();
        $cycle = $subscription->cycleCount + 1;
        // Read before it is cleared: the message says which attempt worked, and after the reset
        // every recovery would claim to have worked first time.
        $attemptNumber = $subscription->dunningStage + 1;

        $subscription->failureCount = 0;
        $subscription->dunningStage = 0;
        $subscription->dateNextRetry = null;
        $subscription->lastFailureMessage = null;
        $subscription->cycleCount = $cycle;
        $subscription->dateLastPayment = new DateTime();
        $subscription->subscriptionStatus = Subscription::STATUS_ACTIVE;
        $subscription->dateCurrentPeriodStart = $due;
        $subscription->dateNextPayment = $plan?->getCadence()->next($due);

        $plugin->getSubscriptions()->save($subscription);

        $plugin->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_PAYMENT_RECOVERED,
            Craft::t('subscribr', 'Payment recovered on attempt {n}; next payment {date}.', [
                'n' => $attemptNumber,
                'date' => $subscription->dateNextPayment?->format('j M Y'),
            ]),
            ['orderId' => $order->id, 'attemptId' => $attempt->id],
        );

        $plugin->getNotifications()->sendPaymentRecovered($subscription, $order);

        return new RenewalResult([
            'outcome' => RenewalResult::RENEWED,
            'subscriptionId' => (int)$subscription->id,
            'order' => $order,
            'cycle' => $cycle,
            'amount' => (float)$order->getTotalPrice(),
            'currency' => $order->currency,
            'nextPaymentDate' => $subscription->dateNextPayment,
            'attempt' => $attempt,
        ]);
    }

    /**
     * The unpaid order this subscription is in dunning over.
     */
    public function getOutstandingOrder(Subscription $subscription): ?Order
    {
        foreach ($subscription->getOrders() as $order) {
            if (!$order->getIsPaid()) {
                return $order;
            }
        }

        return null;
    }

    // Reporting
    // -------------------------------------------------------------------------

    /**
     * The numbers the dunning dashboard exists for.
     *
     * `atRisk` is money, not a count: "eleven subscriptions" tells a merchant nothing about
     * whether to care, and £4,200 tells them immediately.
     */
    public function getSummary(?DateTime $since = null): array
    {
        $since ??= (new DateTime())->modify('-30 days');

        $pastDue = Subscription::find()
            ->status(null)
            ->subscriptionStatus(Subscription::STATUS_PAST_DUE)
            ->all();

        $atRisk = 0.0;

        foreach ($pastDue as $subscription) {
            $atRisk += $subscription->renewalPrice;
        }

        $attempts = (new Query())
            ->select(['outcome', 'COUNT(*) as n', 'SUM([[amount]]) as total'])
            ->from([Table::ATTEMPTS])
            ->where(['>=', 'dateCreated', Db::prepareDateForDb($since)])
            ->groupBy(['outcome'])
            ->all();

        $byOutcome = [];

        foreach ($attempts as $row) {
            $byOutcome[$row['outcome']] = ['count' => (int)$row['n'], 'total' => (float)$row['total']];
        }

        $failed = $byOutcome[Attempt::FAILED]['count'] ?? 0;
        $recovered = (int)(new Query())
            ->from([Table::EVENTS])
            ->where(['type' => LogEntry::TYPE_PAYMENT_RECOVERED])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($since)])
            ->count('[[id]]');

        return [
            'pastDueCount' => count($pastDue),
            'atRisk' => round($atRisk, 2),
            'failedAttempts' => $failed,
            'recovered' => $recovered,
            // The only honest recovery rate is recoveries over *subscriptions that failed*, not
            // over attempts — three retries on one card is one customer, not three.
            'recoveryRate' => $failed > 0 ? round(($recovered / max(1, $failed)) * 100, 1) : null,
            'byOutcome' => $byOutcome,
            'since' => $since,
        ];
    }

    /** @return Subscription[] */
    public function getAtRisk(int $limit = 50): array
    {
        return Subscription::find()
            ->status(null)
            ->subscriptionStatus(Subscription::STATUS_PAST_DUE)
            ->orderBy(['subscribr_subscriptions.dateNextRetry' => SORT_ASC])
            ->limit($limit)
            ->all();
    }

    // Internals
    // -------------------------------------------------------------------------

    private function _step(Subscription $subscription, DunningProfile $profile, ?Attempt $attempt = null): void
    {
        $next = $subscription->dunningStage + 1;
        $stage = $profile->getStage($next);

        if ($stage === null) {
            $this->_finish($subscription, $profile);

            return;
        }

        $firstFailure = $this->_firstFailureDate($subscription);
        $subscription->dunningStage = $next;
        $subscription->dateNextRetry = (clone $firstFailure)->modify('+' . $stage->offsetHours . ' hours');

        Plugin::getInstance()->getSubscriptions()->save($subscription);
    }

    /**
     * The sequence ran out without anything terminal in it — a profile that is all retries. Whatever
     * the store's final action is applies, so a subscription can never sit past-due for ever
     * because somebody forgot to add the last stage.
     */
    private function _finish(Subscription $subscription, DunningProfile $profile): void
    {
        $plugin = Plugin::getInstance();

        match ($plugin->getSettings()->dunningFinalAction) {
            'pause' => $plugin->getSubscriptions()->pause($subscription, null, Craft::t('subscribr', 'Payment could not be taken.')),
            'expire' => $plugin->getSubscriptions()->expire($subscription, Craft::t('subscribr', 'Ended after the payment could not be recovered.')),
            default => $plugin->getSubscriptions()->cancel($subscription, Craft::t('subscribr', 'Payment could not be taken.'), true),
        };

        $this->_close($subscription);
        $plugin->getNotifications()->sendDunningEnded($subscription);
    }

    private function _close(Subscription $subscription): void
    {
        $subscription->dateNextRetry = null;
        $subscription->dunningStage = 0;
        Plugin::getInstance()->getSubscriptions()->save($subscription);
    }

    /**
     * When this run of failures started.
     *
     * Read from the ledger rather than kept in a column, because the column would have to be
     * cleared by something and the one place it must not be forgotten is a recovery — where the
     * code is already busy doing five other things.
     */
    private function _firstFailureDate(Subscription $subscription): DateTime
    {
        $recovered = (new Query())
            ->select(['dateCreated'])
            ->from([Table::EVENTS])
            ->where(['subscriptionId' => $subscription->id, 'type' => LogEntry::TYPE_PAYMENT_RECOVERED])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->scalar();

        $query = (new Query())
            ->select(['dateCreated'])
            ->from([Table::EVENTS])
            ->where(['subscriptionId' => $subscription->id, 'type' => LogEntry::TYPE_PAYMENT_FAILED])
            ->orderBy(['dateCreated' => SORT_ASC]);

        if ($recovered) {
            $query->andWhere(['>', 'dateCreated', $recovered]);
        }

        $first = $query->scalar();

        return $first ? (DateTimeHelper::toDateTime($first) ?: new DateTime()) : new DateTime();
    }

    private function _toProfile(array $row): DunningProfile
    {
        $profile = new DunningProfile();
        $profile->id = (int)$row['id'];
        $profile->name = (string)$row['name'];
        $profile->handle = (string)$row['handle'];
        $profile->description = $row['description'] ?? null;
        $profile->setStages($row['stages'] ?? null);
        $profile->isDefault = (bool)$row['isDefault'];
        $profile->dateCreated = isset($row['dateCreated']) ? DateTimeHelper::toDateTime($row['dateCreated']) ?: null : null;
        $profile->uid = $row['uid'] ?? null;

        return $profile;
    }
}
