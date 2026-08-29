# Subscribr — Craft CMS 5 Plugin

## Project Overview

Subscribr is recurring commerce for Craft Commerce 5: subscription boxes, skip/pause/swap,
prepaid and gift plans, mixed carts, proration and dunning — on any gateway that can store a
payment method. Distributed as `justinholtweb/craft-subscribr`.
**Paid, two editions: Lite $99 ($79/yr), Pro $199 ($159/yr).**

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step: no asset bundles, no JS

## Architecture

### Namespace & package

- Namespace: `justinholtweb\subscribr`
- Package: `justinholtweb/craft-subscribr`
- Handle: `subscribr`

### The argument

Core Commerce asks one question of a gateway — *does it implement `SubscriptionGatewayInterface`?* —
and that question has one affirmative answer in practice, which is why subscriptions in Craft are a
Stripe feature rather than a Commerce feature. `craft\commerce\elements\Subscription` is welded to
it: `getGateway()` returns a `SubscriptionGatewayInterface`, `getNextPaymentAmount()` and
`getAlternativePlans()` both ask the gateway, and the element fatals on anything else. It cannot be
made to describe a subscription the store is running itself.

Subscribr asks a smaller question instead: **can this gateway charge a stored token with nobody
present?** That is `supportsPaymentSources()` plus `supportsPurchase()`, and it is true of nearly
every gateway written for Commerce. Everything else in the plugin follows from being able to
answer it.

### The five invariants

1. **`services\Renewals::renew()` is the only place a cycle advances.** Console, queue, the CP's
   "renew now", and the recovery of a manually-paid invoice all route through it.
2. **A renewal is a real `craft\commerce\elements\Order`.** That one decision buys tax, shipping,
   discounts, coupons, order statuses, emails, PDFs, the order index, reporting, refunds and every
   Commerce plugin the store already has — none of which is then reimplemented and subtly wrong.
3. **`services\Billing::charge()` is the only place money moves**, and it goes through Commerce's
   `Payments` + a stored `PaymentSource`. Nothing special-cases Stripe; a Stripe gateway is a
   gateway with payment sources.
4. **`models\Cadence::next()` is the only place a next-payment date is computed.** The engine, the
   preview, the proration maths and `renewals/due` all ask it, so a subscriber cannot be shown one
   date and billed on another.
5. **Every state change writes to `subscribr_events`**, and the CP timeline and the subscriber's
   portal read the same ledger. There is no second, gentler history for the customer.

### The schedule never drifts

The next payment date is computed from the date that **was due**, never from `now`. A sweep that
runs at 03:07 because the queue was busy must not move a subscriber's billing date seven minutes
later every month until it has walked around the clock. `Renewals::_advanceSchedule()` takes `$due`.

### Booked, not applied

Self-service is mostly "do this next time", not "do this now". Skips, pauses, swaps, plan switches
and quantity changes are rows in `subscribr_schedules`, consumed at the boundary by
`Renewals::_applyScheduledActions()`. Booking rather than mutating is what makes them reversible —
the subscriber changes their mind by cancelling a booking — and it gives the engine one place to
ask what this cycle is supposed to be.

A booked action either **replaces** the renewal (skip, pause, cancel) or **changes** it (swap,
switch, quantity, address). The first group returns a `RenewalResult` and stops; the second falls
through and the renewal carries on.

### Swap is cycle-pinned items

`subscribr_items.cycle` null means "every cycle from now on"; an integer pins the row to one cycle.
`getItemsForCycle()` returns the pinned set *if there is one*, otherwise the standing set — a swap
is a replacement, not an addition, so removing an item from a swapped box actually removes it. The
pinned rows are deleted when that cycle renews, so the month after goes back to normal without
anybody having to put it back.

### Mixed carts are three option keys

A recurring line is an ordinary line item carrying `subscribrPlan` (and optionally `subscribrPrepaid`,
the gift keys). Commerce already de-duplicates line items on purchasable ID **plus a hash of their
options**, so a product bought once and the same product bought monthly are automatically two
separate lines. Subscribr touches no cart machinery.

The keys are flat and scalar on purpose: a dotted key is unreachable from the Twig object template
Commerce renders line-item descriptions with, and a nested array hashes differently depending on key
order. Empty extras are filtered out in `Carts::optionsFor()` for the same reason.

`adjusters\SubscriptionAdjuster` handles what changes the *first* payment and not the recurring one —
trials, joining fees, prepayment — as adjustments rather than line prices, so the customer still
sees what they signed up for at its real price.

### Manual renewal is a cart

A gateway that cannot store a card gets an **incomplete** order and an emailed link. Incomplete
because an incomplete order is a cart, and a cart has `getLoadCartUrl()`, which drops the subscriber
into the store's own checkout with the renewal already in it — no bespoke payment page to build or
keep working when the store changes gateway. `Renewals::completeManualRenewal()` picks it up from
Commerce's order-complete event, and refuses to advance a cycle twice.

**Commerce purges incomplete carts.** `Plugin::_protectRenewalCarts()` hooks
`Carts::EVENT_BEFORE_PURGE_INACTIVE_CARTS` and excludes anything in `subscribr_orders`, or a purge
would delete an unpaid invoice and take the record of the debt with it.

### Data model

