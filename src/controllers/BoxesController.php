<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\subscribr\models\Box;
use justinholtweb\subscribr\models\BoxSlot;
use justinholtweb\subscribr\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class BoxesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('subscribr-managePlans');

        if (!Plugin::getInstance()->getEffectiveBoxesEnabled()) {
            throw new ForbiddenHttpException(Craft::t('subscribr', 'Subscription boxes are a Pro feature.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('subscribr/boxes/_index', [
            'boxes' => Plugin::getInstance()->getBoxes()->getAllBoxes(),
        ]);
    }

    public function actionEdit(?int $boxId = null, ?Box $box = null): Response
    {
        $box ??= $boxId ? Plugin::getInstance()->getBoxes()->getBoxById($boxId) : new Box();

        if ($box === null) {
            throw new NotFoundHttpException('Box not found');
        }

        return $this->renderTemplate('subscribr/boxes/_edit', [
            'box' => $box,
            'isNew' => !$box->id,
            'title' => $box->id ? $box->name : Craft::t('subscribr', 'New box'),
            'modes' => [
                ['value' => Box::MODE_CURATED, 'label' => Craft::t('subscribr', 'Curated — you fill it')],
                ['value' => Box::MODE_CHOICE, 'label' => Craft::t('subscribr', 'Build-a-box — the subscriber fills it')],
                ['value' => Box::MODE_SURPRISE, 'label' => Craft::t('subscribr', 'Surprise — picked at random, avoiding repeats')],
            ],
            'pricingModes' => [
                ['value' => Box::PRICING_CONTENTS, 'label' => Craft::t('subscribr', 'The sum of what’s in it')],
                ['value' => Box::PRICING_FIXED, 'label' => Craft::t('subscribr', 'One fixed price')],
                ['value' => Box::PRICING_BASE, 'label' => Craft::t('subscribr', 'A base price, plus add-ons')],
            ],
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $service = Plugin::getInstance()->getBoxes();
        $id = $this->request->getBodyParam('boxId');
        $box = $id ? $service->getBoxById((int)$id) : new Box();

        if ($box === null) {
            throw new NotFoundHttpException('Box not found');
        }

        $box->name = $this->request->getBodyParam('name', $box->name);
        $box->handle = $this->request->getBodyParam('handle', $box->handle);
        $box->description = $this->request->getBodyParam('description');
        $box->mode = $this->request->getBodyParam('mode', Box::MODE_CHOICE);
        $box->pricing = $this->request->getBodyParam('pricing', Box::PRICING_CONTENTS);
        $box->boxPrice = $this->_floatOrNull('boxPrice');
        $box->minItems = $this->_intOrNull('minItems');
        $box->maxItems = $this->_intOrNull('maxItems');
        $box->allowSwap = (bool)$this->request->getBodyParam('allowSwap');
        $box->lockHours = (int)$this->request->getBodyParam('lockHours', 24);
        $box->avoidRepeatCycles = (int)$this->request->getBodyParam('avoidRepeatCycles', 0);
        $box->enabled = (bool)$this->request->getBodyParam('enabled');

        $slots = [];

        foreach ((array)$this->request->getBodyParam('slots', []) as $row) {
            if (empty($row['name'])) {
                continue;
            }

            $slot = new BoxSlot();
            $slot->id = !empty($row['id']) ? (int)$row['id'] : null;
            $slot->name = (string)$row['name'];
            $slot->minItems = (int)($row['minItems'] ?? 1);
            $slot->maxItems = (int)($row['maxItems'] ?? 1);
            $slot->isAddOn = !empty($row['isAddOn']);
            $slot->setSources([
                'purchasableIds' => array_values(array_filter(array_map('intval', (array)($row['purchasableIds'] ?? [])))),
                'productTypeIds' => array_values(array_filter(array_map('intval', (array)($row['productTypeIds'] ?? [])))),
            ]);

            if (!$slot->validate()) {
                $box->addError('slots', implode(' ', array_merge(...array_values($slot->getErrors()))));
            }

            $slots[] = $slot;
        }

        $box->setSlots($slots);

        if ($box->hasErrors() || !$service->saveBox($box)) {
            $this->setFailFlash(Craft::t('subscribr', 'Couldn’t save the box.'));
            Craft::$app->getUrlManager()->setRouteParams(['box' => $box]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('subscribr', 'Box saved.'));

        return $this->redirectToPostedUrl($box);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        Plugin::getInstance()->getBoxes()->deleteBoxById((int)$this->request->getRequiredBodyParam('id'));

        return $this->asSuccess(Craft::t('subscribr', 'Box deleted.'));
    }

    private function _intOrNull(string $param): ?int
    {
        $value = $this->request->getBodyParam($param);

        return ($value === null || $value === '') ? null : (int)$value;
    }

    private function _floatOrNull(string $param): ?float
    {
        $value = $this->request->getBodyParam($param);

        return ($value === null || $value === '') ? null : (float)$value;
    }
}
