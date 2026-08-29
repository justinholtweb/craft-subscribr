<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\subscribr\models\Plan;
use justinholtweb\subscribr\Plugin;
use yii\console\ExitCode;

/**
 * Moving plans between environments.
 *
 * Plans live in the database rather than project config, because a plan can point at a box and a
 * box points at purchasables — and a purchasable ID means nothing in another environment. Export
 * and import move them by *handle*, and a box by its handle too, so the same file applies cleanly
 * to staging and production.
 */
class PlansController extends Controller
{
    /** Where to write, or read. Defaults to stdout / stdin. */
    public ?string $file = null;

    /** Report what would change without writing anything. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['file', 'dryRun']);
    }

    public function actionList(): int
    {
        $plugin = Plugin::getInstance();
        $plans = $plugin->getPlans()->getAllPlans();

        if ($plans === []) {
            $this->stdout("No plans.\n");

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-24s %-20s %-16s %-8s %s\n", 'HANDLE', 'NAME', 'CADENCE', 'SUBS', ''), Console::BOLD);

        foreach ($plans as $plan) {
            $this->stdout(sprintf(
                "%-24s %-20s %-16s %-8d %s\n",
                $plan->handle,
                mb_substr($plan->name, 0, 19),
                $plan->describe(),
                $plugin->getPlans()->getSubscriberCount((int)$plan->id),
                $plan->enabled ? '' : '(disabled)',
            ));
        }

        return ExitCode::OK;
    }

    public function actionExport(): int
    {
        $plugin = Plugin::getInstance();
        $data = ['plans' => []];

        foreach ($plugin->getPlans()->getAllPlans() as $plan) {
            $data['plans'][] = $plugin->getPlans()->toExportArray($plan);
        }

        $json = Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($this->file) {
            file_put_contents($this->file, $json . "\n");
            $this->stdout(sprintf("%d plans written to %s\n", count($data['plans']), $this->file), Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout($json . "\n");

        return ExitCode::OK;
    }

    public function actionImport(?string $file = null): int
    {
        $file ??= $this->file;

        if (!$file || !is_readable($file)) {
            $this->stderr("A readable --file is required.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $data = Json::decodeIfJson(file_get_contents($file));

        if (!is_array($data) || !isset($data['plans'])) {
            $this->stderr("That file doesn’t look like a plan export.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $plugin = Plugin::getInstance();
        $created = 0;
        $updated = 0;

        foreach ($data['plans'] as $row) {
            $handle = $row['handle'] ?? null;

            if (!$handle) {
                continue;
            }

            $existing = $plugin->getPlans()->getPlanByHandle($handle);
            $plan = $existing ?? new Plan();

            foreach ($row as $key => $value) {
                if (in_array($key, ['box', 'dunning'], true) || !$plan->canSetProperty($key)) {
                    continue;
                }

                $plan->$key = $value;
            }

            // Resolved by handle, and left alone when the target environment has no such box —
            // silently attaching the plan to nothing would turn a box subscription into an empty
            // parcel, so the plan keeps whatever it had and the mismatch is reported.
            if (!empty($row['box'])) {
                $box = $plugin->getBoxes()->getBoxByHandle((string)$row['box']);

                if ($box === null) {
                    $this->stdout(sprintf("  ! %s references box “%s”, which does not exist here\n", $handle, $row['box']), Console::FG_YELLOW);
                } else {
                    $plan->boxId = $box->id;
                }
            }

            if (!empty($row['dunning'])) {
                $profile = $plugin->getDunning()->getProfileByHandle((string)$row['dunning']);

                if ($profile !== null) {
                    $plan->dunningId = $profile->id;
                }
            }

            if ($this->dryRun) {
                $this->stdout(sprintf("  %s %s\n", $existing ? 'update' : 'create', $handle));
                continue;
            }

            if (!$plugin->getPlans()->savePlan($plan)) {
                $this->stderr(sprintf("  ✗ %s: %s\n", $handle, Json::encode($plan->getErrors())), Console::FG_RED);
                continue;
            }

            $existing ? $updated++ : $created++;
            $this->stdout(sprintf("  ✓ %s\n", $handle), Console::FG_GREEN);
        }

        if (!$this->dryRun) {
            $this->stdout(sprintf("\n%d created, %d updated.\n", $created, $updated));
        }

        return ExitCode::OK;
    }
}
