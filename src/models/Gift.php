<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\models;

use craft\base\Model;
use craft\elements\User;
use craft\helpers\StringHelper;
use DateTime;

/**
 * A gifted subscription.
 *
 * A gift is bought by one person and used by another, and the two things that follow from that
 * shape the whole feature:
 *
 * 1. **It is prepaid, and it ends.** A gift that quietly renewed against the giver's card after
 *    twelve months would be indefensible, so a gift has a cycle count and `autoRenew` off. The
 *    recipient can turn renewal on themselves, on their own card, once they have claimed it.
 * 2. **It exists before the recipient does.** They may have no account, and the email address may
 *    be wrong. So the subscription is created `pending` against the *purchaser*, and the claim
 *    moves it — which also means an unclaimed gift is visible and refundable.
 */
class Gift extends Model
{
    public ?int $id = null;
    public ?int $subscriptionId = null;
    public ?int $orderId = null;
    public ?int $purchaserId = null;
    public ?int $recipientId = null;
    public string $recipientEmail = '';
    public ?string $recipientName = null;
    public ?string $senderName = null;
    public ?string $message = null;
    public string $token = '';
    public int $cycles = 1;
    public ?DateTime $dateDeliver = null;
    public ?DateTime $dateDelivered = null;
    public ?DateTime $dateClaimed = null;
    public ?DateTime $dateExpires = null;
    public ?DateTime $dateCreated = null;
    public ?string $uid = null;

    public static function generateToken(): string
    {
        return StringHelper::UUID() . StringHelper::randomString(16);
    }

    public function getPurchaser(): ?User
    {
        return $this->purchaserId ? User::find()->id($this->purchaserId)->status(null)->one() : null;
    }

    public function getRecipient(): ?User
    {
        return $this->recipientId ? User::find()->id($this->recipientId)->status(null)->one() : null;
    }

    public function getIsClaimed(): bool
    {
        return $this->dateClaimed !== null;
    }

    public function getIsDelivered(): bool
    {
        return $this->dateDelivered !== null;
    }

    public function getIsExpired(): bool
    {
        return $this->dateExpires !== null && $this->dateExpires < new DateTime();
    }

    public function getIsClaimable(): bool
    {
        return !$this->getIsClaimed() && !$this->getIsExpired() && $this->subscriptionId !== null;
    }

    protected function defineRules(): array
    {
        return [
            [['recipientEmail'], 'required'],
            [['recipientEmail'], 'email'],
            [['cycles'], 'integer', 'min' => 1, 'max' => 120],
        ];
    }
}
