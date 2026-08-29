<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\models;

use craft\base\Model;
use craft\elements\User;
use craft\helpers\Json;
use DateTime;

/**
 * One line of a subscription's history.
 *
 * Named LogEntry rather than Event so that nothing in the plugin has to disambiguate it from
 * `yii\base\Event` at every use site.
 *
 * The ledger is append-only and it is the *same* ledger the CP timeline and the subscriber's own
 * portal read. There is no second, friendlier history for the customer: if a merchant paused
 * somebody's subscription, the subscriber can see that it was paused.
 */
class LogEntry extends Model
{
    public const SOURCE_CP = 'cp';
    public const SOURCE_PORTAL = 'portal';
    public const SOURCE_CONSOLE = 'console';
    public const SOURCE_QUEUE = 'queue';
    public const SOURCE_API = 'api';

    public const TYPE_CREATED = 'created';
    public const TYPE_ACTIVATED = 'activated';
    public const TYPE_TRIAL_ENDED = 'trialEnded';
    public const TYPE_RENEWED = 'renewed';
    public const TYPE_PAYMENT_FAILED = 'paymentFailed';
    public const TYPE_PAYMENT_RECOVERED = 'paymentRecovered';
    public const TYPE_DUNNING_STAGE = 'dunningStage';
    public const TYPE_SKIPPED = 'skipped';
    public const TYPE_PAUSED = 'paused';
    public const TYPE_RESUMED = 'resumed';
    public const TYPE_SWAPPED = 'swapped';
    public const TYPE_SWITCHED = 'switched';
    public const TYPE_QUANTITY = 'quantityChanged';
    public const TYPE_CANCELED = 'canceled';
    public const TYPE_UNCANCELED = 'uncanceled';
    public const TYPE_EXPIRED = 'expired';
    public const TYPE_PREPAID_DRAWN = 'prepaidDrawn';
    public const TYPE_GIFTED = 'gifted';
    public const TYPE_GIFT_CLAIMED = 'giftClaimed';
    public const TYPE_PAYMENT_SOURCE = 'paymentSourceChanged';
    public const TYPE_NOTE = 'note';

    public ?int $id = null;
    public ?int $subscriptionId = null;
    public string $type = self::TYPE_NOTE;
    public ?string $message = null;
    public array $data = [];
    public ?int $userId = null;
    public string $source = self::SOURCE_CONSOLE;
    public ?DateTime $dateCreated = null;
    public ?string $uid = null;

    public function setData(array|string|null $data): void
    {
        if (is_string($data)) {
            $data = Json::decodeIfJson($data);
        }

        $this->data = is_array($data) ? $data : [];
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getUser(): ?User
    {
        return $this->userId ? User::find()->id($this->userId)->status(null)->one() : null;
    }

    /**
     * Who to credit in the timeline. "Subscribr" is not a cop-out — an automatic renewal genuinely
     * has no actor, and attributing it to the subscriber would be a lie that matters in a dispute.
     */
    public function getActorName(): string
    {
        $user = $this->getUser();

        if ($user) {
            return (string)$user;
        }

        return match ($this->source) {
            self::SOURCE_QUEUE, self::SOURCE_CONSOLE => 'Subscribr',
            self::SOURCE_API => 'API',
            default => 'System',
        };
    }
}
