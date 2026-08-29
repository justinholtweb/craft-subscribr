<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\models;

use craft\base\Model;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use DateTime;

/**
 * A named dunning sequence.
 *
 * Stages are stored as JSON rather than rows because a stage is never read on its own — the
 * dunning run always wants the whole ordered profile, and the CP always edits the whole thing.
 *
 * @property-read DunningStage[] $stages
 */
class DunningProfile extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;
    public bool $isDefault = false;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /** @var DunningStage[] */
    private array $_stages = [];

    public function __toString(): string
    {
        return $this->name;
    }

    /** @return DunningStage[] */
    public function getStages(): array
    {
        return $this->_stages;
    }

    /**
     * @param DunningStage[]|array[]|string|null $stages
     */
    public function setStages(array|string|null $stages): void
    {
        if (is_string($stages)) {
            $stages = Json::decodeIfJson($stages);
        }

        if (!is_array($stages)) {
            $stages = [];
        }

        $models = [];

        foreach ($stages as $stage) {
            if ($stage instanceof DunningStage) {
                $models[] = $stage;
                continue;
            }

            if (is_array($stage)) {
                $models[] = new DunningStage($stage);
            }
        }

        // Sorted on the way in, so every reader gets them in order and nothing has to remember to
        // sort. Offsets are from the first failure, so this is a genuine ordering.
        usort($models, static fn(DunningStage $a, DunningStage $b): int => $a->offsetHours <=> $b->offsetHours);

        $this->_stages = $models;
    }

    public function getStage(int $index): ?DunningStage
    {
        return $this->getStages()[$index] ?? null;
    }

    public function getStageCount(): int
    {
        return count($this->_stages);
    }

    /**
     * How long the whole sequence runs for, in hours — the number a merchant actually wants to
     * know when they are asked how long a card failure takes to become a cancellation.
     */
    public function getTotalHours(): int
    {
        $stages = $this->getStages();

        return $stages === [] ? 0 : end($stages)->offsetHours;
    }

    public function stagesToJson(): string
    {
        return Json::encode(array_map(static fn(DunningStage $s): array => [
            'offsetHours' => $s->offsetHours,
            'action' => $s->action,
            'emailKey' => $s->emailKey,
            'note' => $s->note,
        ], $this->getStages()));
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('subscribr/dunning/' . $this->id);
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['handle'], 'craft\validators\HandleValidator'],
        ];
    }
}
