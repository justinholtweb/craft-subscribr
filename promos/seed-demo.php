<?php
/**
 * Seeds a believable Subscribr store into the plugin-testing install so the control-panel
 * screens can be screenshotted for the marketing page and the Plugin Store promos.
 *
 *   docker exec -w /var/www/html ddev-plugin-testing-web php /path/seed-demo.php
 *   docker exec -w /var/www/html ddev-plugin-testing-web php /path/seed-demo.php --teardown
 *
 * Everything it creates is tagged DEMO_TAG so --teardown can find it again.
 */

$root = getcwd();
require $root . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\payments\DummyPaymentForm;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use craft\helpers\StringHelper;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\models\Attempt;
use justinholtweb\subscribr\models\Box;
use justinholtweb\subscribr\models\BoxSlot;
use justinholtweb\subscribr\models\Cadence;
use justinholtweb\subscribr\models\DunningProfile;
use justinholtweb\subscribr\models\DunningStage;
use justinholtweb\subscribr\models\Item;
use justinholtweb\subscribr\models\Plan;
use justinholtweb\subscribr\models\RenewalResult;
use justinholtweb\subscribr\models\ScheduledAction;
use justinholtweb\subscribr\db\Table;
use justinholtweb\subscribr\Plugin;

const DEMO = 'demo';                 // handle prefix
const DEMO_EMAIL = '@rowangray.test';

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$elements = Craft::$app->getElements();
$storeId = $commerce->getStores()->getPrimaryStore()->id;

$plans = $plugin->getPlans();
$subscriptions = $plugin->getSubscriptions();
$renewals = $plugin->getRenewals();
$dunning = $plugin->getDunning();
$boxes = $plugin->getBoxes();
$schedules = $plugin->getSchedules();

$plugin->edition = Plugin::EDITION_PRO;
$plugin->getSettings()->sendEmails = false;

$teardown = in_array('--teardown', $argv, true);

// ---------------------------------------------------------------- teardown --
if ($teardown) {
    foreach (Subscription::find()->status(null)->limit(null)->all() as $sub) {
        $plan = $sub->getPlan();
        if ($plan && str_starts_with($plan->handle, DEMO)) {
            $elements->deleteElement($sub, true);
        }
    }
    foreach ($plans->getAllPlans() as $plan) {
        if (str_starts_with($plan->handle, DEMO)) {
            $plans->deletePlanById((int)$plan->id);
        }
    }
    foreach ($boxes->getAllBoxes() as $box) {
        if (str_starts_with($box->handle, DEMO)) {
            $boxes->deleteBoxById((int)$box->id);
        }
    }
    foreach ($dunning->getAllProfiles() as $p) {
        if (str_starts_with($p->handle, DEMO)) {
            $dunning->deleteProfileById((int)$p->id);
        }
    }
    foreach (User::find()->limit(null)->all() as $u) {
        if (str_ends_with((string)$u->email, DEMO_EMAIL)) {
            $elements->deleteElement($u, true);
        }
    }
    foreach (Product::find()->limit(null)->all() as $p) {
        if (str_starts_with((string)$p->title, 'Rowan & Gray')) {
            $elements->deleteElement($p, true);
        }
    }
    echo "Demo data removed.\n";
    exit(0);
}

// ------------------------------------------------------------------- build --
$dummy = $commerce->getGateways()->getGatewayByHandle('dummy');
if (!$dummy) {
    throw new RuntimeException('No dummy gateway in this install.');
}

$productType = $commerce->getProductTypes()->getAllProductTypes()[0];

function product(string $title, float $price, $productType): Variant
{
    $p = new Product();
    $p->typeId = $productType->id;
    $p->title = $title;
    $p->enabled = true;
    $v = new Variant();
    $v->sku = strtoupper(StringHelper::randomString(8));
    $v->basePrice = $price;
    $v->isDefault = true;
    $p->setVariants([$v]);
    if (!Craft::$app->getElements()->saveElement($p)) {
        throw new RuntimeException('product: ' . json_encode($p->getErrors()));
    }
    return $p->getDefaultVariant();
}

echo "Products…\n";
$yirg   = product('Rowan & Gray Ethiopia Yirgacheffe 250g', 16.00, $productType);
$huila  = product('Rowan & Gray Colombia Huila 250g', 15.00, $productType);
$decaf  = product('Rowan & Gray Decaf Brazil Cerrado 250g', 15.50, $productType);
$mug    = product('Rowan & Gray Stoneware Mug', 12.00, $productType);
$papers = product('Rowan & Gray Filter Papers 100', 6.00, $productType);

