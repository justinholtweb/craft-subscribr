<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\Plugin;

class PauseSubscription extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('subscribr', 'Pause');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $service = Plugin::getInstance()->getSubscriptions();
        $n = 0;

        /** @var Subscription $subscription */
        foreach ($query->status(null)->all() as $subscription) {
            if ($subscription->getIsLive() && $service->pause($subscription)) {
                $n++;
            }
        }

        $this->setMessage(Craft::t('subscribr', '{n, plural, =0{Nothing to pause} =1{One subscription paused} other{# subscriptions paused}}.', ['n' => $n]));

        return true;
    }
}
