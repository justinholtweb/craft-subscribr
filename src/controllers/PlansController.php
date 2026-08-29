<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\subscribr\models\Cadence;
use justinholtweb\subscribr\models\Plan;
use justinholtweb\subscribr\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class PlansController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('subscribr-managePlans');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $plans = $plugin->getPlans()->getAllPlans();
        $counts = [];

        foreach ($plans as $plan) {
            $counts[$plan->id] = $plugin->getPlans()->getSubscriberCount((int)$plan->id);
        }

        return $this->renderTemplate('subscribr/plans/_index', [
            'plans' => $plans,
            'counts' => $counts,
            'capable' => $plugin->getBilling()->getHasCapableGateway(),
        ]);
    }

    public function actionEdit(?int $planId = null, ?Plan $plan = null): Response
    {
        $plugin = Plugin::getInstance();
        $plan ??= $planId ? $plugin->getPlans()->getPlanById($planId) : new Plan();

        if ($plan === null) {
            throw new NotFoundHttpException('Plan not found');
        }

        return $this->renderTemplate('subscribr/plans/_edit', [
            'plan' => $plan,
            'isNew' => !$plan->id,
            'title' => $plan->id ? $plan->name : Craft::t('subscribr', 'New plan'),
            'intervals' => array_map(static fn(string $i): array => [
                'value' => $i,
                'label' => ucfirst($i),
            ], Cadence::INTERVALS),
            'boxes' => $plugin->getEffectiveBoxesEnabled() ? $plugin->getBoxes()->getAllBoxes() : [],
            'profiles' => $plugin->getEffectiveDunningProfilesEnabled() ? $plugin->getDunning()->getAllProfiles() : [],
            'isPro' => $plugin->isPro(),
            'subscriberCount' => $plan->id ? $plugin->getPlans()->getSubscriberCount((int)$plan->id) : 0,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $id = $this->request->getBodyParam('planId');
        $plan = $id ? $plugin->getPlans()->getPlanById((int)$id) : new Plan();

        if ($plan === null) {
            throw new NotFoundHttpException('Plan not found');
        }

        $plan->name = $this->request->getBodyParam('name', $plan->name);
        $plan->handle = $this->request->getBodyParam('handle', $plan->handle);
        $plan->description = $this->request->getBodyParam('description');
        $plan->interval = $this->request->getBodyParam('interval', $plan->interval);
        $plan->intervalCount = (int)$this->request->getBodyParam('intervalCount', 1);
        $plan->anchorDay = $this->_intOrNull('anchorDay');
        $plan->trialDays = (int)$this->request->getBodyParam('trialDays', 0);
        $plan->signupFee = $this->_floatOrNull('signupFee');
        $plan->maxCycles = (int)$this->request->getBodyParam('maxCycles', 0);
        $plan->pricingMode = $this->request->getBodyParam('pricingMode', Plan::PRICING_INHERIT);
        $plan->planPrice = $this->_floatOrNull('planPrice');
        $plan->discountPercent = $this->_floatOrNull('discountPercent');
        $plan->allowPause = (bool)$this->request->getBodyParam('allowPause');
        $plan->maxPauseCycles = (int)$this->request->getBodyParam('maxPauseCycles', 0);
        $plan->allowCancel = (bool)$this->request->getBodyParam('allowCancel');
        $plan->allowQuantityChange = (bool)$this->request->getBodyParam('allowQuantityChange');
        $plan->cancelMode = $this->request->getBodyParam('cancelMode', Plan::CANCEL_END);
        $plan->switchMode = $this->request->getBodyParam('switchMode', Plan::SWITCH_PRORATE);
        $plan->switchGroup = $this->request->getBodyParam('switchGroup') ?: null;
        $plan->shippable = (bool)$this->request->getBodyParam('shippable');
        $plan->enabled = (bool)$this->request->getBodyParam('enabled');

        // Pro-only fields are only read on a Pro install. A Lite install posting them — from a
        // cached form, or by hand — must not be able to configure a feature it cannot then honour,
        // because a plan that promises a box it will not build is worse than one that never did.
        if ($plugin->isPro()) {
            $plan->boxId = $this->_intOrNull('boxId');
            $plan->dunningId = $this->_intOrNull('dunningId');
            $plan->prepaidOptions = $this->request->getBodyParam('prepaidOptions') ?: null;
            $plan->prepaidDiscountPercent = $this->_floatOrNull('prepaidDiscountPercent');
            $plan->allowSkip = (bool)$this->request->getBodyParam('allowSkip');
            $plan->maxSkipsPerYear = (int)$this->request->getBodyParam('maxSkipsPerYear', 0);
            $plan->allowSwap = (bool)$this->request->getBodyParam('allowSwap');
            $plan->allowSwitch = (bool)$this->request->getBodyParam('allowSwitch');
            $plan->allowGift = (bool)$this->request->getBodyParam('allowGift');
        }

        if (!$plugin->getPlans()->savePlan($plan)) {
            $this->setFailFlash(Craft::t('subscribr', 'Couldn’t save the plan.'));
            Craft::$app->getUrlManager()->setRouteParams(['plan' => $plan]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('subscribr', 'Plan saved.'));

        return $this->redirectToPostedUrl($plan);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int)$this->request->getRequiredBodyParam('id');

        if (!Plugin::getInstance()->getPlans()->deletePlanById($id)) {
            $message = Craft::t('subscribr', 'This plan still has subscribers, so it can’t be deleted. Disable it instead — existing subscriptions carry on and no new ones can start.');

            if ($this->request->getAcceptsJson()) {
                return $this->asFailure($message);
            }

            $this->setFailFlash($message);

            return $this->redirectToPostedUrl();
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('subscribr', 'Plan deleted.'));
        }

        $this->setSuccessFlash(Craft::t('subscribr', 'Plan deleted.'));

        return $this->redirectToPostedUrl();
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = \craft\helpers\Json::decode($this->request->getRequiredBodyParam('ids'));
        Plugin::getInstance()->getPlans()->reorderPlans($ids);

        return $this->asSuccess();
    }

    private function _intOrNull(string $param): ?int
    {
        $value = $this->request->getBodyParam($param);

        return ($value === null || $value === '') ? null : (int)$value;
    }

    private function _floatOrNull(string $param): ?float
    {
        $value = $this->request->getBodyParam($param);

        // A blank number field means "not set", which is not the same as zero — on a plan price,
        // zero means free and null means "use the product's price".
        return ($value === null || $value === '') ? null : (float)$value;
    }
}
