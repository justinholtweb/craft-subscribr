<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use DateTime;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\models\LogEntry;
use justinholtweb\subscribr\models\ScheduledAction;
use justinholtweb\subscribr\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Self-service, on the front end.
 *
 * The whole feature list Craft's core subscriptions do not have — skip, pause, swap a shipment,
 * change the plan and see the proration first, update the card, claim a gift — from the customer's
 * side. Nobody has to email support, and support does not have to be in the control panel.
 *
 * ## Two rules that everything here obeys
 *
 * 1. **Every action re-resolves the subscription from the signed-in user.** A reference in a form
 *    is not proof of anything; `_subscription()` looks the record up scoped to the current user, so
 *    a posted reference belonging to somebody else does not resolve at all.
 * 2. **Every action re-checks the permission the button was rendered from.** The Twig `can()` map
 *    decides what is *shown*; these methods decide what is *allowed*, independently, because a form
 *    can be cached, replayed, or made up.
 */
class PortalController extends Controller
{
    protected array|bool|int $allowAnonymous = ['claim'];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::getInstance()->getSettings()->enablePortal) {
            throw new ForbiddenHttpException('The subscriber portal is switched off.');
        }

        return true;
    }

    /**
     * Skip the next cycle.
     */
    public function actionSkip(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $subscription = $this->_subscription();
        $plan = $subscription->getPlan();

        if (!$plugin->getEffectiveSkipEnabled() || !$plan?->allowSkip) {
            return $this->_fail($subscription, Craft::t('subscribr', 'This subscription can’t be skipped.'));
        }

        if ($error = $this->_windowError($subscription)) {
            return $this->_fail($subscription, $error);
        }

        if ($plan->maxSkipsPerYear > 0) {
            // Counted from the ledger rather than a column, because a per-year allowance needs
            // something to reset it and nothing is reliably running on 1 January.
            $used = $plugin->getLedger()->countSince(
                (int)$subscription->id,
                LogEntry::TYPE_SKIPPED,
                (new DateTime())->modify('-1 year'),
            );

            if ($used >= $plan->maxSkipsPerYear) {
                return $this->_fail($subscription, Craft::t('subscribr', 'You’ve used all {n} of your skips for this year.', ['n' => $plan->maxSkipsPerYear]));
            }
        }

        $cycle = $subscription->cycleCount + 1;
        $plugin->getSchedules()->book($subscription, ScheduledAction::SKIP, $cycle);

        return $this->_ok($subscription, Craft::t('subscribr', 'Your next order is skipped. The one after is still on {date}.', [
            'date' => $subscription->getPlan()?->getCadence()->next($subscription->dateNextPayment ?? new DateTime())->format('j M Y'),
        ]));
    }

    public function actionUnskip(): ?Response
    {
        $this->requirePostRequest();

        $subscription = $this->_subscription();
        Plugin::getInstance()->getSchedules()->cancelPending($subscription, ScheduledAction::SKIP);

        return $this->_ok($subscription, Craft::t('subscribr', 'Your next order is back on.'));
    }

    /**
     * Pause.
     *
     * A pause offered instead of a cancellation is the single highest-value retention tool a
     * subscription store has, which is why `offerPauseOnCancel` exists and why pausing is in Lite.
     */
    public function actionPause(): ?Response
    {
        $this->requirePostRequest();

        $subscription = $this->_subscription();
        $plan = $subscription->getPlan();

        if (!$plan?->allowPause) {
            return $this->_fail($subscription, Craft::t('subscribr', 'This subscription can’t be paused.'));
        }

        $subscriptions = Plugin::getInstance()->getSubscriptions();
        [$until, $error] = $subscriptions->resolvePauseUntil($subscription, $this->request->getBodyParam('until'));

        if ($error !== null) {
            return $this->_fail($subscription, $error);
        }

        $reason = $this->request->getBodyParam('reason');
        $subscriptions->pause($subscription, $until, is_string($reason) && $reason !== '' ? $reason : null);

        return $this->_ok($subscription, $until
            ? Craft::t('subscribr', 'Paused. We’ll start again on {date}.', ['date' => $until->format('j M Y')])
            : Craft::t('subscribr', 'Paused. Restart whenever you like.'));
    }

    public function actionResume(): ?Response
    {
        $this->requirePostRequest();

        $subscription = $this->_subscription();
        Plugin::getInstance()->getSubscriptions()->resume($subscription);

        return $this->_ok($subscription, Craft::t('subscribr', 'Welcome back. Your next order is {date}.', [
            'date' => $subscription->dateNextPayment?->format('j M Y'),
        ]));
    }

    public function actionCancel(): ?Response
    {
        $this->requirePostRequest();

        $subscription = $this->_subscription();
        $plan = $subscription->getPlan();

        if (!$plan?->allowCancel) {
            return $this->_fail($subscription, Craft::t('subscribr', 'This subscription can’t be cancelled here — please get in touch.'));
        }

        Plugin::getInstance()->getSubscriptions()->cancel($subscription, $this->request->getBodyParam('reason'));

        return $this->_ok($subscription, $subscription->dateEnds
            ? Craft::t('subscribr', 'Cancelled. You’ll keep getting your orders until {date}.', ['date' => $subscription->dateEnds->format('j M Y')])
            : Craft::t('subscribr', 'Cancelled.'));
    }

    public function actionUncancel(): ?Response
    {
        $this->requirePostRequest();

        $subscription = $this->_subscription();

        if (!Plugin::getInstance()->getSubscriptions()->uncancel($subscription)) {
            return $this->_fail($subscription, Craft::t('subscribr', 'This subscription has already ended. You can start a new one any time.'));
        }

        return $this->_ok($subscription, Craft::t('subscribr', 'Good news — your subscription is back on.'));
    }

    /**
     * Swap what is in the next shipment.
     *
     * Written as cycle-pinned items, so it changes one delivery and the one after goes back to
     * normal without anybody having to remember to put it back.
     */
    public function actionSwap(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $subscription = $this->_subscription();
        $box = $subscription->getBox();
        $plan = $subscription->getPlan();

        if (!$plugin->getEffectiveSwapEnabled() || $box === null || !$box->allowSwap || !$plan?->allowSwap) {
            return $this->_fail($subscription, Craft::t('subscribr', 'This subscription’s contents can’t be changed.'));
        }

        if ($error = $this->_windowError($subscription)) {
            return $this->_fail($subscription, $error);
        }

        $selection = $this->_selection();
        $errors = $plugin->getBoxes()->validateSelection($box, $selection);

        if ($errors !== []) {
            return $this->_fail($subscription, implode(' ', $errors));
        }

        $cycle = $subscription->cycleCount + 1;
        $permanent = (bool)$this->request->getBodyParam('permanent');
        $items = $plugin->getBoxes()->selectionToItems($box, $selection, $permanent ? null : $cycle);

        $plugin->getSubscriptions()->saveItems($subscription, $items, $permanent ? null : $cycle);
        $plugin->getSubscriptions()->refreshRenewalPrice($subscription);

        if (!$permanent) {
            $plugin->getSchedules()->book($subscription, ScheduledAction::SWAP, $cycle, ['items' => count($items)]);
        }

        $plugin->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_SWAPPED,
            $permanent
                ? Craft::t('subscribr', 'Contents changed for every future order.')
                : Craft::t('subscribr', 'Contents changed for the next order only.'),
            ['cycle' => $permanent ? null : $cycle],
            LogEntry::SOURCE_PORTAL,
        );

        return $this->_ok($subscription, $permanent
            ? Craft::t('subscribr', 'Saved — that’s what you’ll get from now on.')
            : Craft::t('subscribr', 'Saved — that’s what’s in your next order.'));
    }

    /**
     * What a plan change would cost, before agreeing to it.
     *
     * The same `Proration` the charge is taken from, so the confirmation cannot disagree with the
     * bill.
     */
    public function actionPreviewSwitch(): Response
    {
        $this->requireAcceptsJson();

        $subscription = $this->_subscription(false);
        $plan = Plugin::getInstance()->getPlans()->getPlanByHandle((string)$this->request->getRequiredParam('plan'));

        if ($plan === null) {
            return $this->asFailure(Craft::t('subscribr', 'That plan isn’t available.'));
        }

        $proration = Plugin::getInstance()->getProration()->preview($subscription, $plan);

        return $this->asJson([
            'success' => true,
            'mode' => $proration->mode,
            'netDue' => $proration->getNetDue(),
            'netDueFormatted' => $proration->formatAmount(max(0, $proration->getNetDue())),
            'creditFormatted' => $proration->formatAmount($proration->credit),
            'chargeFormatted' => $proration->formatAmount($proration->charge),
            'carriedCredit' => $proration->carriedCredit,
            'carriedCreditFormatted' => $proration->formatAmount($proration->carriedCredit),
            'daysRemaining' => $proration->daysRemaining,
            'daysInPeriod' => $proration->daysInPeriod,
            'usedFraction' => $proration->getUsedFraction(),
            'effectiveDate' => $proration->effectiveDate?->format('Y-m-d'),
            'newCycleAmount' => $proration->newCycleAmount,
            'newCycleAmountFormatted' => $proration->formatAmount($proration->newCycleAmount),
            'cadence' => $plan->getCadence()->describe(),
            'lines' => $proration->lines,
        ]);
    }

    public function actionSwitch(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $subscription = $this->_subscription();
        $plan = $plugin->getPlans()->getPlanByHandle((string)$this->request->getRequiredBodyParam('plan'));

        if (!$plugin->getEffectiveSwitchingEnabled() || $plan === null) {
            return $this->_fail($subscription, Craft::t('subscribr', 'That plan isn’t available.'));
        }

        [$ok, $proration, $error] = $plugin->getProration()->applySwitch($subscription, $plan);

        if (!$ok) {
            return $this->_fail($subscription, $error ?? Craft::t('subscribr', 'We couldn’t change your plan.'));
        }

        return $this->_ok($subscription, $proration->mode === \justinholtweb\subscribr\models\Proration::MODE_END
            ? Craft::t('subscribr', 'You’ll move to {plan} on {date}.', [
                'plan' => $plan->name,
                'date' => $proration->effectiveDate?->format('j M Y'),
            ])
            : Craft::t('subscribr', 'You’re on {plan}. {due} was charged today.', [
                'plan' => $plan->name,
                'due' => $proration->formatAmount(max(0, $proration->getNetDue())),
            ]));
    }

    public function actionQuantity(): ?Response
    {
        $this->requirePostRequest();

        $subscription = $this->_subscription();

        if (!$subscription->getPlan()?->allowQuantityChange) {
            return $this->_fail($subscription, Craft::t('subscribr', 'The quantity of this subscription can’t be changed here.'));
        }

        Plugin::getInstance()->getSubscriptions()->setQuantity(
            $subscription,
            (int)$this->request->getRequiredBodyParam('quantity'),
        );

        return $this->_ok($subscription, Craft::t('subscribr', 'Updated from your next order.'));
    }

    /**
     * Point the subscription at a different stored card.
     *
     * Subscribr never sees card details: the source is created by Commerce's own
     * `commerce/payment-sources/add` action, and this only chooses between the ones the customer
     * already has. Which is also why fixing a declined card is two steps and not one.
     */
    public function actionPaymentSource(): ?Response
    {
        $this->requirePostRequest();

        $subscription = $this->_subscription();
        $sourceId = (int)$this->request->getRequiredBodyParam('paymentSourceId');
        $user = Craft::$app->getUser()->getIdentity();

        $source = Commerce::getInstance()->getPaymentSources()->getPaymentSourceByIdAndUserId($sourceId, (int)$user?->id);

        if ($source === null) {
            throw new ForbiddenHttpException('That payment method isn’t yours.');
        }

        Plugin::getInstance()->getSubscriptions()->setPaymentSource($subscription, $sourceId, (int)$source->gatewayId);
        $subscription->isManual = false;
        Plugin::getInstance()->getSubscriptions()->save($subscription);

        return $this->_ok($subscription, Craft::t('subscribr', 'Payment method updated. We’ll try again shortly.'));
    }

    public function actionAddress(): ?Response
    {
        $this->requirePostRequest();

        $subscription = $this->_subscription();
        $user = Craft::$app->getUser()->getIdentity();

        foreach (['shippingAddressId', 'billingAddressId'] as $param) {
            $id = $this->request->getBodyParam($param);

            if ($id === null || $id === '') {
                continue;
            }

            $address = \craft\elements\Address::find()->id((int)$id)->owner($user)->one();

            if ($address === null) {
                throw new ForbiddenHttpException('That address isn’t yours.');
            }

            $subscription->$param = (int)$id;
        }

        Plugin::getInstance()->getSubscriptions()->save($subscription);

        return $this->_ok($subscription, Craft::t('subscribr', 'Address updated.'));
    }

    /**
     * Claim a gift.
     *
     * Anonymous, because the recipient may have arrived from an email and have no account yet —
     * but the claim itself needs one, so an unauthenticated visitor is sent to register and comes
     * back to the same token.
     */
    public function actionClaim(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $token = (string)$this->request->getRequiredBodyParam('token');
        $gift = $plugin->getGifts()->getGiftByToken($token);

        if ($gift === null) {
            throw new NotFoundHttpException('No such gift');
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            $this->setFailFlash(Craft::t('subscribr', 'Please sign in or create an account to claim your gift.'));

            return $this->redirect(\craft\helpers\UrlHelper::siteUrl(Craft::$app->getConfig()->getGeneral()->getLoginPath(), [
                'redirect' => $this->request->getPathInfo(),
            ]));
        }

        [$subscription, $error] = $plugin->getGifts()->claim($gift, $user);

        if ($subscription === null) {
            $this->setFailFlash($error ?? Craft::t('subscribr', 'This gift couldn’t be claimed.'));

            return $this->redirectToPostedUrl();
        }

        $this->setSuccessFlash(Craft::t('subscribr', 'Your gift is set up. Your first order is on its way.'));

        return $this->redirect($plugin->getNotifications()->portalUrl($subscription));
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * The subscription this request is about, scoped to the signed-in user.
     *
     * Looked up by reference **and** user ID in one query. A reference that belongs to somebody
     * else does not come back at all, so there is no branch in which the wrong subscription has
     * been loaded and something later is relied upon to notice.
     */
    private function _subscription(bool $requirePost = true): Subscription
    {
        $this->requireLogin();

        $user = Craft::$app->getUser()->getIdentity();
        $reference = $requirePost
            ? (string)$this->request->getRequiredBodyParam('subscription')
            : (string)$this->request->getRequiredParam('subscription');

        $subscription = Subscription::find()
            ->status(null)
            ->reference($reference)
            ->userId($user?->id)
            ->one();

        if (!$subscription instanceof Subscription) {
            throw new NotFoundHttpException('Subscription not found');
        }

        return $subscription;
    }

    /**
     * `[slotId => [purchasableId => qty]]`, cleaned of everything a form could smuggle in.
     */
    private function _selection(): array
    {
        $posted = $this->request->getBodyParam('selection', []);

        if (!is_array($posted)) {
            throw new BadRequestHttpException('Invalid selection');
        }

        $selection = [];

        foreach ($posted as $slotId => $items) {
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $purchasableId => $qty) {
                $qty = (int)$qty;

                if ($qty > 0) {
                    $selection[(int)$slotId][(int)$purchasableId] = min($qty, 999);
                }
            }
        }

        return $selection;
    }

    private function _windowError(Subscription $subscription): ?string
    {
        [$open, $reason] = Plugin::getInstance()->getSchedules()->changeWindow($subscription);

        return $open ? null : $reason;
    }

    private function _ok(Subscription $subscription, string $message): ?Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess($message, [
                'subscription' => [
                    'reference' => $subscription->reference,
                    'status' => $subscription->getStatus(),
                    'dateNextPayment' => $subscription->dateNextPayment?->format('Y-m-d'),
                ],
            ]);
        }

        $this->setSuccessFlash($message);

        return $this->redirectToPostedUrl($subscription);
    }

    private function _fail(Subscription $subscription, string $message): ?Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asFailure($message);
        }

        $this->setFailFlash($message);

        return $this->redirectToPostedUrl($subscription);
    }
}
