# Subscribr

Recurring commerce for Craft — subscription boxes, skip/pause/swap, prepaid and gift plans, mixed
carts, proration and dunning, **on any gateway that can store a payment method**.

Craft Commerce ships with subscriptions, and they are thin: a local mirror of something Stripe is
holding. The plan lives at the gateway, the schedule lives at the gateway, the retries live at the
gateway, and a subscription cannot go in a cart. Subscribr does the opposite — the store owns the
arrangement, and the gateway is asked one question, at renewal time: *take this much money off this
stored card.*

That single change is what makes everything else possible.

| | Craft Commerce | Subscribr |
|---|---|---|
| Gateways | Stripe (anything implementing `SubscriptionGatewayInterface`) | Any gateway with stored payment methods; the rest renew by invoice |
| Subscription boxes / build-a-box | — | ✅ |
| Skip, pause, swap a shipment | — | ✅ |
| Prepaid plans | — | ✅ |
| Gift subscriptions | — | ✅ |
| One-off and recurring in one cart | — | ✅ |
| Proration, previewed before it is charged | — | ✅ |
| Dunning in the control panel | — | ✅ |
| Renewal orders | none — the gateway invoices | real Commerce orders, with your tax, shipping, discounts and reports |

## Editions

| | Lite | Pro |
|---|---|---|
| **Price** | **$99**, $79/year renewal | **$199**, $159/year renewal |
| Recurring plans on any gateway | ✅ | ✅ |
| Renewals as real Commerce orders | ✅ | ✅ |
| Mixed carts — one-off and recurring together | ✅ | ✅ |
| Trials and joining fees | ✅ | ✅ |
| Pause, resume, cancel, reinstate | ✅ | ✅ |
| Dunning, retries and the dashboard | ✅ | ✅ |
| Subscriber portal and emails | ✅ | ✅ |
| Manual renewal for gateways with no stored cards | ✅ | ✅ |
| Subscription boxes and build-a-box | | ✅ |
| Skip a cycle, swap a shipment | | ✅ |
| Prepaid plans | | ✅ |
| Gift subscriptions | | ✅ |
| Plan switching with proration | | ✅ |
| Named dunning sequences per plan | | ✅ |

Dunning is deliberately in **Lite**. A free-tier store whose cards decline still needs its money,
and a store that cancels on the first failed payment is throwing away customers who would happily
have paid.

## Requirements

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.

## Installation

```sh
composer require justinholtweb/craft-subscribr
php craft plugin/install subscribr
```

Then set up cron:

```cron
*/5 * * * *  php craft subscribr/renewals/run
0    * * * *  php craft subscribr/dunning/run
0    8 * * *  php craft subscribr/gifts/deliver
```

## Will it work with my payment provider?

Ask it:

```sh
php craft subscribr/gateways
```

```
GATEWAY                  RENEWS       SOURCES  PURCHASE   NOTE
Stripe                   automatic    yes      yes        also a Commerce subscription gateway
Braintree                automatic    yes      yes
Bank transfer            manual       no       yes

  Bank transfer: This gateway cannot store a payment method, so renewals are raised as unpaid
  orders and the subscriber is emailed a link to pay them.
```

Subscribr does not ask whether a gateway "supports subscriptions". It asks whether it can charge a
stored token with nobody present — `supportsPaymentSources()` plus `supportsPurchase()` — which is
true of very nearly every gateway written for Commerce. Gateways where it is false are not shut
out: they renew *manually*, by raising the order and emailing a payment link that drops the
customer into your own checkout.

## Selling a subscription

A recurring line is an ordinary line item with a plan handle in its options. That is the whole
mechanism, and it is why one-off and recurring items sit happily in the same basket:

```twig
<form method="post">
    {{ csrfInput() }}
    {{ actionInput('commerce/cart/update-cart') }}
    {{ hiddenInput('purchasables[0][id]', variant.id) }}

    {% for key, value in craft.subscribr.options('monthly-coffee', { subscribrPrepaid: 6 }) %}
        {{ hiddenInput('purchasables[0][options][' ~ key ~ ']', value) }}
    {% endfor %}

    <button>Subscribe — {{ plan.describe }}</button>
</form>
```

Commerce de-duplicates line items on a hash of their options, so the same bag of coffee bought once
and bought monthly are automatically two separate lines at two different quantities. Subscribr does
not touch the cart machinery at all.

Show the customer what they are committing to:

```twig
{% for group in craft.subscribr.cartSummary() %}
    <p>Then {{ group.amount|commerceCurrency(cart.currency) }} {{ group.cadence }}.</p>
{% endfor %}
```

When the order completes, every recurring line becomes a subscription and the order becomes its
signup order.

## The subscriber portal

`craft.subscribr.can(subscription)` returns what this subscriber may do to this subscription right
now — the plan's rules, the edition, and the change window, all resolved. Render buttons from it and
a button is never shown for something the controller would refuse:

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

Working templates for the whole portal ship in `src/templates/portal/`. Copy them into
`templates/subscribr/portal/` and yours take precedence.

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
without anybody authorising it — and the preview says so.

## Dunning

A failed renewal is usually an expired card. Subscribr retries on a schedule you set, tells the
subscriber, and ends the subscription only when the schedule runs out. The control panel shows the
money currently at risk and how much of it is coming back:

```sh
php craft subscribr/dunning/report
```

```
Dunning, last 30 days
  Past due          11 subscriptions
  At risk           4,218.00
  Failed attempts   34
  Recovered         26
  Recovery rate     76.5%
```

Recovery is counted per subscription, not per attempt — three retries against one card is one
customer, not three.

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

## Support

<justin@justinholt.com>