Eleven tables, in the database rather than project config — the same call Commerce makes for its own
plans, and for the same reason: a plan can point at a box, and a box points at purchasables, whose
IDs mean nothing in another environment. `subscribr/plans export|import` moves plans by handle.

`subscribr_subscriptions` is the element's own table. `status` is the column, mapped to the element
property `subscriptionStatus` — `status` on an element collides with `Element::getStatus()`.

### Editions

Lite is a complete, working subscription plugin: plans, renewals on any gateway, mixed carts,
trials, pause/cancel, dunning, portal, emails. Pro adds boxes, skip, swap, prepaid, gifts, plan
switching with proration, and named dunning sequences.

The `getEffective*` gates read the **edition**, never a stored setting, so a downgraded install
cannot obey a Pro configuration it can no longer edit — a box plan whose slots are unreachable would
ship whatever it liked. `getHasSuppressedProSettings()` surfaces that in the CP rather than leaving
it to a support ticket.

## Traps found while building this

- **`Order::recalculate()` calls `LineItem::refresh()`**, which restores the catalogue price. So
  setting a line item's price and *then* recalculating silently throws the price away — a prepaid
  cycle arrives as a full bill and a proration charges a whole month. Both places set
  `RECALCULATION_MODE_ADJUSTMENTS_ONLY` first, which still runs tax and shipping.
- **`Order::setPaymentSource()` throws if the source is not owned by the order's customer**, so the
  customer has to be set *first*. Setting them the other way round throws on every renewal of every
  subscription.
- **Commerce picks authorise-vs-purchase from the gateway's own `paymentType`**, and a subscription
  that is only ever authorised is never actually paid. `Billing::_shouldCapture()` captures the
  authorisation immediately.
- **An off-session charge that comes back wanting a redirect has not failed** — it has asked for
  something a cron job cannot provide. That is a distinct outcome (`Attempt::REDIRECT`) that turns
  the cycle into a manual one rather than counting against the retry budget.
- **`+1 month` from 31 January in PHP is 3 March**, which walks a subscriber's billing day forward
  every short month until it settles on the 3rd. `Cadence::_addMonths()` clamps to the last day of
  the target month. Anchors are 1–28 only, and 29–31 are *rejected* rather than clamped, because a
  plan anchored to the 31st silently means "the 28th" for one month in four.
- **Advancing a `DateTime` by `P1M` across a DST boundary shifts the wall clock by an hour.**
  `Cadence::next()` advances the date part and reapplies the time of day.
- **A custom line item (`LineItemType::Custom`) arrives with no tax or shipping category**, and both
  columns are `NOT NULL` — the insert fails with an integrity violation several frames from the
  cause. Subscribr does not use custom line items at all: the proration charge re-prices the
  subscription's own purchasable, which is also the correct answer for tax.
  (In the plugin-testing harness, completing an order that contains a custom line item fails
  outright — reproducible with Subscribr disabled, so it is something else in that install.)
- **`getIsCpRequest()` does not exist on a console application** — asking is a fatal error, not a
  false. `Ledger::currentSource()` checks `getIsConsoleRequest()` first.
- **Yii skips a conditional validator when the attribute is empty** (`skipOnEmpty` defaults true),
  which is exactly when "a fixed-price box needs a price" has something to say. Pass
  `'skipOnEmpty' => false`.
- **PHP identifiers may contain bytes 0x80–0xFF**, so `"Box “$name” saved."` parses as a variable
  called `name”` and fatals at *runtime*, not at lint time. There is a check in the suite for this.
- **A blank number field means "not set", which is not zero** — on a plan price, zero means free and
  null means "use the product's price". The controllers have `_floatOrNull()` for this.
- **An editable table with spare blank rows posts them.** The dunning stage rows default to
  `retry`, so a save would grow the sequence by two every time. Blank rows post an empty `action`
  and the controller drops them.
- **A bare console script buffers project-config writes.** Switching the edition without
  `saveModifiedConfigData()` leaves the web request reading the old edition — and the next apply
  undoes it. See `[[craft-project-config-script-wipe]]`.

See also `[[craft-plugin-gotchas]]` and `[[craft-commerce-shipping-gotchas]]` in the shared memory.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-subscribr/tests/integration/checks.php   # 130 checks
docker exec ddev-plugin-testing-web bash -c 'find /var/www/craft-subscribr/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

Idempotent and self-cleaning — every user, product, plan, box, profile, subscription and order it
creates is removed in a `finally`, pass or fail.

**Fixture dates are relative, not fixed.** `$anchorStart` is always a month and a day ago, so the
first renewal is always due yesterday. A hard-coded date drifts further into the past every day the
suite is not run and eventually trips the catch-up guard — correct behaviour and a useless failure.

The edition and settings are changed **in memory** rather than saved: project config is contended in
this harness and a console script that writes it races the queue runner.

## Coding conventions

- `Craft::t('subscribr', '…')` for user-facing strings; `src/translations/en/subscribr.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template
- Never mark plugin settings `required`
- A declined card is **not** an exception. Renewal outcomes come back as a `RenewalResult`, because
  a sweep over five hundred subscriptions must not end on the first bad card. Only conditions that
  mean the *caller* is wrong throw.
- Sending email never throws. A mail server that is down must not undo a payment that has been taken.
- Every portal action re-resolves the subscription scoped to the signed-in user and re-checks the
  permission its button was rendered from. The Twig `can()` map decides what is shown; the
  controller decides what is allowed, independently.
