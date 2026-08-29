<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\models;

use craft\base\Model;
use craft\helpers\Json;
use DateTime;

/**
 * A change booked for later.
 *
 * Everything self-service that does not take effect this instant is one of these: skip the next
 * box, pause from the 1st, swap cycle 7's contents, move to the bigger plan at the boundary.
 *
 * Booking rather than mutating is the point. It means the subscriber can change their mind — a
 * booked action is cancelled, not undone — and it means the renewal engine has one place to look
 * to find out what this cycle is supposed to be, instead of a subscription whose columns were
 * quietly rewritten a fortnight ago by somebody who has since forgotten.
 */
class ScheduledAction extends Model
{
    public const SKIP = 'skip';
    public const PAUSE = 'pause';
    public const RESUME = 'resume';
    public const SWAP = 'swap';
    public const SWITCH_PLAN = 'switch';
    public const QUANTITY = 'quantity';
    public const CANCEL = 'cancel';
    public const ADDRESS = 'address';

    public const ACTIONS = [
        self::SKIP,
        self::PAUSE,
        self::RESUME,
        self::SWAP,
        self::SWITCH_PLAN,
        self::QUANTITY,
        self::CANCEL,
        self::ADDRESS,
    ];

    public ?int $id = null;
    public ?int $subscriptionId = null;
    public string $action = self::SKIP;
    public ?int $cycle = null;
    public ?DateTime $applyAt = null;
    public array $payload = [];
    public ?DateTime $appliedAt = null;
    public ?DateTime $canceledAt = null;
    public ?int $createdByUserId = null;
    public ?DateTime $dateCreated = null;
    public ?string $uid = null;

    public function setPayload(array|string|null $payload): void
    {
        if (is_string($payload)) {
            $payload = Json::decodeIfJson($payload);
        }

        $this->payload = is_array($payload) ? $payload : [];
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getIsPending(): bool
    {
        return $this->appliedAt === null && $this->canceledAt === null;
    }

    /**
     * Whether this action is due at `$cycle` / `$when`.
     *
     * A cycle-pinned action and a date-pinned action are both allowed, and an action with neither
     * is due at the next boundary — that is the common case, "skip my next one".
     */
    public function isDue(int $cycle, DateTime $when): bool
    {
        if (!$this->getIsPending()) {
            return false;
        }

        if ($this->cycle !== null) {
            return $this->cycle <= $cycle;
        }

        if ($this->applyAt !== null) {
            return $this->applyAt <= $when;
        }

        return true;
    }

    protected function defineRules(): array
    {
        return [
            [['action'], 'in', 'range' => self::ACTIONS],
            [['cycle'], 'integer', 'min' => 0],
        ];
    }
}