echo "Dunning sequence…\n";
$profile = new DunningProfile(['name' => 'Standard recovery', 'handle' => DEMO . 'Recovery']);
$profile->setStages([
    ['offsetHours' => 24,  'action' => DunningStage::ACTION_RETRY],
    ['offsetHours' => 72,  'action' => DunningStage::ACTION_RETRY],
    ['offsetHours' => 120, 'action' => DunningStage::ACTION_EMAIL],
    ['offsetHours' => 168, 'action' => DunningStage::ACTION_RETRY],
    ['offsetHours' => 240, 'action' => DunningStage::ACTION_CANCEL],
]);
$dunning->saveProfile($profile);

echo "Box…\n";
$box = new Box([
    'name' => 'Build Your Own Box',
    'handle' => DEMO . 'Box',
    'mode' => Box::MODE_CHOICE,
    'pricing' => Box::PRICING_CONTENTS,
    'lockHours' => 48,
    'allowSwap' => true,
]);
$coffeeSlot = new BoxSlot(['name' => 'Coffee', 'minItems' => 2, 'maxItems' => 3]);
$coffeeSlot->setSources(['purchasableIds' => [$yirg->id, $huila->id, $decaf->id]]);
$extrasSlot = new BoxSlot(['name' => 'Extras', 'minItems' => 0, 'maxItems' => 2, 'isAddOn' => true]);
$extrasSlot->setSources(['purchasableIds' => [$mug->id, $papers->id]]);
$box->setSlots([$coffeeSlot, $extrasSlot]);
$boxes->saveBox($box);

echo "Plans…\n";
$monthly = new Plan([
    'name' => 'Coffee Club — Monthly',
    'handle' => DEMO . 'Monthly',
    'description' => 'Two bags a month, roasted the day before it ships.',
    'interval' => Cadence::MONTH,
    'intervalCount' => 1,
    'anchorDay' => 3,
    'pricingMode' => Plan::PRICING_LOCKED,
    'prepaidOptions' => '3,6,12',
    'prepaidDiscountPercent' => 10,
    'allowSkip' => true,
    'allowSwap' => true,
    'allowSwitch' => true,
    'allowGift' => true,
    'switchGroup' => 'coffeeClub',
    'dunningId' => $profile->id,
    'storeId' => $storeId,
]);
$plans->savePlan($monthly);

$fortnightly = new Plan([
    'name' => 'Coffee Club — Fortnightly',
    'handle' => DEMO . 'Fortnightly',
    'description' => 'For people who get through it faster.',
    'interval' => Cadence::WEEK,
    'intervalCount' => 2,
    'pricingMode' => Plan::PRICING_LOCKED,
    'allowSkip' => true,
    'allowSwitch' => true,
    'switchGroup' => 'coffeeClub',
    'dunningId' => $profile->id,
    'storeId' => $storeId,
]);
$plans->savePlan($fortnightly);

$double = new Plan([
    'name' => 'Coffee Club — Double',
    'handle' => DEMO . 'Double',
    'description' => 'Four bags a month at a flat price.',
    'interval' => Cadence::MONTH,
    'intervalCount' => 1,
    'anchorDay' => 3,
    'pricingMode' => Plan::PRICING_OVERRIDE,
    'planPrice' => 56.00,
    'allowSwitch' => true,
    'switchGroup' => 'coffeeClub',
    'dunningId' => $profile->id,
    'storeId' => $storeId,
]);
$plans->savePlan($double);

$boxPlan = new Plan([
    'name' => 'Build Your Own Box — Monthly',
    'handle' => DEMO . 'BoxMonthly',
    'description' => 'Pick the bags yourself; swap any month.',
    'interval' => Cadence::MONTH,
    'intervalCount' => 1,
    'pricingMode' => Plan::PRICING_LOCKED,
    'boxId' => $box->id,
    'allowSkip' => true,
    'allowSwap' => true,
    'dunningId' => $profile->id,
    'storeId' => $storeId,
]);
$plans->savePlan($boxPlan);

$trialPlan = new Plan([
    'name' => 'Coffee Club — 14-day trial',
    'handle' => DEMO . 'Trial',
    'description' => 'Two weeks before the first payment.',
    'interval' => Cadence::MONTH,
    'intervalCount' => 1,
    'trialDays' => 14,
    'pricingMode' => Plan::PRICING_LOCKED,
    'allowSwitch' => true,
    'switchGroup' => 'coffeeClub',
    'dunningId' => $profile->id,
    'storeId' => $storeId,
]);
$plans->savePlan($trialPlan);

