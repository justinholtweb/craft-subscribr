<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\models;

use craft\base\Model;

/**
 * One step in a dunning sequence: wait this long, then do this.
 */
class DunningStage extends Model
{
    public const ACTION_RETRY = 'retry';
    public const ACTION_EMAIL = 'email';
    public const ACTION_PAUSE = 'pause';
    public const ACTION_CANCEL = 'cancel';
    public const ACTION_EXPIRE = 'expire';
    public const ACTION_NOTIFY_STAFF = 'notifyStaff';

    public const ACTIONS = [
        self::ACTION_RETRY,
        self::ACTION_EMAIL,
        self::ACTION_PAUSE,
        self::ACTION_CANCEL,
        self::ACTION_EXPIRE,
        self::ACTION_NOTIFY_STAFF,
    ];

    /** Hours after the *first* failure, not after the previous stage. Absolute offsets sort. */
    public int $offsetHours = 24;

    public string $action = self::ACTION_RETRY;

    /** Sent alongside a retry, or on its own for an `email` stage. Null uses the default template. */
    public ?string $emailKey = null;

    public ?string $note = null;

    /**
     * Whether this stage ends the sequence. Cancel, expire and pause do; a retry does not.
     */
    public function getIsTerminal(): bool
    {
        return in_array($this->action, [self::ACTION_PAUSE, self::ACTION_CANCEL, self::ACTION_EXPIRE], true);
    }

    protected function defineRules(): array
    {
        return [
            [['offsetHours'], 'integer', 'min' => 0, 'max' => 8760],
            [['action'], 'in', 'range' => self::ACTIONS],
        ];
    }
}
