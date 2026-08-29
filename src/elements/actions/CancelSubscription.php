<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\Plugin;

/**
 * Cancels at the end of the paid period, never immediately.
 *
 * A bulk action is the one place somebody cancels forty subscriptions with one click, and an
 * immediate bulk cancellation would take away service forty customers have already paid for. The
 * immediate form exists on the individual screen, where somebody has looked at what they are doing.
 */
class CancelSubscription extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('subscribr', 'Cancel at period end');
    }

    public function getConfirmationMessage(): ?string
    {
        return Craft::t('subscribr', 'Are you sure you want to cancel these subscriptions? They’ll keep running until the end of the period each subscriber has paid for.');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $service = Plugin::getInstance()->getSubscriptions();
        $n = 0;

        /** @var Subscription $subscription */
        foreach ($query->status(null)->all() as $subscription) {
            if (!$subscription->getIsCanceled() && $service->cancel($subscription, Craft::t('subscribr', 'Cancelled from the control panel.'))) {
                $n++;
            }
        }

        $this->setMessage(Craft::t('subscribr', '{n, plural, =0{Nothing to cancel} =1{One subscription cancelled} other{# subscriptions cancelled}}.', ['n' => $n]));

        return true;
    }
}
