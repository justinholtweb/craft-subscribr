---
title: Installation
slug: installation
order: 10
summary: Requirements, install, cron, and finding out whether your gateway can carry a subscription.
---

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later
- A payment gateway that can store a payment method — or one that can't, see below

## Install

```sh
composer require justinholtweb/craft-subscribr
php craft plugin/install subscribr
```

## Will it work with my payment provider?

Ask it before you commit to anything:

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

Subscribr never asks whether a gateway "supports subscriptions". It asks whether it can charge a
stored token with nobody present — `supportsPaymentSources()` plus `supportsPurchase()` — which is
true of very nearly every gateway written for Commerce.

A gateway where that is false is not shut out. It renews **manually**: the renewal is raised as an
incomplete order and the subscriber gets a link that drops it into your own checkout. The same
screen is in the control panel at **Subscribr → Gateways**.

## Cron

Nothing renews until something sweeps. Three jobs:

```cron
*/5 * * * *  php craft subscribr/renewals/run
0    * * * *  php craft subscribr/dunning/run
0    8 * * *  php craft subscribr/gifts/deliver
```

`renewals/run` takes `--queue`, which fans out to Craft's queue with one job per subscription. Use
it once you are past a few hundred subscribers, so one slow gateway call cannot hold up the sweep.

The schedule does not drift if a sweep runs late. The next payment date is computed from the date
that **was** due, never from the moment the sweep happened to run — a queue that was busy until
03:07 does not push everybody's billing date seven minutes later every month.

Before you wire up cron, see what a run would do:

```sh
php craft subscribr/renewals/due
```

That takes no money. It lists what is due, what each one would be charged, and whether it would
renew automatically or be invoiced.

## Your first plan

1. **Subscribr → Plans → New plan.** A name, a handle, and a cadence is enough to start.
2. Pick a **recurring price** mode. "Locked" is what a subscriber means by *price for life*;
   "inherit" means a catalogue price rise reaches existing subscribers at their next renewal.
3. Add the plan to a product page as an option key on an ordinary add-to-cart form — see
   [Usage](usage).

## Permissions

Five, under **Subscribr** in the user group settings:

| Permission | Allows |
|---|---|
| `subscribr-viewSubscriptions` | See the subscriptions index and any subscription |
| `subscribr-manageSubscriptions` | Pause, resume, cancel, skip, change plan, edit a subscription |
| `subscribr-billSubscriptions` | Take a payment: "renew now", retry a declined card |
| `subscribr-managePlans` | Create and edit plans and boxes |
| `subscribr-manageDunning` | Create and edit dunning sequences |

The last two are separate from the first three on purpose: the person who handles a customer's
billing question is usually not the person who should be able to change what every plan costs.
