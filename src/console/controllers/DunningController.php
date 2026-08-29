<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use DateTime;
use justinholtweb\subscribr\Plugin;
use yii\console\ExitCode;

/**
 * The dunning sweep, and the report of what it has been doing.
 */
class DunningController extends Controller
{
    public ?int $limit = null;
    public int $days = 30;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'run' => ['limit'],
            'report' => ['days'],
            default => [],
        });
    }

    /**
     * Take the next dunning step for everything whose retry is due.
     */
    public function actionRun(): int
    {
        $results = Plugin::getInstance()->getDunning()->run($this->limit);

        if ($results === []) {
            $this->stdout("Nothing to retry.\n");

            return ExitCode::OK;
        }

        $recovered = 0;

        foreach ($results as $result) {
            $ok = $result->getIsSuccess();
            $recovered += $ok ? 1 : 0;

            $this->stdout(sprintf(
                "  %-10s #%-6d %s\n",
                $result->outcome,
                $result->subscriptionId,
                $result->message ?? '',
            ), $ok ? Console::FG_GREEN : Console::FG_YELLOW);
        }

        $this->stdout(sprintf("\n%d attempted, %d recovered.\n", count($results), $recovered));

        return ExitCode::OK;
    }

    /**
     * How much money is at risk, and how much of it is coming back.
     */
    public function actionReport(): int
    {
        $summary = Plugin::getInstance()->getDunning()->getSummary((new DateTime())->modify('-' . $this->days . ' days'));

        $this->stdout("Dunning, last {$this->days} days\n", Console::BOLD);
        $this->stdout(sprintf("  Past due          %d subscriptions\n", $summary['pastDueCount']));
        $this->stdout(sprintf("  At risk           %s\n", number_format($summary['atRisk'], 2)));
        $this->stdout(sprintf("  Failed attempts   %d\n", $summary['failedAttempts']));
        $this->stdout(sprintf("  Recovered         %d\n", $summary['recovered']));
        $this->stdout(sprintf(
            "  Recovery rate     %s\n",
            $summary['recoveryRate'] === null ? 'n/a' : $summary['recoveryRate'] . '%',
        ));

        $atRisk = Plugin::getInstance()->getDunning()->getAtRisk(20);

        if ($atRisk !== []) {
            $this->stdout("\nNext retries\n", Console::BOLD);

            foreach ($atRisk as $subscription) {
                $this->stdout(sprintf(
                    "  %-12s %-24s stage %d  %s\n",
                    $subscription->reference,
                    mb_substr((string)($subscription->getSubscriber() ?? 'guest'), 0, 23),
                    $subscription->dunningStage,
                    $subscription->dateNextRetry?->format('Y-m-d H:i') ?? '—',
                ));
            }
        }

        return ExitCode::OK;
    }
}
