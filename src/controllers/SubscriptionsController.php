<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\controllers;

use Craft;
use craft\web\Controller;
use DateTime;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\models\LogEntry;
use justinholtweb\subscribr\models\RenewalResult;
use justinholtweb\subscribr\models\ScheduledAction;
use justinholtweb\subscribr\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Subscriptions, in the control panel.
 *
 * Every action here goes through the services rather than touching the element, so a change made
 * by a support agent leaves the same history as one made by the subscriber, and both are subject
 * to the same rules.
 */
class SubscriptionsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('subscribr-viewSubscriptions');

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('subscribr/subscriptions/_index', [
            'title' => Craft::t('subscribr', 'Subscriptions'),
            'elementType' => Subscription::class,
            'capable' => Plugin::getInstance()->getBilling()->getHasCapableGateway(),
        ]);
    }

    public function actionEdit(int $subscriptionId): Response
    {
        $plugin = Plugin::getInstance();
        $subscription = $plugin->getSubscriptions()->getSubscriptionById($subscriptionId);

        if ($subscription === null) {
            throw new NotFoundHttpException('Subscription not found');
        }

        $plan = $subscription->getPlan();

        return $this->renderTemplate('subscribr/subscriptions/_edit', [
            'subscription' => $subscription,
            'plan' => $plan,
            'items' => $subscription->getItemsForCycle(),
            'history' => $subscription->getHistory(),
            'orders' => $subscription->getOrders(),
            'attempts' => $plugin->getBilling()->getAttempts($subscriptionId),
            'pending' => $subscription->getPendingActions(),
            'capability' => $subscription->getGatewayCapability(),
            'switchOptions' => $plan && $plugin->getEffectiveSwitchingEnabled()
                ? $plugin->getPlans()->getSwitchOptions($plan)
                : [],
            'canManage' => Craft::$app->getUser()->checkPermission('subscribr-manageSubscriptions'),
            'canBill' => Craft::$app->getUser()->checkPermission('subscribr-billSubscriptions'),
        ]);
    }

    public function actionSaveNote(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('subscribr-manageSubscriptions');

        $subscription = $this->_subscription();
        $subscription->note = $this->request->getBodyParam('note');
        Plugin::getInstance()->getSubscriptions()->save($subscription);

        return $this->_back(Craft::t('subscribr', 'Note saved.'));
    }

    public function actionPause(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('subscribr-manageSubscriptions');

        $subscription = $this->_subscription();
        $until = $this->request->getBodyParam('until');

        Plugin::getInstance()->getSubscriptions()->pause(
            $subscription,
            $until ? new DateTime($until) : null,
            $this->request->getBodyParam('reason'),
        );

        return $this->_back(Craft::t('subscribr', 'Subscription paused.'));
    }

    public function actionResume(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('subscribr-manageSubscriptions');

        Plugin::getInstance()->getSubscriptions()->resume($this->_subscription());

        return $this->_back(Craft::t('subscribr', 'Subscription resumed.'));
    }

    public function actionCancel(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('subscribr-manageSubscriptions');

        Plugin::getInstance()->getSubscriptions()->cancel(
            $this->_subscription(),
            $this->request->getBodyParam('reason'),
            (bool)$this->request->getBodyParam('immediately'),
        );

        return $this->_back(Craft::t('subscribr', 'Subscription cancelled.'));
    }

    public function actionUncancel(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('subscribr-manageSubscriptions');

        if (!Plugin::getInstance()->getSubscriptions()->uncancel($this->_subscription())) {
            return $this->_back(Craft::t('subscribr', 'This subscription has already ended and can’t be reinstated. The subscriber needs to sign up again.'), false);
        }

        return $this->_back(Craft::t('subscribr', 'Cancellation reversed.'));
    }

    public function actionSetQuantity(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('subscribr-manageSubscriptions');

        Plugin::getInstance()->getSubscriptions()->setQuantity(
            $this->_subscription(),
            (int)$this->request->getBodyParam('quantity', 1),
        );

        return $this->_back(Craft::t('subscribr', 'Quantity updated from the next renewal.'));
    }

    /**
     * Renew now, out of schedule.
     *
     * Forced, so it works on a subscription that is not due — which is the only reason anybody
     * presses this button. It still goes through the same engine, so it still writes history, and
     * it still moves the schedule on from the date that *was* due rather than from today.
     */
    public function actionRenewNow(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('subscribr-billSubscriptions');

        $subscription = $this->_subscription();
        $result = Plugin::getInstance()->getRenewals()->renew($subscription, null, true);

        return $this->_back(
            $this->_renewalMessage($result),
            $result->getIsSuccess(),
        );
    }

    public function actionRetry(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('subscribr-billSubscriptions');

        $result = Plugin::getInstance()->getDunning()->retry($this->_subscription());

        return $this->_back($this->_renewalMessage($result), $result->getIsSuccess());
    }

    /**
     * Preview a plan switch without doing it.
     */
    public function actionPreviewSwitch(): Response
    {
        $this->requireAcceptsJson();

        $subscription = $this->_subscription();
        $plan = Plugin::getInstance()->getPlans()->getPlanById((int)$this->request->getParam('planId'));

        if ($plan === null) {
            return $this->asFailure(Craft::t('subscribr', 'No such plan.'));
        }

        $proration = Plugin::getInstance()->getProration()->preview($subscription, $plan);

        return $this->asJson([
            'success' => true,
            'mode' => $proration->mode,
            'credit' => $proration->credit,
            'charge' => $proration->charge,
            'netDue' => $proration->getNetDue(),
            'netDueFormatted' => $proration->formatAmount($proration->getNetDue()),
            'carriedCredit' => $proration->carriedCredit,
            'daysRemaining' => $proration->daysRemaining,
            'daysInPeriod' => $proration->daysInPeriod,
            'usedFraction' => $proration->getUsedFraction(),
            'effectiveDate' => $proration->effectiveDate?->format('Y-m-d'),
            'lines' => array_map(static fn(array $line): array => $line + [
                'amountFormatted' => \justinholtweb\subscribr\helpers\Money::format($line['amount']),
            ], $proration->lines),
        ]);
    }

    public function actionSwitchPlan(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('subscribr-manageSubscriptions');

        if (!Plugin::getInstance()->getEffectiveSwitchingEnabled()) {
            throw new ForbiddenHttpException('Plan switching is a Pro feature.');
        }

        $subscription = $this->_subscription();
        $plan = Plugin::getInstance()->getPlans()->getPlanById((int)$this->request->getBodyParam('planId'));

        if ($plan === null) {
            return $this->_back(Craft::t('subscribr', 'No such plan.'), false);
        }

        [$ok, $proration, $error] = Plugin::getInstance()->getProration()->applySwitch($subscription, $plan);

        if (!$ok) {
            return $this->_back($error ?? Craft::t('subscribr', 'The plan could not be changed.'), false);
        }

        return $this->_back(Craft::t('subscribr', 'Moved to {plan}. {due} charged.', [
            'plan' => $plan->name,
            'due' => $proration->formatAmount(max(0, $proration->getNetDue())),
        ]));
    }

    /**
     * Book a change for a future cycle: skip, swap, pause, switch.
     */
    public function actionSchedule(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('subscribr-manageSubscriptions');

        $subscription = $this->_subscription();
        $action = (string)$this->request->getRequiredBodyParam('scheduledAction');

        if (!in_array($action, ScheduledAction::ACTIONS, true)) {
            return $this->_back(Craft::t('subscribr', 'That isn’t something that can be scheduled.'), false);
        }

        Plugin::getInstance()->getSchedules()->book(
            $subscription,
            $action,
            $this->request->getBodyParam('cycle') !== null ? (int)$this->request->getBodyParam('cycle') : $subscription->cycleCount + 1,
            (array)($this->request->getBodyParam('payload') ?? []),
        );

        Plugin::getInstance()->getLedger()->log(
            (int)$subscription->id,
            LogEntry::TYPE_NOTE,
            Craft::t('subscribr', '“{action}” booked for the next renewal.', ['action' => $action]),
        );

        return $this->_back(Craft::t('subscribr', 'Booked for the next renewal.'));
    }

    public function actionCancelScheduled(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('subscribr-manageSubscriptions');

        $scheduled = Plugin::getInstance()->getSchedules()->getById((int)$this->request->getRequiredBodyParam('scheduleId'));

        if ($scheduled === null) {
            return $this->_back(Craft::t('subscribr', 'That change is no longer booked.'), false);
        }

        Plugin::getInstance()->getSchedules()->cancel($scheduled);

        return $this->_back(Craft::t('subscribr', 'Booked change cancelled.'));
    }

    // Internals
    // -------------------------------------------------------------------------

    private function _subscription(): Subscription
    {
        $id = (int)$this->request->getRequiredBodyParam('subscriptionId');
        $subscription = Plugin::getInstance()->getSubscriptions()->getSubscriptionById($id);

        if ($subscription === null) {
            throw new NotFoundHttpException('Subscription not found');
        }

        return $subscription;
    }

    private function _renewalMessage(RenewalResult $result): string
    {
        return match ($result->outcome) {
            RenewalResult::RENEWED => Craft::t('subscribr', 'Renewed. Next payment {date}.', ['date' => $result->nextPaymentDate?->format('j M Y')]),
            RenewalResult::PREPAID => Craft::t('subscribr', 'Shipped from prepaid credit.'),
            RenewalResult::MANUAL => Craft::t('subscribr', 'Invoiced — the subscriber has been emailed a link to pay it.'),
            RenewalResult::SKIPPED => Craft::t('subscribr', 'This cycle was skipped.'),
            RenewalResult::PAUSED => Craft::t('subscribr', 'The subscription is paused.'),
            RenewalResult::ENDED => Craft::t('subscribr', 'The subscription has ended.'),
            RenewalResult::NOT_DUE => Craft::t('subscribr', 'Nothing to do — this subscription isn’t due.'),
            RenewalResult::ABANDONED => Craft::t('subscribr', 'Missed cycles were skipped rather than billed.'),
            default => Craft::t('subscribr', 'Payment failed: {message}', ['message' => $result->message ?? '']),
        };
    }

    private function _back(string $message, bool $success = true): Response
    {
        if ($this->request->getAcceptsJson()) {
            return $success ? $this->asSuccess($message) : $this->asFailure($message);
        }

        if ($success) {
            $this->setSuccessFlash($message);
        } else {
            $this->setFailFlash($message);
        }

        return $this->redirectToPostedUrl();
    }
}
