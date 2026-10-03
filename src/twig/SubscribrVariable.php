<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\twig;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use DateTime;
use justinholtweb\subscribr\elements\db\SubscriptionQuery;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\models\Box;
use justinholtweb\subscribr\models\Plan;
use justinholtweb\subscribr\models\Proration;
use justinholtweb\subscribr\Plugin;

/**
 * `craft.subscribr` — everything a front end needs.
 *
 * The read side of the portal. Nothing here changes anything; the actions are controller posts, so
 * a GET can never cancel somebody's subscription.
 */
class SubscribrVariable
{
    /**
     * A subscription query: `craft.subscribr.subscriptions.userId(currentUser.id).all()`.
     */
    public function subscriptions(array $criteria = []): SubscriptionQuery
    {
        $query = Subscription::find()->status(null);

        if ($criteria !== []) {
            Craft::configure($query, $criteria);
        }

        return $query;
    }

    /**
     * The signed-in customer's subscriptions.
     *
     * @return Subscription[]
     */
    public function mine(bool $liveOnly = true): array
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return [];
        }

        return Plugin::getInstance()->getSubscriptions()->getSubscriptionsForUser((int)$user->id, $liveOnly);
    }

    /**
     * One of the signed-in customer's subscriptions, by reference.
     *
     * **Scoped to the current user on purpose.** A reference in a URL is guessable, and a portal
     * page that looked up a subscription by reference alone would hand somebody else's billing
     * history to anyone who tried. Staff use the control panel.
     */
    public function subscription(string $reference): ?Subscription
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return null;
        }

        return Subscription::find()
            ->status(null)
            ->reference($reference)
            ->userId($user->id)
            ->one();
    }

    /** @return Plan[] */
    public function plans(bool $enabledOnly = true): array
    {
        $service = Plugin::getInstance()->getPlans();

        return $enabledOnly ? $service->getEnabledPlans() : $service->getAllPlans();
    }

    public function plan(string|int $handleOrId): ?Plan
    {
        $service = Plugin::getInstance()->getPlans();

        return is_int($handleOrId) ? $service->getPlanById($handleOrId) : $service->getPlanByHandle($handleOrId);
    }

    public function box(string|int $handleOrId): ?Box
    {
        $service = Plugin::getInstance()->getBoxes();

        return is_int($handleOrId) ? $service->getBoxById($handleOrId) : $service->getBoxByHandle($handleOrId);
    }

    /**
     * The line-item options that make a purchasable recurring.
     *
     * ```twig
     * <input type="hidden" name="purchasables[0][id]" value="{{ variant.id }}">
     * {% for key, value in craft.subscribr.options('monthly-coffee', { subscribrPrepaid: 6 }) %}
     *   <input type="hidden" name="purchasables[0][options][{{ key }}]" value="{{ value }}">
     * {% endfor %}
     * ```
     */
    public function options(string|Plan $plan, array $extra = []): array
    {
        $plan = is_string($plan) ? Plugin::getInstance()->getPlans()->getPlanByHandle($plan) : $plan;

        return $plan ? Plugin::getInstance()->getCarts()->optionsFor($plan, $extra) : [];
    }

    // Carts
    // -------------------------------------------------------------------------

    public function isRecurring(LineItem $lineItem): bool
    {
        return Plugin::getInstance()->getCarts()->isRecurring($lineItem);
    }

    public function planForLineItem(LineItem $lineItem): ?Plan
    {
        return Plugin::getInstance()->getCarts()->getPlanForLineItem($lineItem);
    }

    /**
     * What the cart commits the customer to after today, grouped by cadence.
     *
     * The number a mixed cart has to print next to its total.
     */
    public function cartSummary(?Order $order = null): array
    {
        $order ??= Commerce::getInstance()->getCarts()->getCart();

        return Plugin::getInstance()->getCarts()->getRecurringSummary($order);
    }

    public function cartHasSubscription(?Order $order = null): bool
    {
        $order ??= Commerce::getInstance()->getCarts()->getCart();

        return Plugin::getInstance()->getCarts()->getHasRecurringItems($order);
    }

    /** @return string[] */
    public function cartProblems(?Order $order = null): array
    {
        $order ??= Commerce::getInstance()->getCarts()->getCart();

        return Plugin::getInstance()->getCarts()->validate($order);
    }

    // Portal
    // -------------------------------------------------------------------------

    /**
     * What this subscriber may do, right now, to this subscription.
     *
     * The portal renders its buttons from this rather than working it out itself, so a button is
     * never shown for something the controller would refuse — which is the difference between a
     * self-service page and a page that generates support tickets.
     *
     * @return array<string, bool>
     */
    public function can(Subscription $subscription): array
    {
        $plugin = Plugin::getInstance();
        $plan = $subscription->getPlan();
        [$inWindow] = $plugin->getSchedules()->changeWindow($subscription);
        $live = $subscription->getIsLive();

        return [
            'skip' => $live && $inWindow && $plugin->getEffectiveSkipEnabled() && ($plan->allowSkip ?? false) && $this->_skipsLeft($subscription) !== 0,
            'pause' => $live && ($plan->allowPause ?? false) && !$subscription->getIsPaused(),
            'resume' => $subscription->getIsPaused(),
            'swap' => $live && $inWindow && $plugin->getEffectiveSwapEnabled() && ($plan->allowSwap ?? false) && $subscription->getBox()?->allowSwap === true,
            'switch' => $live && $plugin->getEffectiveSwitchingEnabled() && ($plan->allowSwitch ?? false),
            'quantity' => $live && ($plan->allowQuantityChange ?? false),
            'cancel' => $live && ($plan->allowCancel ?? false) && !$subscription->getIsCanceled(),
            'uncancel' => $subscription->getStatus() === Subscription::STATUS_CANCELED,
            'updatePayment' => $live,
        ];
    }

    /**
     * Why a change is not available, for the portal to say out loud.
     */
    public function changeLockReason(Subscription $subscription): ?string
    {
        [, $reason] = Plugin::getInstance()->getSchedules()->changeWindow($subscription);

        return $reason;
    }

    /** @return Plan[] */
    public function switchOptions(Subscription $subscription): array
    {
        $plan = $subscription->getPlan();

        if ($plan === null || !Plugin::getInstance()->getEffectiveSwitchingEnabled()) {
            return [];
        }

        return Plugin::getInstance()->getPlans()->getSwitchOptions($plan);
    }

    /**
     * What a switch would cost. The same object the charge is taken from.
     */
    public function proration(Subscription $subscription, Plan|string $newPlan, ?DateTime $at = null): ?Proration
    {
        $newPlan = is_string($newPlan) ? Plugin::getInstance()->getPlans()->getPlanByHandle($newPlan) : $newPlan;

        if ($newPlan === null) {
            return null;
        }

        return Plugin::getInstance()->getProration()->preview($subscription, $newPlan, $at);
    }

    /**
     * What is in the next box.
     *
     * @return \justinholtweb\subscribr\models\Item[]
     */
    public function nextShipment(Subscription $subscription): array
    {
        return Plugin::getInstance()->getBoxes()->contentsForCycle($subscription, $subscription->cycleCount + 1);
    }

    public function contentsLocked(Subscription $subscription): bool
    {
        return Plugin::getInstance()->getBoxes()->contentsAreLocked($subscription);
    }

    /** @return \justinholtweb\subscribr\models\ScheduledAction[] */
    public function pendingActions(Subscription $subscription): array
    {
        return $subscription->getPendingActions();
    }

    /** @return \justinholtweb\subscribr\models\LogEntry[] */
    public function history(Subscription $subscription, int $limit = 25): array
    {
        return $subscription->getHistory($limit);
    }

    public function cancelReasons(): array
    {
        return Plugin::getInstance()->getSettings()->cancelReasons;
    }

    /**
     * The gift waiting behind a claim token.
     */
    public function gift(string $token): ?\justinholtweb\subscribr\models\Gift
    {
        return Plugin::getInstance()->getGifts()->getGiftByToken($token);
    }

    private function _skipsLeft(Subscription $subscription): ?int
    {
        $plan = $subscription->getPlan();

        if ($plan === null || $plan->maxSkipsPerYear < 1) {
            return null;
        }

        $used = Plugin::getInstance()->getLedger()->countSince(
            (int)$subscription->id,
            \justinholtweb\subscribr\models\LogEntry::TYPE_SKIPPED,
            (new DateTime())->modify('-1 year'),
        );

        return max(0, $plan->maxSkipsPerYear - $used);
    }
}