echo "Subscribers…\n";
function subscriber(string $first, string $last, $dummy): array
{
    $u = new User();
    $u->username = strtolower($first . '.' . $last);
    $u->email = strtolower($first . '.' . $last) . DEMO_EMAIL;
    $u->firstName = $first;
    $u->lastName = $last;
    if (!Craft::$app->getElements()->saveElement($u)) {
        throw new RuntimeException('user: ' . json_encode($u->getErrors()));
    }
    $src = Commerce::getInstance()->getPaymentSources()->createPaymentSource(
        (int)$u->id,
        $dummy,
        new DummyPaymentForm(['firstName' => $first, 'lastName' => $last, 'number' => '4242424242424242', 'expiry' => '01/2030', 'cvv' => '123']),
        'Visa ending 4242',
    );
    return [$u, $src];
}

function item($variant, int $qty, float $price, string $desc): Item
{
    $i = new Item(['purchasableId' => $variant->id, 'qty' => $qty, 'price' => $price, 'description' => $desc, 'sku' => $variant->getSku()]);
    $i->setSnapshot(['listPrice' => $price]);
    return $i;
}

$people = [
    ['Marguerite', 'Okonkwo'], ['Tobias', 'Lindqvist'], ['Priya', 'Raghunathan'],
    ['Callum', 'Fairweather'], ['Ines', 'Delacroix'], ['Yusuf', 'Barakat'],
    ['Hannah', 'Vestergaard'], ['Dmitri', 'Anandale'], ['Rosalind', 'Achebe'],
    ['Esther', 'Nakamura'], ['Olivier', 'Brandt'], ['Freya', 'Mikkelsen'],
    ['Aurelio', 'Santos'],
];
$users = [];
foreach ($people as [$f, $l]) {
    $users[] = subscriber($f, $l, $dummy);
}

$opts = fn($src) => ['gatewayId' => (int)$dummy->id, 'paymentSourceId' => (int)$src->id, 'currency' => 'USD'];

/**
 * Record a decline the way a real one arrives: an attempt row as well as the dunning state.
 *
 * `Dunning::recordFailure()` moves the subscription and books the retry; the attempt row is written
 * by `Billing::charge()`, which a seeded failure never goes through. Without it the dunning summary
 * counts no attempts and the recovery rate renders as a dash.
 */
$decline = function (Subscription $sub, string $why, ?string $code = null, int $daysAgo = 0) use ($dunning): void {
    $mark = (int)(new craft\db\Query())->from(Table::EVENTS)->max('id');
    $attempt = new Attempt([
        'subscriptionId' => (int)$sub->id,
        'outcome' => Attempt::FAILED,
        'message' => $why,
        'gatewayCode' => $code,
    ]);
    $at = (new DateTime())->modify("-$daysAgo days")->format('Y-m-d H:i:s');
    Craft::$app->getDb()->createCommand()->insert('{{%subscribr_attempts}}', [
        'subscriptionId' => (int)$sub->id,
        'stage' => (int)$sub->dunningStage,
        'outcome' => Attempt::FAILED,
        'amount' => $sub->renewalPrice,
        'currency' => 'USD',
        'message' => $why,
        'gatewayCode' => $code,
        'dateCreated' => $at,
        'dateUpdated' => $at,
        'uid' => \craft\helpers\StringHelper::UUID(),
    ])->execute();
    $dunning->recordFailure($sub, $attempt);

    // The ledger entry is written now but describes a decline that happened $daysAgo days ago.
    Craft::$app->getDb()->createCommand()->update(Table::EVENTS, ['dateCreated' => $at], ['>', 'id', $mark])->execute();
};

/**
 * Run a recovery and date the entries it writes.
 */
$recover = function (Subscription $sub, int $daysAgo) use ($dunning): void {
    $mark = (int)(new craft\db\Query())->from(Table::EVENTS)->max('id');
    $orderMark = (int)(new craft\db\Query())->from('{{%commerce_orders}}')->max('id');
    $at = (new DateTime())->modify("-$daysAgo days")->format('Y-m-d H:i:s');
    $dunning->retry($sub);
    Craft::$app->getDb()->createCommand()->update(Table::EVENTS, ['dateCreated' => $at], ['>', 'id', $mark])->execute();
    Craft::$app->getDb()->createCommand()->update('{{%commerce_orders}}', ['dateOrdered' => $at], ['>', 'id', $orderMark])->execute();
};
$monthsAgo = fn(int $n) => (new DateTime())->modify("-$n month")->modify('-1 day')->setTime(9, 0);

