<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\subscribr\Plugin;

/**
 * Find everything due and push one job per subscription.
 *
 * Deliberately does no billing itself. Its only job is to fan out, so that the expensive,
 * failure-prone part — talking to a gateway — happens in jobs that can fail and be retried
 * individually.
 */
class RunRenewals extends BaseJob
{
    public ?int $limit = null;
    public int $leadHours = 0;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $subscriptions = $plugin->getSubscriptions()->getDue(
            $this->limit ?? $settings->renewalBatchSize,
            $this->leadHours ?: $settings->renewalLeadHours,
        );

        $total = count($subscriptions);

        foreach ($subscriptions as $i => $subscription) {
            $this->setProgress($queue, $total > 0 ? $i / $total : 1);

            Craft::$app->getQueue()->push(new RenewSubscription([
                'subscriptionId' => (int)$subscription->id,
            ]));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('subscribr', 'Queueing subscription renewals');
    }
}
