<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\Plugin;

class ResumeSubscription extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('subscribr', 'Resume');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $service = Plugin::getInstance()->getSubscriptions();
        $n = 0;

        /** @var Subscription $subscription */
        foreach ($query->status(null)->all() as $subscription) {
            if ($subscription->getIsPaused() && $service->resume($subscription)) {
                $n++;
            }
        }

        $this->setMessage(Craft::t('subscribr', '{n, plural, =0{Nothing to resume} =1{One subscription resumed} other{# subscriptions resumed}}.', ['n' => $n]));

        return true;
    }
}
