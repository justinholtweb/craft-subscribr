<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\subscribr\Plugin;

/**
 * Renew one subscription.
 *
 * One job per subscription rather than one job for the whole sweep, because a queue job that dies
 * halfway through five hundred renewals is a job that gets retried, and a retried sweep would
 * re-charge everyone it had already billed. One subscription per job means a retry re-attempts
 * exactly one cycle, and `renew()` refuses it anyway if the schedule has already moved on.
 */
class RenewSubscription extends BaseJob
{
    public int $subscriptionId;
    public bool $force = false;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $subscription = $plugin->getSubscriptions()->getSubscriptionById($this->subscriptionId);

        if ($subscription === null) {
            return;
        }

        $result = $plugin->getRenewals()->renew($subscription, null, $this->force);

        if ($result->message) {
            Craft::info(sprintf('Subscription %d: %s — %s', $this->subscriptionId, $result->outcome, $result->message), __METHOD__);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('subscribr', 'Renewing a subscription');
    }
}
