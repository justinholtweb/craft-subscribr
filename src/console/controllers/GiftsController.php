<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\subscribr\Plugin;
use yii\console\ExitCode;

/**
 * Gift delivery.
 *
 * A gift bought in November for Christmas Day is delivered by this, from cron:
 *
 *     0 8 * * * php craft subscribr/gifts/deliver
 */
class GiftsController extends Controller
{
    /**
     * Send every gift whose delivery date has come.
     */
    public function actionDeliver(): int
    {
        $service = Plugin::getInstance()->getGifts();
        $gifts = $service->getDeliverable();

        if ($gifts === []) {
            $this->stdout("Nothing to deliver.\n");

            return ExitCode::OK;
        }

        foreach ($gifts as $gift) {
            $service->deliver($gift);
            $this->stdout(sprintf("  ✓ %s\n", $gift->recipientEmail), Console::FG_GREEN);
        }

        $this->stdout(sprintf("\n%d delivered.\n", count($gifts)));

        return ExitCode::OK;
    }

    /**
     * List gifts that have been bought but never claimed.
     */
    public function actionUnclaimed(): int
    {
        $rows = (new \craft\db\Query())
            ->select(['recipientEmail', 'token', 'dateCreated', 'dateExpires'])
            ->from([\justinholtweb\subscribr\db\Table::GIFTS])
            ->where(['dateClaimed' => null])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->all();

        if ($rows === []) {
            $this->stdout("Every gift has been claimed.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-32s %-12s %s\n", 'RECIPIENT', 'BOUGHT', 'EXPIRES'), Console::BOLD);

        foreach ($rows as $row) {
            $this->stdout(sprintf(
                "%-32s %-12s %s\n",
                mb_substr((string)$row['recipientEmail'], 0, 31),
                substr((string)$row['dateCreated'], 0, 10),
                substr((string)($row['dateExpires'] ?? '—'), 0, 10),
            ));
        }

        return ExitCode::OK;
    }
}
