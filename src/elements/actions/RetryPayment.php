<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\models\RenewalResult;
use justinholtweb\subscribr\Plugin;

class RetryPayment extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('subscribr', 'Retry payment now');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $dunning = Plugin::getInstance()->getDunning();
        $recovered = 0;
        $tried = 0;

        /** @var Subscription $subscription */
        foreach ($query->status(null)->all() as $subscription) {
            if ($subscription->getStatus() !== Subscription::STATUS_PAST_DUE) {
                continue;
            }

            $tried++;
            $result = $dunning->retry($subscription);

            if ($result->outcome === RenewalResult::RENEWED) {
                $recovered++;
            }
        }

        $this->setMessage(Craft::t('subscribr', '{recovered} of {tried} recovered.', ['recovered' => $recovered, 'tried' => $tried]));

        return true;
    }
}
