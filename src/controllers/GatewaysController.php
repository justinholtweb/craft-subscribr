<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use justinholtweb\subscribr\models\GatewayCapability;
use justinholtweb\subscribr\Plugin;
use yii\web\Response;

/**
 * The gateway capability screen.
 *
 * There is nothing to configure here, and that is the point. It is a straight answer to the
 * question every merchant asks first — *will this work with my payment provider?* — with the
 * reasoning shown, so a "no" tells them what to change rather than just refusing.
 */
class GatewaysController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission('accessPlugin-subscribr');

        $capabilities = Plugin::getInstance()->getBilling()->getAllCapabilities();
        $gateways = [];

        foreach (Commerce::getInstance()->getGateways()->getAllGateways() as $gateway) {
            $gateways[(int)$gateway->id] = $gateway;
        }

        return $this->renderTemplate('subscribr/gateways/_index', [
            'capabilities' => $capabilities,
            'gateways' => $gateways,
            'automatic' => count(array_filter($capabilities, static fn(GatewayCapability $c): bool => $c->getIsAutomatic())),
            'title' => Craft::t('subscribr', 'Gateways'),
        ]);
    }
}
