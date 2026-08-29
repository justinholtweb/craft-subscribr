<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\db;

/**
 * Subscribr's tables.
 *
 * Everything lives in the database rather than project config, for the same reason Commerce keeps
 * its own shipping methods and subscription plans there: a plan points at purchasables, and
 * purchasable IDs are not portable between environments. `subscribr/plans export|import` moves
 * plans by SKU instead.
 */
abstract class Table
{
    public const PLANS = '{{%subscribr_plans}}';
    public const SUBSCRIPTIONS = '{{%subscribr_subscriptions}}';
    public const ITEMS = '{{%subscribr_items}}';
    public const ORDERS = '{{%subscribr_orders}}';
    public const EVENTS = '{{%subscribr_events}}';
    public const SCHEDULES = '{{%subscribr_schedules}}';
    public const BOXES = '{{%subscribr_boxes}}';
    public const BOXSLOTS = '{{%subscribr_boxslots}}';
    public const DUNNING = '{{%subscribr_dunning}}';
    public const ATTEMPTS = '{{%subscribr_attempts}}';
    public const GIFTS = '{{%subscribr_gifts}}';
}
