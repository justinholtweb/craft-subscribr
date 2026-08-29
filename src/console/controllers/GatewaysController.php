<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\subscribr\Plugin;
use yii\console\ExitCode;

/**
 * "Will this work with my payment provider?" — answered from the command line, before anybody
 * builds a plan.
 */
class GatewaysController extends Controller
{
    public function actionIndex(): int
    {
        $capabilities = Plugin::getInstance()->getBilling()->getAllCapabilities();

        if ($capabilities === []) {
            $this->stdout("This store has no payment gateways.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-24s %-12s %-8s %-10s %s\n", 'GATEWAY', 'RENEWS', 'SOURCES', 'PURCHASE', 'NOTE'), Console::BOLD);

        foreach ($capabilities as $capability) {
            $this->stdout(sprintf(
                "%-24s %-12s %-8s %-10s %s\n",
                mb_substr($capability->name, 0, 23),
                $capability->mode,
                $capability->supportsPaymentSources ? 'yes' : 'no',
                $capability->supportsPurchase ? 'yes' : 'no',
                $capability->isCommerceSubscriptionGateway ? 'also a Commerce subscription gateway' : '',
            ), $capability->getIsAutomatic() ? Console::FG_GREEN : Console::FG_YELLOW);
        }

        $this->stdout("\n");

        foreach ($capabilities as $capability) {
            if (!$capability->getIsAutomatic()) {
                $this->stdout(sprintf("  %s: %s\n", $capability->name, $capability->reason));
            }
        }

        return ExitCode::OK;
    }
}
