<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use justinholtweb\subscribr\models\Settings;
use justinholtweb\subscribr\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('accessPlugin-subscribr');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $statuses = [];

        if (Plugin::commerceIsReady()) {
            foreach (Commerce::getInstance()->getOrderStatuses()->getAllOrderStatuses() as $status) {
                $statuses[] = ['value' => $status->handle, 'label' => $status->name];
            }
        }

        return $this->renderTemplate('subscribr/settings/_index', [
            'settings' => $plugin->getSettings(),
            'orderStatuses' => $statuses,
            'isPro' => $plugin->isPro(),
            'suppressed' => $plugin->getHasSuppressedProSettings(),
            'capable' => Plugin::commerceIsReady() && $plugin->getBilling()->getHasCapableGateway(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $posted = (array)$this->request->getBodyParam('settings', []);

        // Retry hours arrive as a table, one row per stage. Turned into a plain list here rather
        // than in the model, which should not know what the form looked like.
        if (isset($posted['defaultRetryHours']) && is_array($posted['defaultRetryHours'])) {
            $posted['defaultRetryHours'] = array_values(array_filter(array_map(
                static fn($row): int => (int)(is_array($row) ? ($row['hours'] ?? 0) : $row),
                $posted['defaultRetryHours'],
            )));
        }

        if (isset($posted['cancelReasons']) && is_array($posted['cancelReasons'])) {
            $reasons = [];

            foreach ($posted['cancelReasons'] as $row) {
                if (is_array($row) && !empty($row['label'])) {
                    $key = !empty($row['key']) ? (string)$row['key'] : \craft\helpers\StringHelper::toKebabCase((string)$row['label']);
                    $reasons[$key] = (string)$row['label'];
                }
            }

            $posted['cancelReasons'] = $reasons;
        }

        // The editable table posts a list of rows; the setting is a map. Normalised here rather
        // than in the model, which should not have to know what the form looked like.
        if (isset($posted['emailTemplates']) && is_array($posted['emailTemplates'])) {
            $templates = [];

            foreach ($posted['emailTemplates'] as $row) {
                if (is_array($row) && !empty($row['key']) && !empty($row['template'])) {
                    $templates[(string)$row['key']] = (string)$row['template'];
                }
            }

            $posted['emailTemplates'] = $templates;
        }

        $settings = new Settings($posted);

        if (!$settings->validate()) {
            $this->setFailFlash(Craft::t('subscribr', 'Couldn’t save the settings.'));
            Craft::$app->getUrlManager()->setRouteParams(['settings' => $settings]);

            return null;
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            $this->setFailFlash(Craft::t('subscribr', 'Couldn’t save the settings.'));

            return null;
        }

        $this->setSuccessFlash(Craft::t('subscribr', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
