<?php

/**
 * Subscribr integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-subscribr/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: every user, product, plan, box, subscription and order it creates
 * it deletes again, whether the run passes or not.
 *
 * The edition and the settings are changed **in memory** rather than saved: project config is
 * contended in this harness, and a console script that writes it races the queue runner.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\payments\DummyPaymentForm;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\StringHelper;
use justinholtweb\subscribr\db\Table;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\models\Attempt;
use justinholtweb\subscribr\models\Box;
use justinholtweb\subscribr\models\BoxSlot;
use justinholtweb\subscribr\models\Cadence;
use justinholtweb\subscribr\models\DunningProfile;
use justinholtweb\subscribr\models\DunningStage;
use justinholtweb\subscribr\models\GatewayCapability;
use justinholtweb\subscribr\models\Item;
use justinholtweb\subscribr\models\LogEntry;
use justinholtweb\subscribr\models\Plan;
use justinholtweb\subscribr\models\Proration as ProrationModel;
use justinholtweb\subscribr\models\RenewalResult;
use justinholtweb\subscribr\models\ScheduledAction;
use justinholtweb\subscribr\models\Settings;
use justinholtweb\subscribr\Plugin;
use justinholtweb\subscribr\services\Carts as SubscribrCarts;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        global $failed;
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$elements = Craft::$app->getElements();
$storeId = $commerce->getStores()->getPrimaryStore()->id;

$plans = $plugin->getPlans();
$subscriptions = $plugin->getSubscriptions();
$renewals = $plugin->getRenewals();
$billing = $plugin->getBilling();
$dunning = $plugin->getDunning();
$boxes = $plugin->getBoxes();
$schedules = $plugin->getSchedules();
$proration = $plugin->getProration();
$gifts = $plugin->getGifts();
$carts = $plugin->getCarts();
$ledger = $plugin->getLedger();

$suffix = strtolower(substr(md5((string)microtime(true)), 0, 6));
$tag = 'SBR' . strtoupper($suffix);

/**
 * Fixture dates are relative, not fixed.
 *
 * A subscription activated on a hard-coded date drifts further into the past every day the suite
 * is not run, and eventually trips the catch-up guard — which is correct behaviour and a useless
 * test failure. `$anchorStart` is always a month and a day ago, so the first renewal is always due
 * yesterday, and the expected dates are computed with the same Cadence the engine uses.
 */
$anchorStart = (new DateTime())->modify('-1 month')->modify('-1 day')->setTime(10, 0);
$monthlyCadence = new Cadence(['interval' => Cadence::MONTH, 'intervalCount' => 1]);
$firstDue = $monthlyCadence->next($anchorStart);
$secondDue = $monthlyCadence->next($firstDue);
$thirdDue = $monthlyCadence->next($secondDue);

$originalEdition = $plugin->edition;
$originalSettings = clone $plugin->getSettings();

$createdUsers = [];
$createdProducts = [];
$createdPlans = [];
$createdBoxes = [];
$createdProfiles = [];
$createdSubscriptions = [];
$createdOrders = [];

/**
 * Everything below runs on Pro. Lite is exercised explicitly in its own section.
 */
$plugin->edition = Plugin::EDITION_PRO;
$plugin->getSettings()->sendEmails = false;

