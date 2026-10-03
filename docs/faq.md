---
title: FAQ
slug: faq
order: 50
summary: Common questions about running subscriptions in Craft Commerce without Stripe.
---

## How is this different from Commerce's own subscriptions?

Commerce's subscriptions are a local mirror of something the gateway is holding. The plan lives at
the gateway, the schedule lives at the gateway, the retries live at the gateway, and a subscription
cannot go in a cart.

Subscribr does the opposite: the store owns the arrangement, and the gateway is asked one question,
at renewal time — *take this much money off this stored card*. Everything else follows from that.

## Does it really work without Stripe?

Yes. `craft\commerce\elements\Subscription` is welded to `SubscriptionGatewayInterface`, which in
practice means Stripe. Subscribr does not use that element or that interface. It has its own
subscription element and asks a smaller question: can this gateway charge a stored token with nobody
present?

Run `php craft subscribr/gateways` before you buy anything and it will tell you what yours can do.

## What if my gateway can't store a card?

It still carries subscriptions. The renewal is raised as an **incomplete** order and the subscriber
is emailed a link.

Incomplete because an incomplete order is a cart, and a cart has `getLoadCartUrl()` — which drops
the subscriber into your own checkout with the renewal already in it. There is no bespoke payment
page to build, and nothing to fix when you change gateway.

## Can a customer buy a subscription and a one-off thing together?

Yes, in the same cart, in one checkout. A recurring line is an ordinary line item carrying an option
key. Commerce already de-duplicates line items on a hash of their options, so the same product
bought once and subscribed to monthly are automatically two separate lines.

## Do renewals show up in my reports?

Yes — a renewal *is* a Craft Commerce order. It appears in the order index, in Commerce's reporting,
in your order status emails and PDFs, and in whatever other Commerce plugins you run.

## Can I use my existing tax and shipping setup?

Yes, for the same reason. Renewal orders go through Commerce's own recalculation, so your tax rules,
shipping methods, discounts and coupons apply exactly as they do to any other order.

## What happens if the cron job doesn't run for a week?

Nothing is lost and nobody is double-billed. Renewals more than `renewalCatchUpDays` (7 by default)
past due are abandoned rather than charged, and the skipped cycles are written to the ledger. The
schedule moves forward from the date that was due, so nobody's billing day shifts.

## Can subscribers skip a month?

On Pro, yes — and pause, swap what is in a shipment, change plan, change the quantity, and buy a
subscription as a gift. Each is a switch on the plan, so you decide which of them your store offers.

Skips and swaps are booked for the next cycle rather than applied immediately, which is what makes
them reversible.

## Is dunning a Pro feature?

No. Dunning is in **Lite**, deliberately. A store whose cards decline still needs its money, and a
store that cancels on the first failed payment is throwing away customers who would happily have
paid. Pro adds *named* sequences, so different plans can be chased differently.

## What's in Lite and what's in Pro?

Lite is a complete, working subscription plugin: plans, renewals on any gateway, mixed carts, trials
and joining fees, pause, cancel and reinstate, dunning, the subscriber portal, emails, and manual
renewal for gateways that cannot store a card.

Pro adds subscription boxes and build-a-box, skip, swap, prepaid plans, gift subscriptions, plan
switching with proration, and named dunning sequences per plan.

## What happens if I downgrade from Pro to Lite?

The Pro features stop, and they stop honestly. The feature gates read the edition rather than a
stored setting, so an install that can no longer edit a Pro configuration does not keep obeying one —
a box plan whose slots had become unreachable would otherwise ship whatever it liked. The settings
screen tells you which of your settings have been suppressed.

## Can I move plans between environments?

Yes, by handle:

```sh
php craft subscribr/plans/export > plans.json
php craft subscribr/plans/import plans.json
```

Plans are in the database rather than project config — the same call Commerce makes for its own
plans, and for the same reason: a plan can point at a box, and a box points at purchasables whose
IDs mean nothing in another environment.

## Do I have to build the subscriber portal myself?

No. Working templates for the whole portal ship with the plugin. Copy them into
`templates/subscribr/portal/` and your versions take precedence, so you can restyle as much or as
little as you want.

## Is there a trial?

The Craft Plugin Store gives every commercial plugin a trial in development environments — install
it and run it against your own gateway before you buy.
