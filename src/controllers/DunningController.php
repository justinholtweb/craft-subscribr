<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\controllers;

use Craft;
use craft\web\Controller;
use DateTime;
use justinholtweb\subscribr\models\DunningProfile;
use justinholtweb\subscribr\models\DunningStage;
use justinholtweb\subscribr\models\LogEntry;
use justinholtweb\subscribr\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The dunning screen.
 *
 * This is the feature merchants ask for by describing a symptom — "I don't know how much money is
 * failing" — so the index leads with the money at risk and the recovery rate, and the list of
 * at-risk subscribers is underneath it with a retry button on each one.
 */
class DunningController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('subscribr-manageDunning');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $days = (int)$this->request->getParam('days', 30);
        $since = (new DateTime())->modify('-' . max(1, $days) . ' days');

        return $this->renderTemplate('subscribr/dunning/_index', [
            'summary' => $plugin->getDunning()->getSummary($since),
            'atRisk' => $plugin->getDunning()->getAtRisk(),
            'profiles' => $plugin->getDunning()->getAllProfiles(),
            'fallback' => $plugin->getDunning()->getFallbackProfile(),
            'days' => $days,
            'recent' => $plugin->getLedger()->getRecent(20, [
                LogEntry::TYPE_PAYMENT_FAILED,
                LogEntry::TYPE_PAYMENT_RECOVERED,
                LogEntry::TYPE_DUNNING_STAGE,
            ]),
            'profilesEnabled' => $plugin->getEffectiveDunningProfilesEnabled(),
            'isPro' => $plugin->isPro(),
        ]);
    }

    public function actionEdit(?int $profileId = null, ?DunningProfile $profile = null): Response
    {
        if (!Plugin::getInstance()->getEffectiveDunningProfilesEnabled()) {
            throw new ForbiddenHttpException(Craft::t('subscribr', 'Dunning profiles are a Pro feature.'));
        }

        $profile ??= $profileId
            ? Plugin::getInstance()->getDunning()->getProfileById($profileId)
            : new DunningProfile();

        if ($profile === null) {
            throw new NotFoundHttpException('Dunning profile not found');
        }

        return $this->renderTemplate('subscribr/dunning/_edit', [
            'profile' => $profile,
            'isNew' => !$profile->id,
            'title' => $profile->id ? $profile->name : Craft::t('subscribr', 'New dunning sequence'),
            'actions' => [
                ['value' => DunningStage::ACTION_RETRY, 'label' => Craft::t('subscribr', 'Retry the payment')],
                ['value' => DunningStage::ACTION_EMAIL, 'label' => Craft::t('subscribr', 'Email the subscriber')],
                ['value' => DunningStage::ACTION_NOTIFY_STAFF, 'label' => Craft::t('subscribr', 'Email a member of staff')],
                ['value' => DunningStage::ACTION_PAUSE, 'label' => Craft::t('subscribr', 'Pause the subscription')],
                ['value' => DunningStage::ACTION_CANCEL, 'label' => Craft::t('subscribr', 'Cancel the subscription')],
                ['value' => DunningStage::ACTION_EXPIRE, 'label' => Craft::t('subscribr', 'End the subscription')],
            ],
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        if (!Plugin::getInstance()->getEffectiveDunningProfilesEnabled()) {
            throw new ForbiddenHttpException(Craft::t('subscribr', 'Dunning profiles are a Pro feature.'));
        }

        $service = Plugin::getInstance()->getDunning();
        $id = $this->request->getBodyParam('profileId');
        $profile = $id ? $service->getProfileById((int)$id) : new DunningProfile();

        if ($profile === null) {
            throw new NotFoundHttpException('Dunning profile not found');
        }

        $profile->name = $this->request->getBodyParam('name', $profile->name);
        $profile->handle = $this->request->getBodyParam('handle', $profile->handle);
        $profile->description = $this->request->getBodyParam('description');
        $profile->isDefault = (bool)$this->request->getBodyParam('isDefault');

        $stages = [];

        foreach ((array)$this->request->getBodyParam('stages', []) as $row) {
            // A blank row from the two spare slots at the bottom of the form. Dropped rather than
            // defaulted, or every save would grow the sequence by two.
            if (empty($row['action'])) {
                continue;
            }

            $stages[] = [
                'offsetHours' => (int)($row['offsetHours'] ?? 24),
                'action' => (string)$row['action'],
                'emailKey' => $row['emailKey'] ?? null,
                'note' => $row['note'] ?? null,
            ];
        }

        $profile->setStages($stages);

        if (!$service->saveProfile($profile)) {
            $this->setFailFlash(Craft::t('subscribr', 'Couldn’t save the sequence.'));
            Craft::$app->getUrlManager()->setRouteParams(['profile' => $profile]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('subscribr', 'Dunning sequence saved.'));

        return $this->redirectToPostedUrl($profile);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        Plugin::getInstance()->getDunning()->deleteProfileById((int)$this->request->getRequiredBodyParam('id'));

        return $this->asSuccess(Craft::t('subscribr', 'Sequence deleted.'));
    }

    /**
     * Work the whole dunning queue by hand.
     */
    public function actionRunNow(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('subscribr-billSubscriptions');

        $results = Plugin::getInstance()->getDunning()->run();
        $recovered = count(array_filter($results, static fn($r): bool => $r->getIsSuccess()));

        $this->setSuccessFlash(Craft::t('subscribr', '{n} attempted, {recovered} recovered.', [
            'n' => count($results),
            'recovered' => $recovered,
        ]));

        return $this->redirectToPostedUrl();
    }
}
