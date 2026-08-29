<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\models\RenewalResult;
use justinholtweb\subscribr\Plugin;
use yii\console\ExitCode;

/**
 * The renewal sweep.
 *
 * This is what cron calls. Typically:
 *
 *     * * * * * php craft subscribr/renewals/run --limit=50
 *     0 * * * * php craft subscribr/dunning/run
 *
 * `--dry-run` is not decoration. A store turning Subscribr on for the first time, or restoring a
 * production database onto staging, wants to know exactly what a run would charge before it
 * charges it.
 */
class RenewalsController extends Controller
{
    /** How many subscriptions to renew. Defaults to the plugin's batch size. */
    public ?int $limit = null;

    /** Raise renewal orders this many hours early. Defaults to the plugin's setting. */
    public ?int $lead = null;

    /** List what would happen without taking any money. */
    public bool $dryRun = false;

    /** Push the work onto Craft's queue instead of doing it here. */
    public bool $queue = false;

    /** Renew one subscription by reference, whether it is due or not. */
    public ?string $reference = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'run' => ['limit', 'lead', 'dryRun', 'queue'],
            'one' => ['reference'],
            'due' => ['limit', 'lead'],
            default => [],
        });
    }

    public function optionAliases(): array
    {
        return ['l' => 'limit', 'd' => 'dryRun', 'r' => 'reference'];
    }

    /**
     * Renew everything that is due.
     */
    public function actionRun(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->autoRenew && !$this->dryRun) {
            $this->stdout("Automatic renewals are switched off in the plugin settings.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if ($this->dryRun) {
            return $this->actionDue();
        }

        if ($this->queue) {
            $n = $plugin->getRenewals()->queueDue($this->limit, $this->lead ?? 0);
            $this->stdout(sprintf("%d renewals queued.\n", $n), Console::FG_GREEN);

            return ExitCode::OK;
        }

        $results = $plugin->getRenewals()->runDue($this->limit, $this->lead ?? 0);

        if ($results === []) {
            $this->stdout("Nothing due.\n");

            return ExitCode::OK;
        }

        $counts = [];

        foreach ($results as $result) {
            $counts[$result->outcome] = ($counts[$result->outcome] ?? 0) + 1;
            $this->_line($result);
        }

        $this->stdout("\n");

        foreach ($counts as $outcome => $n) {
            $this->stdout(sprintf("  %-12s %d\n", $outcome, $n));
        }

        // A non-zero exit on failures, so a cron wrapper or a monitor notices declines without
        // having to parse the output. Failures are not errors — the sweep did its job — so the
        // code is distinct from a crash.
        $failed = ($counts[RenewalResult::FAILED] ?? 0) + ($counts[RenewalResult::ERROR] ?? 0);

        return $failed > 0 ? ExitCode::TEMPFAIL : ExitCode::OK;
    }

    /**
     * What a run would do, without doing it.
     */
    public function actionDue(): int
    {
        $plugin = Plugin::getInstance();
        $lead = $this->lead ?? $plugin->getSettings()->renewalLeadHours;
        $subscriptions = $plugin->getSubscriptions()->getDue($this->limit ?? 100, $lead);

        if ($subscriptions === []) {
            $this->stdout("Nothing due.\n");

            return ExitCode::OK;
        }

        $total = 0.0;

        $this->stdout(sprintf("%-12s %-24s %-14s %-12s %s\n", 'REFERENCE', 'SUBSCRIBER', 'DUE', 'AMOUNT', 'HOW'), Console::BOLD);

        foreach ($subscriptions as $subscription) {
            $amount = $subscription->getIsPrepaid() ? 0.0 : $subscription->getRenewalSubtotal();
            $total += $amount;

            $this->stdout(sprintf(
                "%-12s %-24s %-14s %-12s %s\n",
                $subscription->reference,
                mb_substr((string)($subscription->getSubscriber() ?? 'guest'), 0, 23),
                $subscription->dateNextPayment?->format('Y-m-d') ?? '—',
                number_format($amount, 2),
                $this->_how($subscription),
            ));
        }

        $this->stdout(sprintf("\n%d due, %s total.\n", count($subscriptions), number_format($total, 2)), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Renew one subscription now, whether it is due or not.
     */
    public function actionOne(?string $reference = null): int
    {
        $reference ??= $this->reference;

        if (!$reference) {
            $this->stderr("A subscription reference is required.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $subscription = Plugin::getInstance()->getSubscriptions()->getSubscriptionByReference($reference);

        if ($subscription === null) {
            $this->stderr("No subscription with that reference.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $result = Plugin::getInstance()->getRenewals()->renew($subscription, null, true);
        $this->_line($result);

        return $result->getIsSuccess() ? ExitCode::OK : ExitCode::TEMPFAIL;
    }

    private function _how(Subscription $subscription): string
    {
        if ($subscription->getIsPrepaid()) {
            return 'prepaid (' . $subscription->prepaidCyclesRemaining . ' left)';
        }

        if (Plugin::getInstance()->getBilling()->canChargeAutomatically($subscription)) {
            return 'stored card';
        }

        return 'invoice + pay link';
    }

    private function _line(RenewalResult $result): void
    {
        $colour = match (true) {
            $result->outcome === RenewalResult::FAILED, $result->outcome === RenewalResult::ERROR => Console::FG_RED,
            $result->getIsSuccess() => Console::FG_GREEN,
            default => Console::FG_YELLOW,
        };

        $this->stdout(sprintf(
            "  %-10s #%-6d %s%s\n",
            $result->outcome,
            $result->subscriptionId,
            $result->amount !== null ? number_format($result->amount, 2) . ' ' : '',
            $result->message ? '— ' . $result->message : '',
        ), $colour);
    }
}
