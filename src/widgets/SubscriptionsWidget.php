<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\widgets;

use Craft;
use craft\base\Widget;
use DateTime;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\helpers\Money;
use justinholtweb\subscribr\Plugin;

/**
 * The dashboard widget: recurring revenue, what is renewing this week, and what is at risk.
 *
 * MRR is normalised to a month across every cadence the store runs, because a store with weekly
 * boxes and annual memberships cannot compare them any other way — and a "recurring revenue"
 * figure that quietly counted a yearly plan as a monthly one would be twelve times wrong.
 */
class SubscriptionsWidget extends Widget
{
    public int $days = 7;

    public static function displayName(): string
    {
        return Craft::t('subscribr', 'Subscriptions');
    }

    public static function icon(): ?string
    {
        return 'arrows-rotate';
    }

    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('subscribr-viewSubscriptions');
    }

    public function getTitle(): ?string
    {
        return Craft::t('subscribr', 'Subscriptions');
    }

    public function getBodyHtml(): ?string
    {
        $plugin = Plugin::getInstance();

        if (!Plugin::commerceIsReady()) {
            return Craft::t('subscribr', 'Commerce isn’t installed.');
        }

        $live = Subscription::find()
            ->status(null)
            ->subscriptionStatus([Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
            ->all();

        $mrr = 0.0;

        foreach ($live as $subscription) {
            $mrr += $this->_monthlyValue($subscription);
        }

        $summary = $plugin->getDunning()->getSummary();

        return Craft::$app->getView()->renderTemplate('subscribr/_widgets/subscriptions', [
            'activeCount' => count($live),
            'mrr' => Money::format(Money::round($mrr)),
            'renewing' => Subscription::find()->status(null)->renewingWithin($this->days)->count(),
            'days' => $this->days,
            'summary' => $summary,
            'atRisk' => Money::format($summary['atRisk']),
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('subscribr/_widgets/settings', ['widget' => $this]);
    }

    /**
     * One subscription's contribution to monthly recurring revenue.
     *
     * Days-based rather than a table of magic numbers, so a "every 10 days" cadence is handled
     * correctly rather than rounded to whichever of weekly and monthly is nearer.
     */
    private function _monthlyValue(Subscription $subscription): float
    {
        $plan = $subscription->getPlan();

        if ($plan === null || $subscription->renewalPrice <= 0) {
            return 0.0;
        }

        $days = $plan->getCadence()->daysInCycle($subscription->dateCurrentPeriodStart ?? new DateTime());

        return $days > 0 ? $subscription->renewalPrice * (365.25 / 12) / $days : 0.0;
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['days'], 'integer', 'min' => 1, 'max' => 90],
        ]);
    }
}