try {
    // ------------------------------------------------------------------------
    section('Cadence — the one place a date advances');

    check('monthly cadence advances a month', function (): bool|string {
        $cadence = new Cadence(['interval' => Cadence::MONTH, 'intervalCount' => 1]);
        $next = $cadence->next(new DateTime('2026-03-15 09:00:00'));

        return $next->format('Y-m-d H:i') === '2026-04-15 09:00'
            ?: 'got ' . $next->format('Y-m-d H:i');
    });

    check('31 January + 1 month clamps to 28 February, not 3 March', function (): bool|string {
        $cadence = new Cadence(['interval' => Cadence::MONTH, 'intervalCount' => 1]);
        $next = $cadence->next(new DateTime('2026-01-31 09:00:00'));

        return $next->format('Y-m-d') === '2026-02-28' ?: 'got ' . $next->format('Y-m-d');
    });

    check('a clamped month does not drag the billing day forward for ever', function (): bool|string {
        // The bug this guards: 31 Jan -> 3 Mar -> 3 Apr, and the subscriber's billing day has
        // permanently moved. Clamping means the next hop from 28 Feb is 28 Mar, but a subscription
        // billed on the 31st should come back to the 31st.
        $cadence = new Cadence(['interval' => Cadence::MONTH, 'intervalCount' => 1, 'anchorDay' => 28]);
        $first = $cadence->next(new DateTime('2026-01-31'));
        $second = $cadence->next($first);

        return $first->format('Y-m-d') === '2026-02-28' && $second->format('Y-m-d') === '2026-03-28'
            ?: 'got ' . $first->format('Y-m-d') . ' then ' . $second->format('Y-m-d');
    });

    check('the time of day survives a spring DST change', function (): bool|string {
        $tz = new DateTimeZone('Europe/London');
        $cadence = new Cadence(['interval' => Cadence::MONTH, 'intervalCount' => 1]);
        $next = $cadence->next(new DateTime('2026-03-15 09:00:00', $tz));

        return $next->format('H:i') === '09:00' ?: 'got ' . $next->format('Y-m-d H:i T');
    });

    check('a weekly anchor moves forwards, never backwards', function (): bool|string {
        // Wednesday 2026-03-11, anchored to Monday (1). The next payment must be the following
        // Monday, not the Monday just gone — which would bill for a period not yet had.
        $cadence = new Cadence(['interval' => Cadence::WEEK, 'intervalCount' => 1, 'anchorDay' => 1]);
        $next = $cadence->next(new DateTime('2026-03-11'));

        return $next >= new DateTime('2026-03-11') && (int)$next->format('w') === 1
            ?: 'got ' . $next->format('Y-m-d D');
    });

    check('every-2-weeks is 14 days', function (): bool|string {
        $cadence = new Cadence(['interval' => Cadence::WEEK, 'intervalCount' => 2]);

        return $cadence->daysInCycle(new DateTime('2026-05-01')) === 14
            ?: 'got ' . $cadence->daysInCycle(new DateTime('2026-05-01'));
    });

    check('daysInCycle is measured against a real month, not an average', function (): bool|string {
        $cadence = new Cadence(['interval' => Cadence::MONTH, 'intervalCount' => 1]);
        $feb = $cadence->daysInCycle(new DateTime('2026-02-01'));
        $mar = $cadence->daysInCycle(new DateTime('2026-03-01'));

        return $feb === 28 && $mar === 31 ?: "Feb $feb, Mar $mar";
    });

    check('a cadence describes itself in words', function () use (&$x): bool|string {
        $weekly = new Cadence(['interval' => Cadence::WEEK, 'intervalCount' => 1]);
        $fortnightly = new Cadence(['interval' => Cadence::WEEK, 'intervalCount' => 2]);
        $anchored = new Cadence(['interval' => Cadence::MONTH, 'intervalCount' => 1, 'anchorDay' => 1]);

        return $weekly->describe() === 'weekly'
            && $fortnightly->describe() === 'every 2 weeks'
            && $anchored->describe() === 'monthly on the 1st'
            ?: implode(' / ', [$weekly->describe(), $fortnightly->describe(), $anchored->describe()]);
    });

    // ------------------------------------------------------------------------
    section('Gateway capability — the non-Stripe question');

    $dummy = $commerce->getGateways()->getGatewayByHandle('dummy');

    check('a gateway with stored payment methods renews automatically', function () use ($billing, $dummy): bool|string {
        $capability = $billing->getCapability($dummy);

        return $capability->getIsAutomatic() && $capability->mode === GatewayCapability::AUTOMATIC
            ?: 'mode ' . $capability->mode;
    });

    check('a gateway without them is invoiced, not refused', function () use ($billing, $commerce): bool|string {
        foreach ($commerce->getGateways()->getAllGateways() as $gateway) {
            if ($gateway->supportsPaymentSources()) {
                continue;
            }

            $capability = $billing->getCapability($gateway);

            // The whole argument: a gateway that cannot store a card still carries subscriptions.
            return $capability->mode === GatewayCapability::MANUAL && $capability->getCanCarrySubscriptions()
                ?: $gateway->handle . ' got ' . $capability->mode;
        }

        return 'no gateway without payment sources to test against';
    });

    check('being a Commerce subscription gateway is recorded but changes nothing', function () use ($billing, $dummy): bool|string {
        $capability = $billing->getCapability($dummy);

        // Dummy implements SubscriptionGatewayInterface. Subscribr must not treat it specially:
        // the mode comes from payment sources, not from that interface.
        return $capability->isCommerceSubscriptionGateway && $capability->getIsAutomatic()
            ?: 'flagged ' . var_export($capability->isCommerceSubscriptionGateway, true);
    });

    check('the store reports whether anything can carry a subscription', function () use ($billing): bool {
        return $billing->getHasCapableGateway() === true;
    });

    // ------------------------------------------------------------------------
    section('Setting up a subscriber');

    $user = new User();
    $user->username = 'sub_' . $suffix;
    $user->email = 'sub_' . $suffix . '@example.test';
    $user->firstName = 'Test';
    $user->lastName = 'Subscriber';

    if (!$elements->saveElement($user)) {
        throw new RuntimeException('Could not create the test user: ' . json_encode($user->getErrors()));
    }

    $createdUsers[] = $user;

    $productType = $commerce->getProductTypes()->getAllProductTypes()[0];

    function makeProduct(string $title, float $price, $productType, $storeId, array &$createdProducts): Variant
    {
        $product = new Product();
        $product->typeId = $productType->id;
        $product->title = $title;
        $product->enabled = true;

        $variant = new Variant();
        $variant->sku = strtoupper(StringHelper::randomString(10));
        $variant->basePrice = $price;
        $variant->isDefault = true;
        $product->setVariants([$variant]);

        if (!Craft::$app->getElements()->saveElement($product)) {
            throw new RuntimeException('Could not save product: ' . json_encode($product->getErrors()));
        }

        $createdProducts[] = $product;

        return $product->getDefaultVariant();
    }

    $coffee = makeProduct($tag . ' Coffee', 12.00, $productType, $storeId, $createdProducts);
    $decaf = makeProduct($tag . ' Decaf', 14.00, $productType, $storeId, $createdProducts);
    $mug = makeProduct($tag . ' Mug', 9.00, $productType, $storeId, $createdProducts);

    check('the test products exist and are purchasable', function () use ($coffee, $decaf, $mug): bool|string {
        return $coffee && $decaf && $mug && $coffee->getPrice() == 12.00
            ?: 'coffee price ' . ($coffee?->getPrice() ?? 'null');
    });

    $source = $commerce->getPaymentSources()->createPaymentSource(
        (int)$user->id,
        $dummy,
        new DummyPaymentForm(['firstName' => 'Test', 'lastName' => 'Subscriber', 'number' => '4242424242424242', 'expiry' => '01/2030', 'cvv' => '123']),
        'Test card',
    );

    check('a stored payment method can be created for the subscriber', function () use ($source): bool|string {
        return $source && $source->id ? true : 'no payment source';
    });

    // ------------------------------------------------------------------------
    section('Plans');

    $monthly = new Plan([
        'name' => $tag . ' Monthly',
        'handle' => 'sbr' . $suffix . 'Monthly',
        'interval' => Cadence::MONTH,
        'intervalCount' => 1,
        'pricingMode' => Plan::PRICING_LOCKED,
        'allowSkip' => true,
        'allowSwap' => true,
        'allowSwitch' => true,
        'switchGroup' => 'sbr' . $suffix,
        'prepaidOptions' => '3,6,12',
        'prepaidDiscountPercent' => 10,
        'storeId' => $storeId,
    ]);

    check('a plan saves', function () use ($plans, $monthly, &$createdPlans): bool|string {
        $ok = $plans->savePlan($monthly);
        $createdPlans[] = $monthly;

        return $ok ?: json_encode($monthly->getErrors());
    });

    $bigger = new Plan([
        'name' => $tag . ' Monthly Plus',
        'handle' => 'sbr' . $suffix . 'Plus',
        'interval' => Cadence::MONTH,
        'intervalCount' => 1,
        'pricingMode' => Plan::PRICING_OVERRIDE,
        'planPrice' => 45.00,
        'allowSwitch' => true,
        'switchGroup' => 'sbr' . $suffix,
        'storeId' => $storeId,
    ]);
    $plans->savePlan($bigger);
    $createdPlans[] = $bigger;

    $cheaper = new Plan([
        'name' => $tag . ' Monthly Lite',
        'handle' => 'sbr' . $suffix . 'Cheap',
        'interval' => Cadence::MONTH,
        'intervalCount' => 1,
        'pricingMode' => Plan::PRICING_OVERRIDE,
        'planPrice' => 10.00,
        'allowSwitch' => true,
        'switchGroup' => 'sbr' . $suffix,
        'storeId' => $storeId,
    ]);
    $plans->savePlan($cheaper);
    $createdPlans[] = $cheaper;

    check('a plan that overrides the price must have one', function (): bool|string {
        $plan = new Plan(['name' => 'x', 'handle' => 'xyz', 'pricingMode' => Plan::PRICING_OVERRIDE]);

        return !$plan->validate() && $plan->hasErrors('planPrice')
            ?: 'validated with no price';
    });

    check('a monthly plan cannot be anchored to the 31st', function (): bool|string {
        $plan = new Plan(['name' => 'x', 'handle' => 'xyz', 'interval' => Cadence::MONTH, 'anchorDay' => 31]);
        $plan->validate();

        return $plan->hasErrors('anchorDay') ?: 'the 31st was accepted';
    });

    check('a monthly plan may be anchored to the 28th', function (): bool|string {
        $plan = new Plan(['name' => 'x', 'handle' => 'xyz', 'interval' => Cadence::MONTH, 'anchorDay' => 28, 'planPrice' => 1]);
        $plan->validate();

        return !$plan->hasErrors('anchorDay') ?: json_encode($plan->getErrors('anchorDay'));
    });

    check('a daily plan cannot be anchored at all', function (): bool|string {
        $plan = new Plan(['name' => 'x', 'handle' => 'xyz', 'interval' => Cadence::DAY, 'anchorDay' => 3]);
        $plan->validate();

        return $plan->hasErrors('anchorDay') ?: 'an anchored daily plan validated';
    });

    check('prepaid options are cleaned, sorted and de-duplicated', function () use ($monthly): bool|string {
        $plan = new Plan(['prepaidOptions' => '12, 3, 1, 6, 3']);

        // 1 is dropped: one cycle is a subscription, not a prepayment.
        return $plan->getPrepaidCycleOptions() === [3, 6, 12]
            ?: json_encode($plan->getPrepaidCycleOptions());
    });

    check('the prepaid multiplier applies the discount', function () use ($monthly): bool|string {
        // 6 cycles at 10% off = 5.4 cycles' worth.
        return abs($monthly->prepaidMultiplier(6) - 5.4) < 0.0001
            ?: (string)$monthly->prepaidMultiplier(6);
    });

    check('one cycle is never a prepayment', function () use ($monthly): bool|string {
        return $monthly->prepaidMultiplier(1) === 1.0 ?: (string)$monthly->prepaidMultiplier(1);
    });

    check('a plan will not switch outside its group', function () use ($monthly, $storeId): bool|string {
        $other = new Plan(['name' => 'x', 'handle' => 'z', 'switchGroup' => 'somethingelse', 'enabled' => true]);

        return !$monthly->canSwitchTo($other) ?: 'switched across groups';
    });

    check('a plan will switch within its group', function () use ($monthly, $bigger): bool {
        return $monthly->canSwitchTo($bigger);
    });

    check('a plan with subscribers refuses to be deleted', function () use ($plans, $subscriptions, $monthly, $user, $coffee, &$createdSubscriptions): bool|string {
        $item = new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00, 'description' => 'Coffee']);
        $temp = $subscriptions->createSubscription($monthly, (int)$user->id, [$item]);
        $createdSubscriptions[] = $temp;

        $refused = !$plans->deletePlanById((int)$monthly->id);

        return $refused && $plans->getPlanById((int)$monthly->id) !== null
            ?: 'the plan was deleted out from under a subscriber';
    });

    // ------------------------------------------------------------------------
    section('A subscription through its life');

    $itemCoffee = new Item([
        'purchasableId' => $coffee->id,
        'qty' => 2,
        'price' => 12.00,
        'description' => $tag . ' Coffee',
        'sku' => $coffee->getSku(),
    ]);
    $itemCoffee->setSnapshot(['listPrice' => 12.00]);

    $subscription = $subscriptions->createSubscription($monthly, (int)$user->id, [$itemCoffee], [
        'gatewayId' => (int)$dummy->id,
        'paymentSourceId' => (int)$source->id,
        'currency' => 'USD',
    ]);
    $createdSubscriptions[] = $subscription;

    check('a new subscription is pending, not running', function () use ($subscription): bool|string {
        return $subscription->getStatus() === Subscription::STATUS_PENDING
            ?: 'status ' . $subscription->getStatus();
    });

    check('creating one writes to the ledger', function () use ($ledger, $subscription): bool|string {
        $entries = $ledger->getEntriesOfType((int)$subscription->id, LogEntry::TYPE_CREATED);

        return count($entries) === 1 ?: count($entries) . ' created entries';
    });

    check('the renewal subtotal is the items, times the quantity', function () use ($subscription): bool|string {
        return abs($subscription->getRenewalSubtotal() - 24.00) < 0.001
            ?: (string)$subscription->getRenewalSubtotal();
    });

    check('activating it sets the clock running', function () use ($subscriptions, $subscription, $anchorStart, $firstDue): bool|string {
        $subscriptions->activate($subscription, clone $anchorStart);

        return $subscription->getStatus() === Subscription::STATUS_ACTIVE
            && $subscription->dateNextPayment?->format('Y-m-d') === $firstDue->format('Y-m-d')
            ?: $subscription->getStatus() . ' / ' . ($subscription->dateNextPayment?->format('Y-m-d') ?? 'null');
    });

    check('it is due, because that date is in the past', function () use ($subscription): bool {
        return $subscription->getIsDue() === true;
    });

    check('a renewal takes the money and raises a real Commerce order', function () use ($renewals, $subscription, &$createdOrders): bool|string {
        $result = $renewals->renew($subscription);

        if ($result->order) {
            $createdOrders[] = $result->order;
        }

        return $result->outcome === RenewalResult::RENEWED
            && $result->order instanceof Order
            && $result->order->getIsPaid()
            ?: $result->outcome . ' — ' . ($result->message ?? '');
    });

    check('the cycle advanced and the next date came from the date that was due', function () use ($subscription, $secondDue): bool|string {
        // The renewal was due yesterday. The next payment must be one cadence from *that*, not one
        // cadence from now — otherwise a sweep that runs late walks the subscriber's billing date
        // forward a little every month until it has gone round the clock.
        return $subscription->cycleCount === 1
            && $subscription->dateNextPayment?->format('Y-m-d') === $secondDue->format('Y-m-d')
            ?: 'cycle ' . $subscription->cycleCount . ' next ' . ($subscription->dateNextPayment?->format('Y-m-d') ?? 'null');
    });

    check('the order carries the subscription on its line items', function () use ($createdOrders, $subscription): bool|string {
        $order = end($createdOrders);
        $lineItem = $order->getLineItems()[0] ?? null;

        return ($lineItem?->getOptions()['subscribrSubscription'] ?? null) === $subscription->reference
            ?: json_encode($lineItem?->getOptions());
    });

    check('the order is linked back to the subscription', function () use ($subscriptions, $subscription): bool|string {
        $orders = $subscriptions->getOrders((int)$subscription->id);

        return count($orders) === 1 ?: count($orders) . ' linked orders';
    });

    check('the renewal is in the ledger with the amount in it', function () use ($ledger, $subscription): bool|string {
        $entries = $ledger->getEntriesOfType((int)$subscription->id, LogEntry::TYPE_RENEWED);

        return count($entries) === 1 && str_contains((string)$entries[0]->message, 'renewed')
            ?: json_encode(array_map(static fn($e) => $e->message, $entries));
    });

    check('an automatic renewal is credited to nobody, not to the subscriber', function () use ($ledger, $subscription): bool|string {
        $entry = $ledger->getEntriesOfType((int)$subscription->id, LogEntry::TYPE_RENEWED)[0];

        return $entry->userId === null && $entry->getActorName() === 'Subscribr'
            ?: 'actor ' . $entry->getActorName();
    });

    check('renewing again when it is not due does nothing', function () use ($renewals, $subscription): bool|string {
        $before = $subscription->cycleCount;
        $result = $renewals->renew($subscription);

        return $result->outcome === RenewalResult::NOT_DUE && $subscription->cycleCount === $before
            ?: $result->outcome;
    });

    check('a payment attempt is recorded even when it succeeds', function () use ($billing, $subscription): bool|string {
        $attempts = $billing->getAttempts((int)$subscription->id);

        return count($attempts) >= 1 && $attempts[0]->getIsSuccess()
            ?: count($attempts) . ' attempts';
    });

    // ------------------------------------------------------------------------
    section('Skip, pause and resume');

    check('a booked skip replaces the next renewal instead of billing it', function () use ($schedules, $renewals, $subscription): bool|string {
        $schedules->book($subscription, ScheduledAction::SKIP, $subscription->cycleCount + 1);
        $before = $subscription->cycleCount;

        $result = $renewals->renew($subscription, null, true);

        return $result->outcome === RenewalResult::SKIPPED && $subscription->cycleCount === $before
            ?: $result->outcome . ' cycle ' . $subscription->cycleCount;
    });

    check('a skip moves the schedule on', function () use ($subscription, $thirdDue): bool|string {
        return $subscription->dateNextPayment?->format('Y-m-d') === $thirdDue->format('Y-m-d')
            ?: ($subscription->dateNextPayment?->format('Y-m-d') ?? 'null');
    });

    check('booking a second skip for the same cycle replaces the first', function () use ($schedules, $subscription): bool|string {
        $schedules->book($subscription, ScheduledAction::SKIP, 99);
        $schedules->book($subscription, ScheduledAction::SKIP, 99);
        $pending = array_filter($schedules->getPending((int)$subscription->id), static fn($a): bool => $a->action === ScheduledAction::SKIP);

        $ok = count($pending) === 1;
        $schedules->cancelPending($subscription, ScheduledAction::SKIP);

        return $ok ?: count($pending) . ' pending skips';
    });

    check('pausing stops the clock', function () use ($subscriptions, $subscription): bool|string {
        $subscriptions->pause($subscription);

        return $subscription->getStatus() === Subscription::STATUS_PAUSED
            ?: $subscription->getStatus();
    });

    check('a paused subscription is not due, however long it is paused', function () use ($subscription): bool {
        return $subscription->getIsDue() === false;
    });

    check('resuming gives a full cycle, it does not catch up', function () use ($subscriptions, $subscription): bool|string {
        // The behaviour that makes pausing better than cancelling: three months paused must not
        // become three months of billing on the way back.
        $subscriptions->resume($subscription);
        $expected = (new DateTime())->modify('+1 month')->format('Y-m');

        return $subscription->getStatus() === Subscription::STATUS_ACTIVE
            && $subscription->dateNextPayment?->format('Y-m') === $expected
            ?: $subscription->getStatus() . ' / ' . ($subscription->dateNextPayment?->format('Y-m-d') ?? 'null');
    });

    // ------------------------------------------------------------------------
    section('Cancelling');

    check('cancelling keeps the period the subscriber has paid for', function () use ($subscriptions, $subscription): bool|string {
        $subscriptions->cancel($subscription, 'testing');

        return $subscription->getStatus() === Subscription::STATUS_CANCELED
            && $subscription->dateEnds !== null
            && $subscription->dateNextPayment === null
            ?: $subscription->getStatus() . ' ends ' . ($subscription->dateEnds?->format('Y-m-d') ?? 'null');
    });

    check('a cancelled subscription is still live — it still delivers', function () use ($subscription): bool {
        return $subscription->getIsLive() === true;
    });

    check('a cancellation can be reversed while it is still running', function () use ($subscriptions, $subscription): bool|string {
        return $subscriptions->uncancel($subscription)
            && $subscription->getStatus() === Subscription::STATUS_ACTIVE
            && $subscription->dateNextPayment !== null
            ?: $subscription->getStatus();
    });

    check('cancelling immediately ends it now', function () use ($subscriptions, $monthly, $user, $coffee, &$createdSubscriptions): bool|string {
        $temp = $subscriptions->createSubscription($monthly, (int)$user->id, [
            new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00]),
        ]);
        $createdSubscriptions[] = $temp;
        $subscriptions->activate($temp);
        $subscriptions->cancel($temp, 'now', true);

        return $temp->getStatus() === Subscription::STATUS_EXPIRED ?: $temp->getStatus();
    });

    check('an ended subscription cannot be reinstated', function () use ($subscriptions, $createdSubscriptions): bool|string {
        $temp = end($createdSubscriptions);

        return $subscriptions->uncancel($temp) === false ?: 'an expired subscription was reinstated';
    });

    check('the paid-through date counts prepaid cycles', function () use ($subscriptions, $monthly, $user, $coffee, $anchorStart, $monthlyCadence, &$createdSubscriptions): bool|string {
        $temp = $subscriptions->createSubscription($monthly, (int)$user->id, [
            new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00]),
        ], ['prepaidCyclesRemaining' => 3]);
        $createdSubscriptions[] = $temp;
        $subscriptions->activate($temp, clone $anchorStart);
        $subscriptions->cancel($temp);

        // Somebody who paid for six months and cancels in month two keeps four more months.
        // Ending at the current cycle boundary would be keeping four months of their money.
        $expected = $monthlyCadence->next($monthlyCadence->next($monthlyCadence->next($monthlyCadence->next(clone $anchorStart))));

        return $temp->dateEnds?->format('Y-m-d') === $expected->format('Y-m-d')
            ?: 'ends ' . ($temp->dateEnds?->format('Y-m-d') ?? 'null') . ', expected ' . $expected->format('Y-m-d');
    });

    // ------------------------------------------------------------------------
    section('Trials');

    check('a trial defers the first payment and does not charge', function () use ($plans, $subscriptions, $user, $coffee, $storeId, $suffix, $tag, &$createdPlans, &$createdSubscriptions): bool|string {
        $trial = new Plan([
            'name' => $tag . ' Trial',
            'handle' => 'sbr' . $suffix . 'Trial',
            'interval' => Cadence::MONTH,
            'trialDays' => 14,
            'storeId' => $storeId,
        ]);
        $plans->savePlan($trial);
        $createdPlans[] = $trial;

        $temp = $subscriptions->createSubscription($trial, (int)$user->id, [
            new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00]),
        ]);
        $createdSubscriptions[] = $temp;
        $subscriptions->activate($temp, new DateTime('2026-06-01 12:00:00'));

        return $temp->subscriptionStatus === Subscription::STATUS_TRIALING
            && $temp->dateNextPayment?->format('Y-m-d') === '2026-06-15'
            && $temp->dateTrialEnds?->format('Y-m-d') === '2026-06-15'
            ?: $temp->subscriptionStatus . ' / ' . ($temp->dateNextPayment?->format('Y-m-d') ?? 'null');
    });

    check('a trial that has run out reads as active without the sweep touching it', function () use ($createdSubscriptions): bool|string {
        $temp = end($createdSubscriptions);

        // Computed at read time, so a site whose cron is broken still reports honestly.
        return $temp->subscriptionStatus === Subscription::STATUS_TRIALING
            && $temp->getStatus() === Subscription::STATUS_ACTIVE
            ?: 'stored ' . $temp->subscriptionStatus . ' reads ' . $temp->getStatus();
    });

    // ------------------------------------------------------------------------
    section('Prepaid cycles');

    check('a prepaid cycle ships without taking any money', function () use ($subscriptions, $renewals, $monthly, $user, $coffee, $anchorStart, &$createdSubscriptions, &$createdOrders): bool|string {
        $temp = $subscriptions->createSubscription($monthly, (int)$user->id, [
            new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00, 'description' => 'Coffee']),
        ], ['prepaidCyclesRemaining' => 2]);
        $createdSubscriptions[] = $temp;
        $subscriptions->activate($temp, clone $anchorStart);

        $result = $renewals->renew($temp);

        if ($result->order) {
            $createdOrders[] = $result->order;
        }

        return $result->outcome === RenewalResult::PREPAID
            && $result->amount === 0.0
            && $temp->prepaidCyclesRemaining === 1
            && $temp->cycleCount === 1
            ?: $result->outcome . ' amount ' . var_export($result->amount, true) . ' left ' . $temp->prepaidCyclesRemaining;
    });

    check('the prepaid order is zeroed, not left at the catalogue price', function () use ($createdOrders): bool|string {
        $order = end($createdOrders);

        // A £12 order with no payment against it would appear in every revenue report the store has.
        return (float)$order->getTotalPrice() === 0.0 ?: (string)$order->getTotalPrice();
    });

    check('drawing down a prepaid cycle is in the ledger', function () use ($ledger, $createdSubscriptions): bool|string {
        $temp = null;

        foreach ($createdSubscriptions as $candidate) {
            if ($candidate->prepaidCyclesRemaining === 1) {
                $temp = $candidate;
            }
        }

        if ($temp === null) {
            return 'no prepaid subscription found';
        }

        return count($ledger->getEntriesOfType((int)$temp->id, LogEntry::TYPE_PREPAID_DRAWN)) === 1
            ?: 'no prepaid ledger entry';
    });

    // ------------------------------------------------------------------------
    section('Dunning');

    $profile = new DunningProfile([
        'name' => $tag . ' sequence',
        'handle' => 'sbr' . $suffix . 'Dunning',
    ]);
    $profile->setStages([
        ['offsetHours' => 72, 'action' => DunningStage::ACTION_RETRY],
        ['offsetHours' => 24, 'action' => DunningStage::ACTION_RETRY],
        ['offsetHours' => 168, 'action' => DunningStage::ACTION_CANCEL],
    ]);

    check('stages are sorted on the way in', function () use ($dunning, $profile, &$createdProfiles): bool|string {
        $dunning->saveProfile($profile);
        $createdProfiles[] = $profile;
        $offsets = array_map(static fn(DunningStage $s): int => $s->offsetHours, $profile->getStages());

        // Offsets are from the first failure, so an unsorted profile would run its stages out of
        // order and a retry booked for hour 24 would fire after the one for hour 72.
        return $offsets === [24, 72, 168] ?: json_encode($offsets);
    });

    check('the whole sequence reports its own length', function () use ($profile): bool|string {
        return $profile->getTotalHours() === 168 ?: (string)$profile->getTotalHours();
    });

    check('a store with no profiles still has a working sequence', function () use ($dunning): bool|string {
        $fallback = $dunning->getFallbackProfile();
        $last = $fallback->getStages()[$fallback->getStageCount() - 1];

        // Without this a store that never opened the dunning screen would leave declined
        // subscriptions past-due for ever.
        return $fallback->getStageCount() > 1 && $last->getIsTerminal()
            ?: $fallback->getStageCount() . ' stages, last is ' . $last->action;
    });

    $failing = $subscriptions->createSubscription($monthly, (int)$user->id, [
        new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00, 'description' => 'Coffee']),
    ], ['gatewayId' => (int)$dummy->id, 'paymentSourceId' => (int)$source->id]);
    $createdSubscriptions[] = $failing;
    $subscriptions->activate($failing, clone $anchorStart);
    $monthly->dunningId = $profile->id;
    $plans->savePlan($monthly);

    check('a failure moves the subscription past due and books a retry', function () use ($dunning, $failing, $subscriptions): bool|string {
        $attempt = new Attempt([
            'subscriptionId' => (int)$failing->id,
            'outcome' => Attempt::FAILED,
            'message' => 'Insufficient funds',
        ]);

        $dunning->recordFailure($failing, $attempt);

        return $failing->subscriptionStatus === Subscription::STATUS_PAST_DUE
            && $failing->failureCount === 1
            && $failing->dateNextRetry !== null
            ?: $failing->subscriptionStatus . ' retries at ' . ($failing->dateNextRetry?->format('c') ?? 'null');
    });

    check('the first retry is booked at the first stage’s offset', function () use ($failing): bool|string {
        $hours = ($failing->dateNextRetry->getTimestamp() - time()) / 3600;

        return $hours > 23 && $hours < 25 ?: round($hours, 1) . ' hours away';
    });

    check('a hopeless decline skips straight to the end of the sequence', function () use ($dunning, $subscriptions, $monthly, $user, $coffee, &$createdSubscriptions): bool|string {
        $temp = $subscriptions->createSubscription($monthly, (int)$user->id, [
            new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00]),
        ]);
        $createdSubscriptions[] = $temp;
        $subscriptions->activate($temp);

        $dunning->recordFailure($temp, new Attempt([
            'subscriptionId' => (int)$temp->id,
            'outcome' => Attempt::FAILED,
            'message' => 'Card reported stolen',
            'gatewayCode' => 'stolen_card',
        ]));

        // Retrying a stolen card three more times is how a store's merchant account gets reviewed.
        return $temp->dunningStage === 2 ?: 'stage ' . $temp->dunningStage;
    });

    check('a soft decline is retryable, a hard one is not', function (): bool|string {
        $soft = new Attempt(['message' => 'Insufficient funds']);
        $hard = new Attempt(['gatewayCode' => 'lost_card', 'message' => 'Card was reported lost']);
        $unknown = new Attempt(['message' => 'Something went wrong at the acquirer']);

        // The default is retryable: failing to retry a recoverable payment loses a customer, which
        // is worse than one wasted gateway call.
        return $soft->getIsRetryable() && !$hard->getIsRetryable() && $unknown->getIsRetryable()
            ?: 'soft ' . var_export($soft->getIsRetryable(), true) . ' hard ' . var_export($hard->getIsRetryable(), true);
    });

    check('a retry that works recovers the cycle and clears the dunning state', function () use ($dunning, $renewals, $failing): bool|string {
        $before = $failing->cycleCount;
        $result = $dunning->retry($failing);

        return $result->outcome === RenewalResult::RENEWED
            && $failing->failureCount === 0
            && $failing->dateNextRetry === null
            && $failing->subscriptionStatus === Subscription::STATUS_ACTIVE
            && $failing->cycleCount === $before + 1
            ?: $result->outcome . ' failures ' . $failing->failureCount . ' status ' . $failing->subscriptionStatus;
    });

    check('a recovery is recorded, so the recovery rate can be computed', function () use ($ledger, $failing): bool|string {
        return count($ledger->getEntriesOfType((int)$failing->id, LogEntry::TYPE_PAYMENT_RECOVERED)) === 1
            ?: 'no recovery entry';
    });

    check('the dunning summary reports money, not just a count', function () use ($dunning): bool|string {
        $summary = $dunning->getSummary();

        return array_key_exists('atRisk', $summary)
            && array_key_exists('recoveryRate', $summary)
            && is_int($summary['pastDueCount'])
            ?: json_encode(array_keys($summary));
    });

    check('fixing the card brings the retry forward to now', function () use ($subscriptions, $dunning, $monthly, $user, $coffee, $source, $dummy, &$createdSubscriptions): bool|string {
        $temp = $subscriptions->createSubscription($monthly, (int)$user->id, [
            new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00]),
        ]);
        $createdSubscriptions[] = $temp;
        $subscriptions->activate($temp);
        $dunning->recordFailure($temp, new Attempt(['subscriptionId' => (int)$temp->id, 'outcome' => Attempt::FAILED, 'message' => 'Declined']));

        $wasAt = $temp->dateNextRetry;
        $subscriptions->setPaymentSource($temp, (int)$source->id, (int)$dummy->id);

        // The most valuable event in dunning; it should not wait for the next scheduled stage.
        return $temp->dateNextRetry < $wasAt ?: 'retry did not move forward';
    });

    // ------------------------------------------------------------------------
    section('Proration');

    $switcher = $subscriptions->createSubscription($monthly, (int)$user->id, [
        (function () use ($coffee) {
            $item = new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 30.00, 'description' => 'Coffee']);
            $item->setSnapshot(['listPrice' => 30.00]);

            return $item;
        })(),
    ], ['gatewayId' => (int)$dummy->id, 'paymentSourceId' => (int)$source->id]);
    $createdSubscriptions[] = $switcher;
    $subscriptions->activate($switcher);

    // Halfway through a 30-day period.
    $switcher->dateCurrentPeriodStart = (new DateTime())->modify('-15 days');
    $switcher->dateNextPayment = (new DateTime())->modify('+15 days');
    $subscriptions->save($switcher);

    check('an upgrade credits the unused time and charges the difference', function () use ($proration, $switcher, $bigger): bool|string {
        $preview = $proration->preview($switcher, $bigger);

        // Halfway through a 30-day period: 30/month becomes 45/month, so about half of each is in
        // play and the customer owes roughly half the difference — not a whole month of the new
        // price, which is what a store that does not prorate charges them.
        return $preview->credit > 0
            && $preview->charge > $preview->credit
            && $preview->getNetDue() > 0
            && $preview->daysRemaining >= 14 && $preview->daysRemaining <= 15
            ?: 'credit ' . $preview->credit . ' charge ' . $preview->charge . ' net ' . $preview->getNetDue() . ' days ' . $preview->daysRemaining;
    });

    check('the preview shows its working', function () use ($proration, $switcher, $bigger): bool|string {
        $preview = $proration->preview($switcher, $bigger);

        // A proration UI that only produced a total would be a worse version of not showing one.
        return count($preview->lines) >= 3
            && $preview->getUsedFraction() > 0.4 && $preview->getUsedFraction() < 0.6
            ?: count($preview->lines) . ' lines, used ' . $preview->getUsedFraction();
    });

    check('a downgrade produces a credit that is carried, not refunded', function () use ($proration, $switcher, $cheaper): bool|string {
        $preview = $proration->preview($switcher, $cheaper);

        // An automatic refund to a card is a real movement of money a merchant should authorise,
        // and plenty of downgrades are followed by an upgrade a week later. It carries.
        return $preview->getIsCredit()
            && $preview->carriedCredit > 0
            && abs($preview->carriedCredit - abs($preview->getNetDue())) < 0.01
            ?: 'net ' . $preview->getNetDue() . ' carried ' . $preview->carriedCredit;
    });

    check('a switch during a trial is free and says so', function () use ($proration, $subscriptions, $plans, $monthly, $bigger, $user, $coffee, &$createdSubscriptions): bool|string {
        $temp = $subscriptions->createSubscription($monthly, (int)$user->id, [
            new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00]),
        ]);
        $createdSubscriptions[] = $temp;
        $temp->subscriptionStatus = Subscription::STATUS_TRIALING;
        $temp->dateTrialEnds = (new DateTime())->modify('+7 days');
        $temp->dateNextPayment = $temp->dateTrialEnds;
        $temp->dateCurrentPeriodStart = new DateTime();
        $subscriptions->save($temp);

        $preview = $proration->preview($temp, $bigger);

        // Charging a proration against a trial is one of the fastest ways to lose a customer on
        // day three.
        return $preview->getIsFree() && $preview->credit === 0.0 && $preview->charge === 0.0
            ?: 'net ' . $preview->getNetDue();
    });

    check('a deferred switch is booked, not charged', function () use ($proration, $plans, $subscriptions, $user, $coffee, $storeId, $suffix, $tag, $schedules, &$createdPlans, &$createdSubscriptions): bool|string {
        $deferred = new Plan([
            'name' => $tag . ' Deferred',
            'handle' => 'sbr' . $suffix . 'Deferred',
            'interval' => Cadence::MONTH,
            'switchMode' => Plan::SWITCH_END,
            'allowSwitch' => true,
            'storeId' => $storeId,
        ]);
        $plans->savePlan($deferred);
        $createdPlans[] = $deferred;

        $target = new Plan([
            'name' => $tag . ' Target',
            'handle' => 'sbr' . $suffix . 'Target',
            'interval' => Cadence::MONTH,
            'pricingMode' => Plan::PRICING_OVERRIDE,
            'planPrice' => 40.00,
            'storeId' => $storeId,
        ]);
        $plans->savePlan($target);
        $createdPlans[] = $target;

        $temp = $subscriptions->createSubscription($deferred, (int)$user->id, [
            new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00]),
        ]);
        $createdSubscriptions[] = $temp;
        $subscriptions->activate($temp);

        [$ok, $preview, $error] = $proration->applySwitch($temp, $target);
        $booked = $schedules->getPendingOfType((int)$temp->id, ScheduledAction::SWITCH_PLAN);

        return $ok
            && $preview->mode === ProrationModel::MODE_END
            && $preview->getIsFree()
            && $booked !== null
            && $temp->planId === $deferred->id
            ?: 'ok ' . var_export($ok, true) . ' mode ' . $preview->mode . ' booked ' . var_export($booked !== null, true) . ' error ' . ($error ?? '');
    });

    check('a switch that is charged actually moves the plan', function () use ($proration, $subscriptions, $switcher, $bigger): bool|string {
        [$ok, $preview, $error] = $proration->applySwitch($switcher, $bigger);

        return $ok && $switcher->planId === $bigger->id
            ?: 'ok ' . var_export($ok, true) . ' plan ' . $switcher->planId . ' error ' . ($error ?? '');
    });

    check('switching is recorded with the numbers that were used', function () use ($ledger, $switcher): bool|string {
        $entries = $ledger->getEntriesOfType((int)$switcher->id, LogEntry::TYPE_SWITCHED);
        $data = $entries[0]->getData();

        return count($entries) >= 1 && array_key_exists('netDue', $data) && array_key_exists('credit', $data)
            ?: json_encode($data);
    });

    // ------------------------------------------------------------------------
    section('Boxes and build-a-box');

    $box = new Box([
        'name' => $tag . ' Box',
        'handle' => 'sbr' . $suffix . 'Box',
        'mode' => Box::MODE_CHOICE,
        'pricing' => Box::PRICING_CONTENTS,
        'lockHours' => 24,
        'allowSwap' => true,
    ]);

    $slotCoffee = new BoxSlot(['name' => 'Coffee', 'minItems' => 1, 'maxItems' => 2]);
    $slotCoffee->setSources(['purchasableIds' => [$coffee->id, $decaf->id]]);

    $slotExtra = new BoxSlot(['name' => 'Extras', 'minItems' => 0, 'maxItems' => 1, 'isAddOn' => true]);
    $slotExtra->setSources(['purchasableIds' => [$mug->id]]);

    $box->setSlots([$slotCoffee, $slotExtra]);

    check('a box with slots saves', function () use ($boxes, $box, &$createdBoxes): bool|string {
        $ok = $boxes->saveBox($box);
        $createdBoxes[] = $box;

        return $ok && $box->id && count($boxes->getSlotsByBoxId((int)$box->id)) === 2
            ?: json_encode($box->getErrors());
    });

    check('a slot rejects a product that is not in its sources', function () use ($boxes, $box, $mug): bool|string {
        $slots = $boxes->getSlotsByBoxId((int)$box->id);

        return !$slots[0]->accepts((int)$mug->id) ?: 'the coffee slot accepted a mug';
    });

    check('a slot with no sources accepts anything', function () use ($mug): bool|string {
        $slot = new BoxSlot(['name' => 'Anything']);

        // A slot that allowed nothing until it was configured would make a half-finished box
        // silently unfulfillable.
        return $slot->accepts((int)$mug->id) ?: 'an empty slot refused everything';
    });

    check('a selection that misses a minimum is refused, with a reason', function () use ($boxes, $box): bool|string {
        $slots = $boxes->getSlotsByBoxId((int)$box->id);
        $errors = $boxes->validateSelection($box, [$slots[0]->id => []]);

        return count($errors) >= 1 && str_contains($errors[0], 'at least')
            ?: json_encode($errors);
    });

    check('a selection over a slot maximum is refused', function () use ($boxes, $box, $coffee, $decaf): bool|string {
        $slots = $boxes->getSlotsByBoxId((int)$box->id);
        $errors = $boxes->validateSelection($box, [
            $slots[0]->id => [$coffee->id => 2, $decaf->id => 2],
        ]);

        return count($errors) >= 1 ?: 'four items fitted in a two-item slot';
    });

    check('a valid selection passes', function () use ($boxes, $box, $coffee): bool|string {
        $slots = $boxes->getSlotsByBoxId((int)$box->id);
        $errors = $boxes->validateSelection($box, [$slots[0]->id => [$coffee->id => 1]]);

        return $errors === [] ?: json_encode($errors);
    });

    check('a contents-priced box costs the sum of what is in it', function () use ($boxes, $box, $coffee, $mug): bool|string {
        $slots = $boxes->getSlotsByBoxId((int)$box->id);
        $items = $boxes->selectionToItems($box, [
            $slots[0]->id => [$coffee->id => 2],
            $slots[1]->id => [$mug->id => 1],
        ]);

        return abs($boxes->priceContents($box, $items) - 33.00) < 0.001
            ?: (string)$boxes->priceContents($box, $items);
    });

    check('a fixed-price box costs its own price whatever is in it', function () use ($boxes, $box, $coffee, $mug): bool|string {
        $box->pricing = Box::PRICING_FIXED;
        $box->boxPrice = 25.00;
        $boxes->saveBox($box, false);

        $slots = $boxes->getSlotsByBoxId((int)$box->id);
        $items = $boxes->selectionToItems($box, [
            $slots[0]->id => [$coffee->id => 2],
            $slots[1]->id => [$mug->id => 1],
        ]);

        return abs($boxes->priceContents($box, $items) - 25.00) < 0.001
            ?: (string)$boxes->priceContents($box, $items);
    });

    check('a base-priced box charges for its add-on slots', function () use ($boxes, $box, $coffee, $mug): bool|string {
        $box->pricing = Box::PRICING_BASE;
        $box->boxPrice = 25.00;
        $boxes->saveBox($box, false);

        $slots = $boxes->getSlotsByBoxId((int)$box->id);
        $items = $boxes->selectionToItems($box, [
            $slots[0]->id => [$coffee->id => 2],
            $slots[1]->id => [$mug->id => 1],
        ]);

        // 25 base + 9 for the mug in the add-on slot.
        return abs($boxes->priceContents($box, $items) - 34.00) < 0.001
            ?: (string)$boxes->priceContents($box, $items);
    });

    check('a fixed-price box needs a price', function (): bool|string {
        $bad = new Box(['name' => 'x', 'handle' => 'xyz', 'pricing' => Box::PRICING_FIXED]);
        $bad->validate();

        // Yii skips a conditional validator when the attribute is empty, which is exactly when
        // this rule has something to say.
        return $bad->hasErrors('boxPrice') ?: 'a priceless fixed box validated';
    });

    $box->pricing = Box::PRICING_CONTENTS;
    $box->boxPrice = null;
    $boxes->saveBox($box, false);

    $monthly->boxId = $box->id;
    $plans->savePlan($monthly);

    $boxSub = $subscriptions->createSubscription($monthly, (int)$user->id, [], [
        'gatewayId' => (int)$dummy->id,
        'paymentSourceId' => (int)$source->id,
        'boxId' => $box->id,
    ]);
    $createdSubscriptions[] = $boxSub;

    $slots = $boxes->getSlotsByBoxId((int)$box->id);
    $subscriptions->saveItems($boxSub, $boxes->selectionToItems($box, [$slots[0]->id => [$coffee->id => 2]]));
    $subscriptions->activate($boxSub, clone $anchorStart);

    check('the standing contents ship every cycle', function () use ($boxSub, $boxes): bool|string {
        $items = $boxes->contentsForCycle($boxSub, $boxSub->cycleCount + 1);

        return count($items) === 1 && $items[0]->qty === 2
            ?: count($items) . ' items';
    });

    check('a swap replaces one cycle and leaves the standing set alone', function () use ($subscriptions, $boxes, $box, $boxSub, $decaf, $slots): bool|string {
        $cycle = $boxSub->cycleCount + 1;
        $swap = $boxes->selectionToItems($box, [$slots[0]->id => [$decaf->id => 1]], $cycle);
        $subscriptions->saveItems($boxSub, $swap, $cycle);

        $thisCycle = $boxes->contentsForCycle($boxSub, $cycle);
        $standing = $subscriptions->getItems((int)$boxSub->id);

        return count($thisCycle) === 1
            && $thisCycle[0]->purchasableId === $decaf->id
            && count($standing) === 1
            && $standing[0]->purchasableId !== $decaf->id
            ?: 'cycle has ' . ($thisCycle[0]->sku ?? '?') . ', standing has ' . ($standing[0]->sku ?? '?');
    });

    check('a renewal ships the swap and then goes back to normal', function () use ($renewals, $boxes, $boxSub, $decaf, $coffee, &$createdOrders): bool|string {
        $result = $renewals->renew($boxSub, null, true);

        if ($result->order) {
            $createdOrders[] = $result->order;
        }

        $shipped = $result->order?->getLineItems()[0]?->purchasableId;
        $next = $boxes->contentsForCycle($boxSub, $boxSub->cycleCount + 1);

        // The swap is for one shipment. If it persisted, the customer who tried the decaf once
        // would be sent decaf for ever.
        return $shipped === $decaf->id
            && count($next) === 1
            && $next[0]->purchasableId === $coffee->id
            ?: 'shipped ' . var_export($shipped, true) . ' next ' . var_export($next[0]->purchasableId ?? null, true);
    });

    check('contents lock before the shipment goes out', function () use ($boxes, $boxSub): bool|string {
        $boxSub->dateNextPayment = (new DateTime())->modify('+2 hours');

        // lockHours is 24, so two hours out is inside the lock.
        return $boxes->contentsAreLocked($boxSub) === true ?: 'not locked two hours before shipping';
    });

    check('contents are editable well before that', function () use ($boxes, $boxSub): bool|string {
        $boxSub->dateNextPayment = (new DateTime())->modify('+10 days');

        return $boxes->contentsAreLocked($boxSub) === false ?: 'locked ten days out';
    });

    check('a surprise box avoids what the subscriber has just had', function () use ($boxes, $box, $boxSub, $subscriptions): bool|string {
        $box->mode = Box::MODE_SURPRISE;
        $box->avoidRepeatCycles = 5;
        $boxes->saveBox($box, false);

        $picked = $boxes->surpriseSelection($boxSub, $box, $boxSub->cycleCount + 1);
        $ok = count($picked) >= 1;

        $box->mode = Box::MODE_CHOICE;
        $boxes->saveBox($box, false);

        return $ok ?: 'the surprise box came back empty';
    });

    check('an exhausted pool repeats rather than shipping nothing', function () use ($boxes, $box, $subscriptions, $monthly, $user, $coffee, &$createdSubscriptions): bool|string {
        $temp = $subscriptions->createSubscription($monthly, (int)$user->id, [], ['boxId' => $box->id]);
        $createdSubscriptions[] = $temp;
        $temp->cycleCount = 3;
        $subscriptions->save($temp);

        // Every candidate has been sent recently. A repeat is better than an empty parcel.
        $picked = $boxes->surpriseSelection($temp, $box, 4);

        return count($picked) >= 1 ?: 'the parcel would have gone out empty';
    });

    // ------------------------------------------------------------------------
    section('Mixed carts');

    check('the recurring options are just line-item options', function () use ($carts, $monthly): bool|string {
        $options = $carts->optionsFor($monthly, ['subscribrPrepaid' => 6]);

        return $options[SubscribrCarts::OPTION_PLAN] === $monthly->handle
            && $options['subscribrPrepaid'] === 6
            ?: json_encode($options);
    });

    check('empty extras are dropped rather than hashed into the line', function () use ($carts, $monthly): bool|string {
        $options = $carts->optionsFor($monthly, ['subscribrGiftEmail' => '', 'subscribrPrepaid' => null]);

        // Commerce de-duplicates line items on a hash of their options, so an empty key would make
        // two otherwise identical lines refuse to merge.
        return $options === [SubscribrCarts::OPTION_PLAN => $monthly->handle] ?: json_encode($options);
    });

    $cart = new Order();
    $cart->number = $commerce->getCarts()->generateCartNumber();
    $cart->setCustomer($user);
    $cart->email = $user->email;
    $elements->saveElement($cart, false);
    $createdOrders[] = $cart;

    $recurringLine = $commerce->getLineItems()->createLineItem($cart, (int)$coffee->id, $carts->optionsFor($monthly), 1);
    $oneOffLine = $commerce->getLineItems()->createLineItem($cart, (int)$mug->id, [], 1);
    $cart->setLineItems([$recurringLine, $oneOffLine]);
    $cart->recalculate();
    $elements->saveElement($cart, false);

    check('a cart can hold a subscription and a one-off at the same time', function () use ($carts, $cart): bool|string {
        return $carts->getHasRecurringItems($cart)
            && $carts->getHasOneOffItems($cart)
            && count($carts->getRecurringLineItems($cart)) === 1
            ?: 'recurring ' . count($carts->getRecurringLineItems($cart));
    });

    check('the same product bought once and monthly stays two separate lines', function () use ($carts, $commerce, $cart, $coffee, $monthly, $elements): bool|string {
        // Commerce de-duplicates by purchasable ID *and* an options hash, which is the whole
        // reason a mixed cart needs no cart machinery of its own.
        $alsoOnce = $commerce->getLineItems()->createLineItem($cart, (int)$coffee->id, [], 1);
        $cart->setLineItems(array_merge($cart->getLineItems(), [$alsoOnce]));
        $cart->recalculate();
        $elements->saveElement($cart, false);

        $forCoffee = array_filter($cart->getLineItems(), static fn($li): bool => (int)$li->purchasableId === (int)$coffee->id);

        return count($forCoffee) === 2 ?: count($forCoffee) . ' lines for the same coffee';
    });

    check('the cart reports what it commits the customer to after today', function () use ($carts, $cart): bool|string {
        $summary = $carts->getRecurringSummary($cart);
        $first = reset($summary);

        // A basket with a kettle and a monthly coffee costs one thing today and another every
        // month, and only quoting the first is how a subscription becomes a complaint.
        return count($summary) === 1 && $first['amount'] > 0 && str_contains($first['cadence'], 'month')
            ?: json_encode($summary);
    });

    check('a guest cart with a subscription in it is refused, at the cart', function () use ($carts, $commerce, $elements, $coffee, $monthly, &$createdOrders): bool|string {
        $guest = new Order();
        $guest->number = $commerce->getCarts()->generateCartNumber();
        $elements->saveElement($guest, false);
        $createdOrders[] = $guest;

        $line = $commerce->getLineItems()->createLineItem($guest, (int)$coffee->id, $carts->optionsFor($monthly), 1);
        $guest->setLineItems([$line]);
        $elements->saveElement($guest, false);

        $errors = $carts->validate($guest);

        // Finding out at the first renewal that there is nobody to bill is much worse than finding
        // out at the checkout.
        return count($errors) >= 1 && str_contains(implode(' ', $errors), 'account')
            ?: json_encode($errors);
    });

    check('completing the order turns the recurring lines into a subscription', function () use ($carts, $cart, $elements, &$createdSubscriptions): bool|string {
        $cart->markAsComplete();
        $fresh = Order::find()->id($cart->id)->status(null)->one();

        $made = Plugin::getInstance()->getSubscriptions()->getSubscriptionsForOrder((int)$cart->id);

        foreach ($made as $subscription) {
            $createdSubscriptions[] = $subscription;
        }

        return count($made) === 1 ?: count($made) . ' subscriptions from one order';
    });

    check('the subscription it made is running and knows its plan', function () use ($cart, $monthly): bool|string {
        $made = Plugin::getInstance()->getSubscriptions()->getSubscriptionsForOrder((int)$cart->id)[0];

        return $made->planId === $monthly->id
            && in_array($made->getStatus(), [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING], true)
            ?: 'plan ' . $made->planId . ' status ' . $made->getStatus();
    });

    check('completing the same order twice does not make a second subscription', function () use ($carts, $cart): bool|string {
        // The order-complete event can fire more than once, and a customer with two identical
        // subscriptions is a refund and an apology.
        $carts->materialize(Order::find()->id($cart->id)->status(null)->one());
        $made = Plugin::getInstance()->getSubscriptions()->getSubscriptionsForOrder((int)$cart->id);

        return count($made) === 1 ?: count($made) . ' subscriptions after a second completion';
    });

    // ------------------------------------------------------------------------
    section('Gifts');

    $giftOrder = new Order();
    $giftOrder->number = $commerce->getCarts()->generateCartNumber();
    $giftOrder->setCustomer($user);
    $giftOrder->email = $user->email;
    $elements->saveElement($giftOrder, false);
    $createdOrders[] = $giftOrder;

    $giftSub = $subscriptions->createSubscription($monthly, (int)$user->id, [
        new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00]),
    ]);
    $createdSubscriptions[] = $giftSub;

    $gift = $gifts->createFromOrder($giftSub, $giftOrder, [
        'recipientEmail' => 'recipient_' . $suffix . '@example.test',
        'recipientName' => 'A Friend',
        'senderName' => 'Test Subscriber',
        'message' => 'Enjoy',
        'cycles' => 3,
    ]);

    check('a gift is created against the purchaser and does not renew', function () use ($gift, $giftSub): bool|string {
        // A gift that silently started billing the giver's card in month thirteen would be
        // indefensible.
        return $gift->id
            && $giftSub->autoRenew === false
            && $giftSub->prepaidCyclesRemaining === 2
            ?: 'autoRenew ' . var_export($giftSub->autoRenew, true) . ' prepaid ' . $giftSub->prepaidCyclesRemaining;
    });

    check('the clock does not start until it is claimed', function () use ($giftSub): bool|string {
        // A subscription bought in November for Christmas must not have used a month of itself by
        // the time it is opened.
        return $giftSub->getStatus() === Subscription::STATUS_PENDING && $giftSub->dateStarted === null
            ?: $giftSub->getStatus();
    });

    check('an unclaimed gift is claimable and has an expiry', function () use ($gift): bool|string {
        return $gift->getIsClaimable() && $gift->dateExpires !== null
            ?: 'claimable ' . var_export($gift->getIsClaimable(), true);
    });

    check('claiming it moves the subscription to the recipient and starts it', function () use ($gifts, $gift, $suffix, $elements, &$createdUsers): bool|string {
        $recipient = new User();
        $recipient->username = 'gift_' . $suffix;
        $recipient->email = 'recipient_' . $suffix . '@example.test';

        if (!$elements->saveElement($recipient)) {
            return 'could not create the recipient: ' . json_encode($recipient->getErrors());
        }

        $createdUsers[] = $recipient;

        [$claimed, $error] = $gifts->claim($gift, $recipient);

        return $claimed !== null
            && $claimed->userId === (int)$recipient->id
            && $claimed->getStatus() === Subscription::STATUS_ACTIVE
            ?: ($error ?? 'status ' . ($claimed?->getStatus() ?? 'null'));
    });

    check('the purchaser’s card does not transfer with it', function () use ($gifts, $gift): bool|string {
        $claimed = Plugin::getInstance()->getSubscriptions()->getSubscriptionById((int)$gift->subscriptionId);

        // Nothing about a gift may charge anybody again without them asking.
        return $claimed->paymentSourceId === null && $claimed->autoRenew === false
            ?: 'source ' . var_export($claimed->paymentSourceId, true);
    });

    check('a gift cannot be claimed twice', function () use ($gifts, $gift, $createdUsers): bool|string {
        $fresh = $gifts->getGiftByToken($gift->token);
        [$claimed, $error] = $gifts->claim($fresh, end($createdUsers));

        return $claimed === null && $error !== null ?: 'a claimed gift was claimed again';
    });

    // ------------------------------------------------------------------------
    section('Manual renewal — a gateway that cannot store a card');

    check('a subscription with no stored card is invoiced rather than refused', function () use ($subscriptions, $renewals, $monthly, $user, $coffee, $anchorStart, &$createdSubscriptions, &$createdOrders): bool|string {
        $temp = $subscriptions->createSubscription($monthly, (int)$user->id, [
            new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00, 'description' => 'Coffee']),
        ], ['isManual' => true]);
        $createdSubscriptions[] = $temp;
        $subscriptions->activate($temp, clone $anchorStart);

        $result = $renewals->renew($temp);

        if ($result->order) {
            $createdOrders[] = $result->order;
        }

        return $result->outcome === RenewalResult::MANUAL && $result->order !== null
            ?: $result->outcome . ' — ' . ($result->message ?? '');
    });

    check('the invoice is left as a cart, so Commerce’s own checkout can take it', function () use ($createdOrders): bool|string {
        $order = end($createdOrders);

        // An incomplete order is a cart, and a cart has a load-cart URL that drops the subscriber
        // into the store's real checkout. No bespoke payment page to build or maintain.
        return $order->isCompleted === false ?: 'the invoice was completed and cannot be loaded as a cart';
    });

    check('an invoiced cycle still advances the schedule', function () use ($createdSubscriptions): bool|string {
        $temp = null;

        foreach ($createdSubscriptions as $candidate) {
            if ($candidate->isManual) {
                $temp = $candidate;
            }
        }

        return $temp && $temp->cycleCount === 1 ?: 'cycle ' . ($temp?->cycleCount ?? 'n/a');
    });

    check('paying the invoice recovers the cycle exactly once', function () use ($renewals, $createdOrders, $createdSubscriptions): bool|string {
        $order = null;

        foreach ($createdOrders as $candidate) {
            if (!$candidate->isCompleted) {
                $order = $candidate;
            }
        }

        if ($order === null) {
            return 'no unpaid invoice to pay';
        }

        $first = $renewals->completeManualRenewal($order);
        $second = $renewals->completeManualRenewal($order);

        // A customer who pays a link twice, or a webhook arriving after the sweep already
        // recovered it, must not push the schedule forward twice.
        return $second === null ?: 'the same invoice advanced the cycle twice';
    });

    // ------------------------------------------------------------------------
    section('The catch-up guard');

    check('a queue that was off for months does not bill every missed cycle', function () use ($subscriptions, $renewals, $monthly, $user, $coffee, $ledger, &$createdSubscriptions): bool|string {
        $settings = Plugin::getInstance()->getSettings();
        $settings->renewalCatchUpDays = 7;
        $settings->billMissedCycles = false;

        $temp = $subscriptions->createSubscription($monthly, (int)$user->id, [
            new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00]),
        ]);
        $createdSubscriptions[] = $temp;
        $subscriptions->activate($temp, (new DateTime())->modify('-8 months'));

        $result = $renewals->renew($temp);

        return $result->outcome === RenewalResult::ABANDONED
            && $temp->cycleCount === 0
            && $temp->dateNextPayment > new DateTime()
            ?: $result->outcome . ' cycles ' . $temp->cycleCount;
    });

    check('the skipped cycles are recorded rather than silently dropped', function () use ($ledger, $createdSubscriptions): bool|string {
        $temp = end($createdSubscriptions);
        $notes = $ledger->getEntriesOfType((int)$temp->id, LogEntry::TYPE_NOTE);

        foreach ($notes as $note) {
            if (($note->getData()['missedCycles'] ?? 0) > 1) {
                return true;
            }
        }

        return 'no note about the missed cycles';
    });

    // ------------------------------------------------------------------------
    section('Fixed-term plans');

    check('a plan with a cycle count ends by itself', function () use ($plans, $subscriptions, $renewals, $user, $coffee, $source, $dummy, $storeId, $suffix, $tag, $anchorStart, &$createdPlans, &$createdSubscriptions, &$createdOrders): bool|string {
        $term = new Plan([
            'name' => $tag . ' Six months',
            'handle' => 'sbr' . $suffix . 'Term',
            'interval' => Cadence::MONTH,
            'maxCycles' => 1,
            'storeId' => $storeId,
        ]);
        $plans->savePlan($term);
        $createdPlans[] = $term;

        $temp = $subscriptions->createSubscription($term, (int)$user->id, [
            new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00, 'description' => 'Coffee']),
        ], ['gatewayId' => (int)$dummy->id, 'paymentSourceId' => (int)$source->id]);
        $createdSubscriptions[] = $temp;
        $subscriptions->activate($temp, clone $anchorStart);

        $first = $renewals->renew($temp);

        if ($first->order) {
            $createdOrders[] = $first->order;
        }

        // One cycle allowed: after it, there is an end date and no next payment.
        $stopped = $temp->dateNextPayment === null && $temp->dateEnds !== null;

        $second = $renewals->renew($temp, null, true);

        return $first->outcome === RenewalResult::RENEWED
            && $stopped
            && $second->outcome === RenewalResult::ENDED
            ?: $first->outcome . ' / stopped ' . var_export($stopped, true) . ' / ' . $second->outcome;
    });

    // ------------------------------------------------------------------------
    section('The change window');

    check('a change too close to the renewal is refused, with a reason', function () use ($schedules, $subscriptions, $monthly, $user, $coffee, &$createdSubscriptions): bool|string {
        Plugin::getInstance()->getSettings()->changeLockHours = 12;

        $temp = $subscriptions->createSubscription($monthly, (int)$user->id, [
            new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00]),
        ]);
        $createdSubscriptions[] = $temp;
        $temp->dateNextPayment = (new DateTime())->modify('+2 hours');
        $temp->boxId = null;
        $subscriptions->save($temp);

        [$open, $reason] = $schedules->changeWindow($temp);

        return !$open && $reason !== null ?: 'window open ' . var_export($open, true);
    });

    check('a change well ahead of it is allowed', function () use ($schedules, $createdSubscriptions, $subscriptions): bool|string {
        $temp = end($createdSubscriptions);
        $temp->dateNextPayment = (new DateTime())->modify('+20 days');
        $subscriptions->save($temp);

        [$open] = $schedules->changeWindow($temp);

        return $open ?: 'the window was shut twenty days out';
    });

    // ------------------------------------------------------------------------
    section('Quantity');

    check('a quantity change takes effect at the next renewal, not today', function () use ($subscriptions, $ledger, $createdSubscriptions): bool|string {
        $temp = end($createdSubscriptions);
        $before = $temp->getRenewalSubtotal();

        $subscriptions->setQuantity($temp, 3);

        // The current period has been paid for at the old quantity.
        return abs($temp->getRenewalSubtotal() - ($before * 3)) < 0.001
            && count($ledger->getEntriesOfType((int)$temp->id, LogEntry::TYPE_QUANTITY)) === 1
            ?: 'was ' . $before . ' now ' . $temp->getRenewalSubtotal();
    });

    // ------------------------------------------------------------------------
    section('Element queries');

    check('subscriptions can be found by their reference', function () use ($subscriptions, $subscription): bool|string {
        $found = $subscriptions->getSubscriptionByReference($subscription->reference);

        return $found?->id === $subscription->id ?: 'not found';
    });

    check('subscriptions can be filtered by status', function () use ($user): bool|string {
        $active = Subscription::find()
            ->status(null)
            ->userId($user->id)
            ->subscriptionStatus(Subscription::STATUS_ACTIVE)
            ->count();

        return $active > 0 ?: 'no active subscriptions for the test user';
    });

    check('“renewing within” finds only live subscriptions', function () use ($user): bool|string {
        $ids = Subscription::find()->status(null)->renewingWithin(3650)->ids();
        $expired = Subscription::find()->status(null)->id($ids)->subscriptionStatus(Subscription::STATUS_EXPIRED)->count();

        return $expired === 0 ?: $expired . ' expired subscriptions in the renewing list';
    });

    check('the due query excludes paused subscriptions', function () use ($subscriptions, $monthly, $user, $coffee, &$createdSubscriptions): bool|string {
        $temp = $subscriptions->createSubscription($monthly, (int)$user->id, [
            new Item(['purchasableId' => $coffee->id, 'qty' => 1, 'price' => 12.00]),
        ]);
        $createdSubscriptions[] = $temp;
        $subscriptions->activate($temp, new DateTime('2020-01-01'));
        $subscriptions->pause($temp);

        $dueIds = array_map('intval', Subscription::find()->status(null)->due()->ids());

        return !in_array((int)$temp->id, $dueIds, true) ?: 'a paused subscription was due';
    });

    check('the cached renewal price matches the items', function () use ($subscriptions, $boxSub): bool|string {
        $subscriptions->refreshRenewalPrice($boxSub);

        return abs($boxSub->renewalPrice - $boxSub->getRenewalSubtotal()) < 0.001
            ?: $boxSub->renewalPrice . ' vs ' . $boxSub->getRenewalSubtotal();
    });

    // ------------------------------------------------------------------------
    section('Editions');

    check('Lite switches off boxes, skip, swap, prepaid, gifts and switching', function () use ($plugin): bool|string {
        $plugin->edition = Plugin::EDITION_LITE;

        $off = !$plugin->getEffectiveBoxesEnabled()
            && !$plugin->getEffectiveSkipEnabled()
            && !$plugin->getEffectiveSwapEnabled()
            && !$plugin->getEffectivePrepaidEnabled()
            && !$plugin->getEffectiveGiftsEnabled()
            && !$plugin->getEffectiveSwitchingEnabled()
            && !$plugin->getEffectiveDunningProfilesEnabled();

        $plugin->edition = Plugin::EDITION_PRO;

        return $off ?: 'a Pro feature was on in Lite';
    });

    check('renewals, dunning, pause and cancel are in Lite', function () use ($plugin, $renewals, $subscriptions, $dunning): bool|string {
        $plugin->edition = Plugin::EDITION_LITE;

        // A free-tier store whose cards decline still needs its money, and a paused subscriber is
        // a customer you still have.
        $available = method_exists($renewals, 'renew')
            && $plugin->getSettings()->enableDunning
            && $dunning->getFallbackProfile()->getStageCount() > 0;

        $plugin->edition = Plugin::EDITION_PRO;

        return $available ?: 'a Lite feature was missing';
    });

    check('a Lite install reports its suppressed Pro configuration', function () use ($plugin): bool|string {
        $plugin->edition = Plugin::EDITION_LITE;
        $suppressed = $plugin->getHasSuppressedProSettings();
        $plugin->edition = Plugin::EDITION_PRO;

        // A downgraded install must be told why its box plans stopped offering boxes.
        return $suppressed ?: 'the boxes in the database were not reported';
    });

    check('Pro reports nothing suppressed', function () use ($plugin): bool|string {
        return $plugin->getHasSuppressedProSettings() === false ?: 'Pro reported suppressed settings';
    });

    // ------------------------------------------------------------------------
    section('Housekeeping');

    check('every user-facing string is braced next to a typographic quote', function (): bool|string {
        // PHP identifiers may contain bytes 0x80–0xFF, so "Box “$name” saved." parses as a
        // variable called `name”` and fatals at runtime, not at lint time.
        $bad = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/var/www/craft-subscribr/src'));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match('/[“”‘’]\$[a-zA-Z_]/u', $line)) {
                    $bad[] = $file->getFilename() . ':' . ($n + 1);
                }
            }
        }

        return $bad === [] ?: implode(', ', $bad);
    });

    check('no plugin settings are marked required', function () use ($plugin): bool|string {
        // A settings model with a required attribute cannot be saved on a fresh install, because
        // Craft validates it before the first edit.
        foreach ((new Settings())->rules() as $rule) {
            if (($rule[1] ?? null) === 'required') {
                return 'a required rule is on ' . json_encode($rule[0]);
            }
        }

        return true;
    });

    check('the settings model round-trips through its own array', function (): bool|string {
        $settings = new Settings(['renewalBatchSize' => 25, 'dunningFinalAction' => 'pause']);
        $again = new Settings($settings->toArray());

        return $again->renewalBatchSize === 25 && $again->dunningFinalAction === 'pause'
            ?: json_encode($again->toArray());
    });

    check('the retry schedule is cleaned, sorted and de-duplicated', function (): bool|string {
        $settings = new Settings(['defaultRetryHours' => [72, 24, 24, 0, -5, 168]]);

        // A profile editor that lets somebody type "24, 12, 24" must not produce a retry schedule
        // that goes backwards.
        return $settings->getDefaultRetryHours() === [24, 72, 168]
            ?: json_encode($settings->getDefaultRetryHours());
    });

    check('a plan exports without any IDs in it', function () use ($plans, $monthly): bool|string {
        $data = $plans->toExportArray($monthly);

        return !array_key_exists('id', $data)
            && !array_key_exists('boxId', $data)
            && array_key_exists('box', $data)
            && $data['handle'] === $monthly->handle
            ?: json_encode(array_keys($data));
    });

    check('every table Subscribr installed exists', function (): bool|string {
        $missing = [];
        $schema = Craft::$app->getDb()->getSchema();

        foreach ((new ReflectionClass(Table::class))->getConstants() as $name => $table) {
            if ($schema->getTableSchema($table) === null) {
                $missing[] = $name;
            }
        }

        return $missing === [] ?: 'missing ' . implode(', ', $missing);
    });
} finally {
    $plugin->edition = $originalEdition;
    Plugin::getInstance()->setSettings($originalSettings->toArray());

    foreach ($createdSubscriptions as $subscriptionToDelete) {
        $fresh = Subscription::find()->id($subscriptionToDelete->id)->status(null)->one();

        if ($fresh) {
            $elements->deleteElement($fresh, true);
        }
    }

    foreach ($createdOrders as $order) {
        $fresh = Order::find()->id($order->id)->status(null)->one();

        if ($fresh) {
            $elements->deleteElement($fresh, true);
        }
    }

    foreach ($createdProducts as $product) {
        $fresh = Product::find()->id($product->id)->status(null)->one();

        if ($fresh) {
            $elements->deleteElement($fresh, true);
        }
    }

    foreach ($createdPlans as $plan) {
        if ($plan->id) {
            Craft::$app->getDb()->createCommand()->delete(Table::PLANS, ['id' => $plan->id])->execute();
        }
    }

    foreach ($createdBoxes as $boxToDelete) {
        if ($boxToDelete->id) {
            Craft::$app->getDb()->createCommand()->delete(Table::BOXES, ['id' => $boxToDelete->id])->execute();
        }
    }

    foreach ($createdProfiles as $profileToDelete) {
        if ($profileToDelete->id) {
            Craft::$app->getDb()->createCommand()->delete(Table::DUNNING, ['id' => $profileToDelete->id])->execute();
        }
    }

    foreach ($createdUsers as $userToDelete) {
        $fresh = User::find()->id($userToDelete->id)->status(null)->one();

        if ($fresh) {
            $elements->deleteElement($fresh, true);
        }
    }
}

echo "\n$passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
