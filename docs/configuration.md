---
title: Configuration
slug: configuration
order: 20
summary: Plan settings, cadences and anchors, boxes, dunning sequences and the plugin settings screen.
---

## Plans

A plan is the arrangement: how often, at what price, and what the subscriber is allowed to do to it.

### Cadence

An interval (day, week, month, year) and a count. "Every 2 weeks" is `week` × 2.

**Billing day** anchors the cycle to a date rather than to the signup anniversary — the 3rd of the
month, or Monday for a weekly plan. Leave it empty and each subscriber is billed on the anniversary
of their own signup.

Monthly anchors are **1–28 only**. 29, 30 and 31 are rejected rather than clamped, because a plan
anchored to the 31st quietly means "the 28th" one month in four, and nobody sets that deliberately.
Month arithmetic is clamped to the last day of the target month, so 31 January plus one month is
28 February — not 3 March, which is what PHP would otherwise give you and which walks a subscriber's
billing day forward every short month until it settles somewhere else entirely.

### Price

| Mode | What renews at |
|---|---|
| Inherit | Whatever the product costs at renewal time |
| Locked | Whatever it cost when they signed up |
| Override | A fixed plan price, whatever the product costs |

Plus an optional **subscriber discount** (a percentage off every cycle) and a **joining fee**
(charged once, on the first order).

A blank price field means *not set*, which is not the same as zero. Zero on a plan price means free;
empty means "use the product's price".

### Trials and fixed terms

**Free trial** defers the first payment by a number of days. **Number of cycles** turns the plan
into a fixed term that ends by itself — zero renews until the subscriber cancels.

### What subscribers may do

Pause, cancel, change the quantity, and — on Pro — skip a cycle, swap what is in a shipment, change
plan, and buy as a gift. Each is a switch on the plan, and each is checked again in the controller
when the subscriber actually does it.

**When they cancel** decides whether the subscription runs to the end of the period they have paid
for or ends immediately. The first is the default, because they have paid for it.

### Prepay

**Prepay options** is a list of cycle counts a subscriber may pay for up front — `3,6,12` — with an
optional discount percentage. One cycle is not a prepayment, so anything under two is dropped.

## Boxes (Pro)

A box is what ships each cycle when the plan's contents are chosen rather than fixed.

- **Curated** — you decide what is in it
- **Choice** — the subscriber picks, within slots
- **Surprise** — drawn from the sources you allow

Each slot has a name, a minimum and a maximum, and a set of purchasables it will accept. A slot with
no sources accepts anything, so a half-configured box is not silently unfulfillable.

**Lock hours** is how long before a renewal the contents freeze, so your pick-and-pack has a settled
list. Pricing is the sum of the contents, a fixed box price, or a base price plus extras.

## Dunning

A sequence of stages, each an offset in hours from the **first** failure and an action: retry, email,
pause, cancel, expire, or notify staff. Stages are sorted on the way in, so a retry booked for hour
24 cannot end up firing after one booked for hour 72.

The store has a default sequence, used by any plan without one of its own; on Pro a plan can point
at a named sequence. A store that has never opened the dunning screen still has a working sequence —
without one, a declined subscription would sit past due for ever.

A **hard** decline (a lost or stolen card) skips straight to the end rather than retrying three more
times. A soft decline (insufficient funds) retries. Anything unrecognised is treated as retryable,
because failing to retry a recoverable payment loses a customer, and that is worse than one wasted
gateway call.

## Plugin settings

**Settings → Subscribr.**

| Setting | Default | What it does |
|---|---|---|
| `autoRenew` | `true` | Whether the sweep charges anything at all |
| `renewalBatchSize` | `50` | Subscriptions per sweep |
| `renewalLeadHours` | `0` | Treat a renewal as due this many hours early |
| `renewalCatchUpDays` | `7` | Older than this and the cycle is abandoned, not billed |
| `billMissedCycles` | `false` | Whether a gap bills every missed cycle or just one |
| `captureAuthorizedRenewals` | `true` | Capture an authorisation immediately |
| `renewalOrderStatus` | — | Order status for a successful renewal |
| `pastDueOrderStatus` | — | Order status for a renewal that failed |
| `enablePortal` | `true` | Serve the subscriber portal |
| `portalPath` | `account/subscriptions` | Where it lives |
| `changeLockHours` | `12` | How close to a renewal self-service stops |
| `offerPauseOnCancel` | `true` | Offer a pause before accepting a cancellation |
| `enableDunning` | `true` | Run the retry sequences |
| `sendEmails` | `true` | Send subscriber email at all |

`renewalCatchUpDays` is the guard that matters most. If your queue has been off for two months, you
almost certainly do not want every subscriber charged twice on the morning it comes back. The
abandoned cycles are written to the ledger rather than silently dropped.

`captureAuthorizedRenewals` exists because Commerce picks authorise-versus-purchase from the
gateway's own payment type, and a subscription that is only ever authorised is never actually paid.

## Moving plans between environments

Plans live in the database, not project config — a plan can point at a box, and a box points at
purchasables whose IDs mean nothing in another environment. Move them by handle instead:

```sh
php craft subscribr/plans/export > plans.json
php craft subscribr/plans/import plans.json
```