/**
 * Renew at the date that was due rather than at `now`.
 *
 * Back-dating a subscription and then sweeping it today is exactly the case the catch-up guard
 * exists to refuse: it abandons the missed cycles and jumps the schedule forward. Driving each
 * renewal at its own due date is what a store that has been running all along actually looks like,
 * and it leaves real orders dated across the past few months.
 */
$advance = function (Subscription $sub, int $times) use ($renewals): void {
    $db = Craft::$app->getDb();

    for ($i = 0; $i < $times; $i++) {
        if ($sub->dateNextPayment === null || $sub->dateNextPayment > new DateTime()) {
            break;
        }
        $at = (clone $sub->dateNextPayment)->modify('+2 hours');
        $stamp = $at->format('Y-m-d H:i:s');

        $eventMark = (int)(new craft\db\Query())->from(Table::EVENTS)->max('id');
        $orderMark = (int)(new craft\db\Query())->from('{{%commerce_orders}}')->max('id');

        $result = $renewals->renew($sub, $at);

        if ($result->outcome !== RenewalResult::RENEWED) {
            echo "     stopped: {$result->outcome}\n";
            break;
        }

        // The rows are written now but describe something that happened months ago. A ledger whose
        // every entry is timestamped today reads as a fixture, which is the opposite of the point.
        $db->createCommand()->update(Table::EVENTS, ['dateCreated' => $stamp], ['>', 'id', $eventMark])->execute();
        $db->createCommand()->update('{{%commerce_orders}}', ['dateOrdered' => $stamp], ['>', 'id', $orderMark])->execute();
        $db->createCommand()->update('{{%elements}}', ['dateCreated' => $stamp], [
            'id' => (new craft\db\Query())->select('id')->from('{{%commerce_orders}}')->where(['>', 'id', $orderMark])->column(),
        ])->execute();
    }
};

/**
 * Stamp everything written while a subscription was being set up with the date it started.
 */
$backdateSetup = function (Subscription $sub, DateTime $start) {
    $stamp = $start->format('Y-m-d H:i:s');
    Craft::$app->getDb()->createCommand()->update(Table::EVENTS, ['dateCreated' => $stamp], [
        'and',
        ['subscriptionId' => (int)$sub->id],
        ['in', 'type', ['created', 'activated']],
    ])->execute();
    Craft::$app->getDb()->createCommand()->update('{{%elements}}', ['dateCreated' => $stamp], ['id' => (int)$sub->id])->execute();
};

// 1 — long-running active subscription with real renewal history
[$u, $s] = $users[0];
$a = $subscriptions->createSubscription($monthly, (int)$u->id, [
    item($yirg, 1, 16.00, 'Ethiopia Yirgacheffe 250g'),
    item($huila, 1, 15.00, 'Colombia Huila 250g'),
], $opts($s));
$subscriptions->activate($a, $monthsAgo(5));
$backdateSetup($a, $monthsAgo(5));
$advance($a, 5);
echo "  active, {$a->cycleCount} cycles\n";

// 2 — active, and has already skipped one month
[$u, $s] = $users[1];
$b = $subscriptions->createSubscription($monthly, (int)$u->id, [item($decaf, 2, 15.50, 'Decaf Brazil Cerrado 250g')], $opts($s));
$subscriptions->activate($b, $monthsAgo(3));
$backdateSetup($b, $monthsAgo(3));
$advance($b, 1);
$schedules->book($b, ScheduledAction::SKIP, $b->cycleCount + 1);
$advance($b, 1);
$advance($b, 1);
echo "  active with a skip in the ledger\n";

// 3 — trialing
[$u, $s] = $users[2];
$c = $subscriptions->createSubscription($trialPlan, (int)$u->id, [item($yirg, 1, 16.00, 'Ethiopia Yirgacheffe 250g')], $opts($s));
$subscriptions->activate($c, (new DateTime())->modify('-3 days'));
echo "  trialing\n";

// 4 — paused
[$u, $s] = $users[3];
$d = $subscriptions->createSubscription($fortnightly, (int)$u->id, [item($huila, 1, 15.00, 'Colombia Huila 250g')], $opts($s));
$subscriptions->activate($d, $monthsAgo(2));
$backdateSetup($d, $monthsAgo(2));
$advance($d, 4);
$subscriptions->pause($d, null, 'Travelling until the spring');
echo "  paused\n";

// 5 — past due, mid dunning sequence
[$u, $s] = $users[4];
$e = $subscriptions->createSubscription($monthly, (int)$u->id, [
    item($yirg, 2, 16.00, 'Ethiopia Yirgacheffe 250g'),
    item($papers, 1, 6.00, 'Filter Papers 100'),
], $opts($s));
$subscriptions->activate($e, $monthsAgo(4));
$backdateSetup($e, $monthsAgo(4));
$advance($e, 3);
$decline($e, 'Insufficient funds', 'insufficient_funds', 4);
$decline($e, 'Card declined by issuer', null, 1);
echo "  past due, dunning stage {$e->dunningStage}, {$e->failureCount} failures\n";

