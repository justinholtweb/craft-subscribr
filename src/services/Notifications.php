<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\helpers\UrlHelper;
use craft\web\View;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\models\DunningStage;
use justinholtweb\subscribr\models\Gift;
use justinholtweb\subscribr\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Subscriber email.
 *
 * Deliberately Craft's mailer and Craft's templates rather than Commerce's email system. Commerce
 * emails are attached to *order statuses*, and the messages that matter here — "your card was
 * declined, here is a link to fix it", "your box ships on Tuesday, here is what is in it", "skip
 * used" — have no order status to hang off, and half of them have no order at all.
 *
 * Every message can be overridden per-site by pointing `emailTemplates` at a template of the
 * store's own. The plugin's own templates are plain and short on purpose: they are meant to be
 * replaced, and a store that has not replaced them yet should still be sending something useful.
 *
 * **Sending never throws.** A mail server that is down must not fail a renewal that has already
 * taken the money.
 */
class Notifications extends Component
{
    public const WELCOME = 'welcome';
    public const RENEWED = 'renewed';
    public const UPCOMING = 'upcoming';
    public const PAYMENT_FAILED = 'paymentFailed';
    public const PAYMENT_RECOVERED = 'paymentRecovered';
    public const MANUAL_RENEWAL = 'manualRenewal';
    public const DUNNING_ENDED = 'dunningEnded';
    public const GIFT = 'gift';
    public const STAFF_ALERT = 'staffAlert';

    public function sendWelcome(Subscription $subscription): bool
    {
        return $this->send($subscription, self::WELCOME, Craft::t('subscribr', 'Your subscription is set up'));
    }

    public function sendRenewed(Subscription $subscription, ?Order $order = null): bool
    {
        return $this->send($subscription, self::RENEWED, Craft::t('subscribr', 'Your subscription has renewed'), ['order' => $order]);
    }

    /**
     * The one email that stops chargebacks: "you will be charged on Thursday".
     */
    public function sendUpcoming(Subscription $subscription): bool
    {
        return $this->send($subscription, self::UPCOMING, Craft::t('subscribr', 'Your next order is coming up'));
    }

    public function sendPaymentFailed(Subscription $subscription, ?Order $order = null, ?DunningStage $stage = null): bool
    {
        return $this->send(
            $subscription,
            $stage?->emailKey ?: self::PAYMENT_FAILED,
            Craft::t('subscribr', 'We couldn’t take your payment'),
            ['order' => $order, 'stage' => $stage],
        );
    }

    public function sendPaymentRecovered(Subscription $subscription, ?Order $order = null): bool
    {
        return $this->send($subscription, self::PAYMENT_RECOVERED, Craft::t('subscribr', 'Thanks — your payment went through'), ['order' => $order]);
    }

    /**
     * A renewal the customer has to pay themselves.
     *
     * Carries Commerce's own load-cart URL, which drops them into the store's real checkout with
     * the renewal already in it — no second payment page to build, and no second one to keep
     * working when the store changes gateway.
     */
    public function sendManualRenewal(Subscription $subscription, Order $order): bool
    {
        $payUrl = null;

        try {
            $payUrl = Commerce::getInstance()->getCarts()->getLoadCartUrl($order);
        } catch (Throwable $e) {
            Craft::warning('Could not build a payment link: ' . $e->getMessage(), __METHOD__);
        }

        return $this->send(
            $subscription,
            self::MANUAL_RENEWAL,
            Craft::t('subscribr', 'Your renewal is ready to pay'),
            ['order' => $order, 'payUrl' => $payUrl],
        );
    }

    public function sendDunningEnded(Subscription $subscription): bool
    {
        return $this->send($subscription, self::DUNNING_ENDED, Craft::t('subscribr', 'Your subscription has ended'));
    }

    public function sendGift(Gift $gift): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->sendEmails) {
            return false;
        }

        $subscription = $gift->subscriptionId
            ? Plugin::getInstance()->getSubscriptions()->getSubscriptionById((int)$gift->subscriptionId)
            : null;

        $url = UrlHelper::siteUrl(str_replace('{token}', $gift->token, $settings->giftClaimPath));

        return $this->_dispatch(
            $gift->recipientEmail,
            Craft::t('subscribr', '{name} has sent you a subscription', ['name' => $gift->senderName ?: Craft::$app->getSystemName()]),
            self::GIFT,
            [
                'gift' => $gift,
                'subscription' => $subscription,
                'claimUrl' => $url,
            ],
        );
    }

    /**
     * Tell somebody in the building.
     */
    public function sendStaffAlert(Subscription $subscription, ?DunningStage $stage = null): bool
    {
        $to = Plugin::getInstance()->getSettings()->emailFromAddress
            ?: Craft::$app->getProjectConfig()->get('email.fromEmail');

        if (!$to) {
            return false;
        }

        return $this->_dispatch(
            (string)Craft::parseEnv($to),
            Craft::t('subscribr', 'Subscription {ref} needs attention', ['ref' => $subscription->reference]),
            self::STAFF_ALERT,
            ['subscription' => $subscription, 'stage' => $stage],
        );
    }

    /**
     * Send one of the subscriber-facing messages.
     */
    public function send(Subscription $subscription, string $key, string $subject, array $variables = []): bool
    {
        if (!Plugin::getInstance()->getSettings()->sendEmails) {
            return false;
        }

        $subscriber = $subscription->getSubscriber();
        $email = $subscriber?->email ?? $subscription->getOrder()?->email;

        if (!$email) {
            return false;
        }

        return $this->_dispatch($email, $subject, $key, array_merge([
            'subscription' => $subscription,
            'subscriber' => $subscriber,
            'portalUrl' => $this->portalUrl($subscription),
        ], $variables));
    }

    public function portalUrl(Subscription $subscription): string
    {
        $path = trim(Plugin::getInstance()->getSettings()->portalPath, '/');

        return UrlHelper::siteUrl($path . '/' . $subscription->reference);
    }

    // Internals
    // -------------------------------------------------------------------------

    private function _dispatch(string $to, string $subject, string $key, array $variables): bool
    {
        try {
            $settings = Plugin::getInstance()->getSettings();
            $template = $settings->emailTemplates[$key] ?? null;
            $view = Craft::$app->getView();

            if ($template) {
                // A site template, rendered in the site's own mode so it can extend the store's
                // email layout and use the store's own variables.
                $body = $view->renderTemplate($template, $variables, View::TEMPLATE_MODE_SITE);
            } else {
                $body = $view->renderTemplate('subscribr/_emails/' . $key, $variables, View::TEMPLATE_MODE_CP);
            }

            $message = Craft::$app->getMailer()->compose()
                ->setTo($to)
                ->setSubject($subject)
                ->setHtmlBody($body);

            if ($settings->emailFromAddress) {
                $message->setFrom([
                    (string)Craft::parseEnv($settings->emailFromAddress) => $settings->emailFromName ?: Craft::$app->getSystemName(),
                ]);
            }

            return $message->send();
        } catch (Throwable $e) {
            // Never fatal. A mail server that is down must not undo a payment that has been taken.
            Craft::error(sprintf('Subscribr could not send the “%s” email: %s', $key, $e->getMessage()), __METHOD__);

            return false;
        }
    }
}
