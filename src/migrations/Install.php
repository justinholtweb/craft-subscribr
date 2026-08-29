<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use craft\commerce\db\Table as CommerceTable;
use justinholtweb\subscribr\db\Table;

/**
 * Subscribr's schema.
 *
 * The shape of it follows from the one decision that defines the plugin: a subscription is not a
 * mirror of something a gateway is holding, it is the authoritative record, and a renewal is a
 * real Commerce order. So the money columns are here, the schedule is here, and Commerce is asked
 * only to take a payment.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->_createTables();
        $this->_createIndexes();
        $this->_addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        // Children first: the FKs below are cascade-on-delete, but dropping in dependency order
        // keeps an uninstall from depending on the driver's opinion about that.
        $this->dropTableIfExists(Table::ATTEMPTS);
        $this->dropTableIfExists(Table::GIFTS);
        $this->dropTableIfExists(Table::SCHEDULES);
        $this->dropTableIfExists(Table::EVENTS);
        $this->dropTableIfExists(Table::ORDERS);
        $this->dropTableIfExists(Table::ITEMS);
        $this->dropTableIfExists(Table::SUBSCRIPTIONS);
        $this->dropTableIfExists(Table::BOXSLOTS);
        $this->dropTableIfExists(Table::BOXES);
        $this->dropTableIfExists(Table::PLANS);
        $this->dropTableIfExists(Table::DUNNING);

        return true;
    }

    private function _createTables(): void
    {
        $this->createTable(Table::DUNNING, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'description' => $this->text(),
            // Ordered stages: [{offsetHours, action, emailKey, note}]. JSON rather than a table
            // because a stage is never queried on its own — it is only ever read as a whole
            // profile by the dunning run, and edited as a whole profile in the CP.
            'stages' => $this->text(),
            'isDefault' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::BOXES, [
            'id' => $this->primaryKey(),
            'storeId' => $this->integer(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'description' => $this->text(),
            // curated  — the merchant picks the contents each cycle
            // choice   — the subscriber picks, within the slot rules
            // surprise — Subscribr picks, at random, from the slot sources, avoiding repeats
            'mode' => $this->string()->notNull()->defaultValue('choice'),
            // contents — the box costs the sum of what is in it
            // fixed    — the box costs boxPrice regardless of contents
            // base     — boxPrice, plus the price of anything flagged as an add-on
            'pricing' => $this->string()->notNull()->defaultValue('contents'),
            'boxPrice' => $this->decimal(14, 4),
            'minItems' => $this->integer(),
            'maxItems' => $this->integer(),
            'allowSwap' => $this->boolean()->notNull()->defaultValue(true),
            // How long before the next renewal the contents lock. Hours.
            'lockHours' => $this->integer()->notNull()->defaultValue(24),
            'avoidRepeatCycles' => $this->integer()->notNull()->defaultValue(0),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::BOXSLOTS, [
            'id' => $this->primaryKey(),
            'boxId' => $this->integer()->notNull(),
            'name' => $this->string()->notNull(),
            'minItems' => $this->integer()->notNull()->defaultValue(1),
            'maxItems' => $this->integer()->notNull()->defaultValue(1),
            // {"purchasableIds": [...], "productTypeIds": [...], "categoryIds": [...]}
            'sources' => $this->text(),
            'isAddOn' => $this->boolean()->notNull()->defaultValue(false),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::PLANS, [
            'id' => $this->primaryKey(),
            'storeId' => $this->integer(),
            'boxId' => $this->integer(),
            'dunningId' => $this->integer(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'description' => $this->text(),

            // Cadence.
            'interval' => $this->string()->notNull()->defaultValue('month'),
            'intervalCount' => $this->integer()->notNull()->defaultValue(1),
            // null = renew on the anniversary of the signup; otherwise a day-of-month (1-28) or
            // day-of-week (0-6) that every subscriber on the plan bills on.
            'anchorDay' => $this->integer(),
            'trialDays' => $this->integer()->notNull()->defaultValue(0),
            'signupFee' => $this->decimal(14, 4),
            // 0 = renews until cancelled.
            'maxCycles' => $this->integer()->notNull()->defaultValue(0),

            // What the recurring charge is, relative to the purchasable's own price.
            // inherit  — whatever the purchasable costs at renewal time (catalog pricing applies)
            // locked   — whatever it cost at signup, for the life of the subscription
            // override — planPrice, always
            'pricingMode' => $this->string()->notNull()->defaultValue('inherit'),
            'planPrice' => $this->decimal(14, 4),
            'discountPercent' => $this->decimal(6, 3),

            // Prepaid: a comma-separated list of cycle counts a subscriber may pay up front for,
            // e.g. "3,6,12". Empty means prepaying is off for this plan.
            'prepaidOptions' => $this->string(),
            'prepaidDiscountPercent' => $this->decimal(6, 3),

            // Self-service permissions.
            'allowPause' => $this->boolean()->notNull()->defaultValue(true),
            'maxPauseCycles' => $this->integer()->notNull()->defaultValue(0),
            'allowSkip' => $this->boolean()->notNull()->defaultValue(true),
            'maxSkipsPerYear' => $this->integer()->notNull()->defaultValue(0),
            'allowSwap' => $this->boolean()->notNull()->defaultValue(true),
            'allowSwitch' => $this->boolean()->notNull()->defaultValue(true),
            'allowCancel' => $this->boolean()->notNull()->defaultValue(true),
            'allowGift' => $this->boolean()->notNull()->defaultValue(false),
            'allowQuantityChange' => $this->boolean()->notNull()->defaultValue(true),

            // Cancelling: 'end' waits for the period the subscriber paid for, 'immediate' does not.
            'cancelMode' => $this->string()->notNull()->defaultValue('end'),
            // Switching: 'prorate' bills the difference now, 'end' waits for the boundary.
            'switchMode' => $this->string()->notNull()->defaultValue('prorate'),
            // Which plans this one may be switched to. Empty = any plan in the same group.
            'switchGroup' => $this->string(),

            'shippable' => $this->boolean()->notNull()->defaultValue(true),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::SUBSCRIPTIONS, [
            'id' => $this->integer()->notNull(),
            'planId' => $this->integer(),
            'userId' => $this->integer(),
            'gatewayId' => $this->integer(),
            'paymentSourceId' => $this->integer(),
            'orderId' => $this->integer(),
            'boxId' => $this->integer(),
            'billingAddressId' => $this->integer(),
            'shippingAddressId' => $this->integer(),

            'reference' => $this->string()->notNull(),
            'status' => $this->string()->notNull()->defaultValue('pending'),

            'quantity' => $this->integer()->notNull()->defaultValue(1),
            'currency' => $this->string(3)->notNull()->defaultValue('USD'),
            // The recurring subtotal as of the last time it was computed. A cache of the items,
            // kept so the index and the reports do not have to sum the items table.
            'renewalPrice' => $this->decimal(14, 4)->notNull()->defaultValue(0),

            'dateStarted' => $this->dateTime(),
            'dateTrialEnds' => $this->dateTime(),
            'dateNextPayment' => $this->dateTime(),
            'dateCurrentPeriodStart' => $this->dateTime(),
            'dateCanceled' => $this->dateTime(),
            'dateEnds' => $this->dateTime(),
            'dateEnded' => $this->dateTime(),
            'datePaused' => $this->dateTime(),
            'dateResumes' => $this->dateTime(),
            'dateLastPayment' => $this->dateTime(),

            'cycleCount' => $this->integer()->notNull()->defaultValue(0),
            'prepaidCyclesRemaining' => $this->integer()->notNull()->defaultValue(0),
            'skipsUsed' => $this->integer()->notNull()->defaultValue(0),
            'pausesUsed' => $this->integer()->notNull()->defaultValue(0),

            // Dunning state. failureCount is consecutive: a success zeroes it.
            'failureCount' => $this->integer()->notNull()->defaultValue(0),
            'dunningStage' => $this->integer()->notNull()->defaultValue(0),
            'dateNextRetry' => $this->dateTime(),
            'lastFailureMessage' => $this->text(),

            // Manual renewal: the gateway cannot store a payment source, so each renewal order is
            // raised unpaid and the subscriber is emailed a link to pay it.
            'isManual' => $this->boolean()->notNull()->defaultValue(false),
            'autoRenew' => $this->boolean()->notNull()->defaultValue(true),

            'cancelReason' => $this->string(),
            'note' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createTable(Table::ITEMS, [
            'id' => $this->primaryKey(),
            'subscriptionId' => $this->integer()->notNull(),
            'purchasableId' => $this->integer(),
            'boxSlotId' => $this->integer(),
            // null = "every cycle from now on". An integer pins the row to one cycle, which is how
            // a swapped shipment differs from a permanent change to the subscription.
            'cycle' => $this->integer(),
            'qty' => $this->integer()->notNull()->defaultValue(1),
            'price' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'description' => $this->string(),
            'sku' => $this->string(),
            'options' => $this->text(),
            'snapshot' => $this->text(),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::ORDERS, [
            'id' => $this->primaryKey(),
            'subscriptionId' => $this->integer()->notNull(),
            'orderId' => $this->integer()->notNull(),
            // signup | renewal | prepaid | switch | resubscribe | manual
            'kind' => $this->string()->notNull()->defaultValue('renewal'),
            'cycle' => $this->integer()->notNull()->defaultValue(0),
            // A prepaid cycle has an order for the shipment but no money attached to it.
            'isPaidFromPrepaid' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::EVENTS, [
            'id' => $this->primaryKey(),
            'subscriptionId' => $this->integer()->notNull(),
            'type' => $this->string()->notNull(),
            'message' => $this->text(),
            'data' => $this->text(),
            // Who did it. Null is Subscribr itself — a renewal, a retry, an expiry sweep.
            'userId' => $this->integer(),
            // cp | portal | console | queue | api
            'source' => $this->string()->notNull()->defaultValue('console'),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::SCHEDULES, [
            'id' => $this->primaryKey(),
            'subscriptionId' => $this->integer()->notNull(),
            // skip | pause | resume | swap | switch | quantity | cancel | address
            'action' => $this->string()->notNull(),
            // Which cycle it lands on. A skip booked for cycle 7 is consumed when cycle 7 renews.
            'cycle' => $this->integer(),
            'applyAt' => $this->dateTime(),
            'payload' => $this->text(),
            'appliedAt' => $this->dateTime(),
            'canceledAt' => $this->dateTime(),
            'createdByUserId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::ATTEMPTS, [
            'id' => $this->primaryKey(),
            'subscriptionId' => $this->integer()->notNull(),
            'orderId' => $this->integer(),
            'transactionId' => $this->integer(),
            'stage' => $this->integer()->notNull()->defaultValue(0),
            // success | failed | skipped | manual | redirect
            'outcome' => $this->string()->notNull(),
            'amount' => $this->decimal(14, 4),
            'currency' => $this->string(3),
            'message' => $this->text(),
            'gatewayCode' => $this->string(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::GIFTS, [
            'id' => $this->primaryKey(),
            'subscriptionId' => $this->integer(),
            'orderId' => $this->integer(),
            'purchaserId' => $this->integer(),
            'recipientId' => $this->integer(),
            'recipientEmail' => $this->string()->notNull(),
            'recipientName' => $this->string(),
            'senderName' => $this->string(),
            'message' => $this->text(),
            'token' => $this->string(64)->notNull(),
            'cycles' => $this->integer()->notNull()->defaultValue(1),
            'dateDeliver' => $this->dateTime(),
            'dateDelivered' => $this->dateTime(),
            'dateClaimed' => $this->dateTime(),
            'dateExpires' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function _createIndexes(): void
    {
        $this->createIndex(null, Table::DUNNING, ['handle'], true);
        $this->createIndex(null, Table::BOXES, ['handle'], true);
        $this->createIndex(null, Table::BOXSLOTS, ['boxId', 'sortOrder']);
        $this->createIndex(null, Table::PLANS, ['handle'], true);
        $this->createIndex(null, Table::PLANS, ['enabled', 'sortOrder']);

        $this->createIndex(null, Table::SUBSCRIPTIONS, ['reference'], true);
        // The renewal sweep's only query: everything due, by status and date.
        $this->createIndex(null, Table::SUBSCRIPTIONS, ['status', 'dateNextPayment']);
        $this->createIndex(null, Table::SUBSCRIPTIONS, ['status', 'dateNextRetry']);
        $this->createIndex(null, Table::SUBSCRIPTIONS, ['userId', 'status']);
        $this->createIndex(null, Table::SUBSCRIPTIONS, ['planId']);

        $this->createIndex(null, Table::ITEMS, ['subscriptionId', 'cycle']);
        $this->createIndex(null, Table::ORDERS, ['subscriptionId', 'cycle']);
        $this->createIndex(null, Table::ORDERS, ['orderId']);
        $this->createIndex(null, Table::EVENTS, ['subscriptionId', 'dateCreated']);
        $this->createIndex(null, Table::SCHEDULES, ['subscriptionId', 'action', 'appliedAt']);
        $this->createIndex(null, Table::ATTEMPTS, ['subscriptionId', 'dateCreated']);
        $this->createIndex(null, Table::GIFTS, ['token'], true);
        $this->createIndex(null, Table::GIFTS, ['recipientEmail']);
    }

    private function _addForeignKeys(): void
    {
        // The element row owns the subscription's lifetime: delete the element, the whole history
        // goes with it, which is what Craft's trash and a user deletion both expect.
        $this->addForeignKey(null, Table::SUBSCRIPTIONS, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::SUBSCRIPTIONS, ['planId'], Table::PLANS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::SUBSCRIPTIONS, ['boxId'], Table::BOXES, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::SUBSCRIPTIONS, ['userId'], CraftTable::USERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::SUBSCRIPTIONS, ['orderId'], CommerceTable::ORDERS, ['id'], 'SET NULL');

        $this->addForeignKey(null, Table::PLANS, ['boxId'], Table::BOXES, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::PLANS, ['dunningId'], Table::DUNNING, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::BOXSLOTS, ['boxId'], Table::BOXES, ['id'], 'CASCADE');

        $this->addForeignKey(null, Table::ITEMS, ['subscriptionId'], Table::SUBSCRIPTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::ITEMS, ['boxSlotId'], Table::BOXSLOTS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::ORDERS, ['subscriptionId'], Table::SUBSCRIPTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::ORDERS, ['orderId'], CommerceTable::ORDERS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::EVENTS, ['subscriptionId'], Table::SUBSCRIPTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::EVENTS, ['userId'], CraftTable::USERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::SCHEDULES, ['subscriptionId'], Table::SUBSCRIPTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::ATTEMPTS, ['subscriptionId'], Table::SUBSCRIPTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::GIFTS, ['subscriptionId'], Table::SUBSCRIPTIONS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::GIFTS, ['purchaserId'], CraftTable::USERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::GIFTS, ['recipientId'], CraftTable::USERS, ['id'], 'SET NULL');
    }
}