// 6 — cancelled, still delivering to the end of the paid period
[$u, $s] = $users[5];
$f = $subscriptions->createSubscription($monthly, (int)$u->id, [item($huila, 1, 15.00, 'Colombia Huila 250g')], $opts($s));
$subscriptions->activate($f, $monthsAgo(3));
$backdateSetup($f, $monthsAgo(3));
$advance($f, 2);
$subscriptions->cancel($f, 'Moving abroad');
echo "  cancelled, runs to period end\n";

// 7 — box plan with a swap booked for next cycle
[$u, $s] = $users[6];
$g = $subscriptions->createSubscription($boxPlan, (int)$u->id, [
    item($yirg, 1, 16.00, 'Ethiopia Yirgacheffe 250g'),
    item($decaf, 1, 15.50, 'Decaf Brazil Cerrado 250g'),
], $opts($s));
$subscriptions->activate($g, $monthsAgo(2));
$backdateSetup($g, $monthsAgo(2));
$advance($g, 2);
$schedules->book($g, ScheduledAction::SWAP, $g->cycleCount + 1, [
    'items' => [
        ['purchasableId' => $huila->id, 'qty' => 2, 'price' => 15.00, 'description' => 'Colombia Huila 250g'],
        ['purchasableId' => $mug->id, 'qty' => 1, 'price' => 12.00, 'description' => 'Stoneware Mug'],
    ],
]);
echo "  box plan with a swap booked\n";

// 8 — prepaid twelve months up front
[$u, $s] = $users[7];
$h = $subscriptions->createSubscription($monthly, (int)$u->id, [item($yirg, 1, 16.00, 'Ethiopia Yirgacheffe 250g')], $opts($s) + ['prepaidCycles' => 12]);
$subscriptions->activate($h, $monthsAgo(2));
$backdateSetup($h, $monthsAgo(2));
$advance($h, 2);
echo "  prepaid\n";

// 9 — on the fortnightly plan, healthy
[$u, $s] = $users[8];
$i = $subscriptions->createSubscription($fortnightly, (int)$u->id, [item($huila, 1, 15.00, 'Colombia Huila 250g')], $opts($s));
$subscriptions->activate($i, $monthsAgo(1));
$backdateSetup($i, $monthsAgo(1));
$advance($i, 3);
echo "  fortnightly\n";

// 10, 11 — two more cards sitting in the retry queue, so the board looks like a working store
foreach ([[9, 'Expired card', 2], [10, 'Insufficient funds', 1]] as [$n, $why, $fails]) {
    [$u, $s] = $users[$n];
    $sub = $subscriptions->createSubscription($monthly, (int)$u->id, [
        item($yirg, 1, 16.00, 'Ethiopia Yirgacheffe 250g'),
        item($mug, 1, 12.00, 'Stoneware Mug'),
    ], $opts($s));
    $subscriptions->activate($sub, $monthsAgo(3));
    $backdateSetup($sub, $monthsAgo(3));
    $advance($sub, 2);
    for ($k = 0; $k < $fails; $k++) {
        $decline($sub, $why, null, $fails - $k);
    }
    echo "  past due — $why\n";
}

// 12 — failed, then the retry worked. Without one of these the recovery rate reads as a dash.
[$u, $s] = $users[11];
$recovered = $subscriptions->createSubscription($monthly, (int)$u->id, [item($huila, 2, 15.00, 'Colombia Huila 250g')], $opts($s));
$subscriptions->activate($recovered, $monthsAgo(3));
$backdateSetup($recovered, $monthsAgo(3));
$advance($recovered, 2);
$decline($recovered, 'Insufficient funds', null, 9);
$recover($recovered, 8);
echo "  recovered after a decline\n";

[$u, $s] = $users[12];
$recovered2 = $subscriptions->createSubscription($fortnightly, (int)$u->id, [item($decaf, 1, 15.50, 'Decaf Brazil Cerrado 250g')], $opts($s));
$subscriptions->activate($recovered2, $monthsAgo(2));
$backdateSetup($recovered2, $monthsAgo(2));
$advance($recovered2, 3);
$decline($recovered2, 'Card declined by issuer', null, 20);
$recover($recovered2, 19);
echo "  recovered after a decline\n";

echo "\nDone.\n";
