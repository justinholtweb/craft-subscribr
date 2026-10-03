---
title: Usage
slug: usage
order: 30
summary: Selling a subscription from an ordinary cart, the portal, proration, and the console.
---

## Selling one

A recurring line is an ordinary Commerce line item carrying one option key. There is no second cart
and no separate checkout:

```twig
<form method="post">
    {{ csrfInput() }}
    {{ actionInput('commerce/cart/update-cart') }}
    {{ hiddenInput('purchasables[0][id]', variant.id) }}

    {% for key, value in craft.subscribr.options('monthly-coffee') %}
        {{ hiddenInput('purchasables[0][options][' ~ key ~ ']', value) }}
    {% endfor %}

    <button>Subscribe — {{ plan.describe }}</button>
</form>
```

`options()` takes extras for a prepaid term or a gift:

```twig
{% set opts = craft.subscribr.options('monthly-coffee', { subscribrPrepaid: 6 }) %}
```

Commerce de-duplicates line items on the purchasable ID **plus a hash of their options**, so the
same bag of coffee bought once and the same bag bought monthly are automatically two separate lines
at two different quantities. Subscribr does not touch the cart machinery at all.

Show the customer what they are committing to:

```twig
{% for group in craft.subscribr.cartSummary() %}
    <p>Then {{ group.amount|commerceCurrency(cart.currency) }} {{ group.cadence }}.</p>
{% endfor %}
```

Anything that would stop the order becoming a subscription — no stored card on a gateway that needs
one, a box with an incomplete selection — comes back from `craft.subscribr.cartProblems()` so you
can say so before checkout rather than after.

When the order completes, every recurring line becomes a subscription and that order becomes its
signup order.

## What a renewal is

A real `craft\commerce\elements\Order`. Not a record of one held somewhere else.

That single decision is what gives renewals your tax, your shipping, your discounts and coupons,
order statuses, emails, PDFs, the order index, your reporting, refunds, and every Commerce plugin
the store already runs — none of it reimplemented, and so none of it subtly wrong.

Money only ever moves through `Billing::charge()`, which goes through Commerce's own `Payments`
service and a stored `PaymentSource`.

## The portal

`craft.subscribr.can(subscription)` returns what this subscriber may do to this subscription right
now — the plan's rules, the edition and the change window all resolved — so a button is never
rendered for something the controller would then refuse:

```twig
{% set can = craft.subscribr.can(subscription) %}

{% if can.skip %}
    <form method="post">
        {{ csrfInput() }}
        {{ actionInput('subscribr/portal/skip') }}
        {{ hiddenInput('subscription', subscription.reference) }}
        <button>Skip my next order</button>
    </form>
{% endif %}
```

The controller re-resolves the subscription against the signed-in user and re-checks the permission
independently, so a hand-crafted POST gets nowhere.

Working templates for the whole portal ship in `src/templates/portal/`. Copy them into
`templates/subscribr/portal/` and yours take precedence.

## Booked, not applied

Skips, pauses, swaps, plan switches, quantity and address changes are **bookings** — rows consumed
at the next cycle boundary, not changes applied on the spot.

That is what makes them reversible: the subscriber changes their mind by cancelling the booking.
It also gives the engine one place to ask what this cycle is supposed to be.

A booking either **replaces** the renewal (skip, pause, cancel) or **changes** it (swap, switch,
quantity, address). `craft.subscribr.pendingActions(subscription)` lists what is booked.

A swap is cycle-pinned: the swapped items replace the standing ones for that cycle only, and are
deleted once it renews, so the month after goes back to normal without anybody putting it back.

## Proration, shown before it is charged

```twig
{% set quote = craft.subscribr.proration(subscription, 'monthly-plus') %}

<ul>
    {% for line in quote.lines %}
        <li>{{ line.label }} <strong>{{ quote.formatAmount(line.amount) }}</strong>
            {% if line.detail %}<small>{{ line.detail }}</small>{% endif %}</li>
    {% endfor %}
</ul>

<p>{{ quote.formatAmount(quote.netDue) }} today, then
   {{ quote.formatAmount(quote.newCycleAmount) }} {{ quote.newPlan.describe }}.</p>
```

The preview, the confirmation and the charge are the same object, so they cannot disagree. A
downgrade produces a credit, which is carried to the next renewal rather than refunded to a card
nobody authorised a refund to — and the preview says so.

## History

Every state change is written to one ledger. The control-panel timeline and the subscriber's portal
read the same rows; there is no second, gentler history for the customer.

```twig
{% for entry in craft.subscribr.history(subscription) %}
    <li>{{ entry.dateCreated|date('j M Y') }} — {{ entry.message }}</li>
{% endfor %}
```

## Querying

Subscriptions are elements:

```twig
{% set ending = craft.subscribr.subscriptions({
    subscriptionStatus: 'canceled',
    orderBy: 'dateEnds asc',
    limit: 20,
}).all() %}
```

`craft.subscribr.mine()` returns the signed-in user's, live ones by default.

## Console

| Command | What it does |
|---|---|
| `subscribr/renewals/run` | Renew everything due. `--queue` fans out to Craft's queue, one job per subscription. |
| `subscribr/renewals/due` | What a run *would* charge, and how. Takes no money. |
| `subscribr/renewals/one <ref>` | Renew one subscription now, due or not. |
| `subscribr/dunning/run` | Take the next dunning step for everything whose retry is due. |
| `subscribr/dunning/report` | Money at risk and the recovery rate. |
| `subscribr/gateways` | What each gateway can do, and why. |
| `subscribr/plans/list\|export\|import` | Move plans between environments by handle. |
| `subscribr/gifts/deliver\|unclaimed` | Send scheduled gifts; list ones nobody has claimed. |

## Events

```php
use justinholtweb\subscribr\services\Renewals;
use justinholtweb\subscribr\events\RenewalEvent;

Event::on(Renewals::class, Renewals::EVENT_AFTER_RENEW, function (RenewalEvent $e) {
    // $e->subscription, $e->result->order
});
```

Also `EVENT_BEFORE_RENEW`, `Proration::EVENT_AFTER_PRORATE`, and on `Subscriptions`:
`EVENT_BEFORE_ACTIVATE`, `EVENT_AFTER_ACTIVATE`, `EVENT_AFTER_PAUSE`, `EVENT_AFTER_RESUME`,
`EVENT_AFTER_CANCEL` and `EVENT_AFTER_EXPIRE`.
