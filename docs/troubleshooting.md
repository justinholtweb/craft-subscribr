---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Nothing renewed, a cycle that was abandoned, declines, and renewals that ask for a redirect.
---

## Nothing renewed

In order:

1. **Is the sweep running?** `php craft subscribr/renewals/run` by hand. If that renews things, the
   problem is cron, not Subscribr.
2. **Is anything actually due?** `php craft subscribr/renewals/due` lists what a run would charge.
   It takes no money.
3. **Is `autoRenew` on?** Settings → Subscribr. With it off the sweep charges nothing at all.
4. **Can the gateway take the payment?** `php craft subscribr/gateways`. A gateway that cannot store
   a payment method raises invoices instead — those renewals are waiting for the subscriber, not
   broken.

## "Abandoned" in the ledger

The catch-up guard. A renewal more than `renewalCatchUpDays` past its due date is not charged;
the schedule is moved forward and a note is written to the ledger saying how many cycles were
skipped.

This is deliberate. If the queue has been off for two months, charging everybody twice on the
morning it comes back is worse than missing the cycles. Raise `renewalCatchUpDays`, or set
`billMissedCycles` to `true`, if you genuinely want the gap billed.

## A renewal came back wanting a redirect

It has not failed — it has asked for something a cron job cannot provide, usually 3-D Secure.

That outcome turns the cycle into a **manual** one: the order is raised and the subscriber is
emailed a link to pay it. It does not count against the retry budget, because there is nothing
wrong with the card.

## The subscriber says they were charged the wrong amount

Look at the subscription's **Orders** pane. Every cycle is a real order, so the line items, the
adjustments, the tax and the shipping are all on it and all inspectable.

Two things commonly surprise people:

- **A prepaid cycle arrives at zero**, because it was paid for up front. The draw-down is in the
  ledger.
- **The first order is not the recurring price.** Trials, joining fees and prepayment are applied as
  *adjustments*, so the customer still sees the thing they signed up for at its real price, with the
  difference shown separately.

## A declined card is not retrying

Check the plan's dunning sequence, and that `enableDunning` is on. Then check whether the decline
was **hard** — a card reported lost or stolen skips straight to the end of the sequence rather than
retrying, because retrying a stolen card three more times is how a merchant account gets reviewed.

The subscription's **Payment attempts** pane shows the gateway's own code for each failure.

When a subscriber fixes their card, the next retry is brought forward to now rather than waiting for
the next scheduled stage — it is the single most valuable event in dunning.

## Billing day drifting

It should not. If it is:

- Check the plan's **billing day**. Monthly anchors must be 1–28; 29–31 are rejected on save.
- The next date is computed from the date that *was* due, never from `now`, so a late sweep does not
  move anybody.

If a subscriber's date really has moved, the ledger says when and why — every renewal entry records
the date it set.

## An unpaid renewal disappeared

It should not, and there is a guard against exactly this: Commerce purges inactive carts on a
schedule, and a manual renewal is an incomplete order, which is a cart. Subscribr excludes anything
in its own orders table from that purge.

If you have written your own purge, exclude those order IDs too — otherwise it deletes an unpaid
invoice and takes the record of the debt with it.

## Emails are not arriving

Sending email never throws in Subscribr: a mail server that is down must not undo a payment that has
already been taken. So a failed send is logged rather than surfaced.

Check `sendEmails`, then the Craft log, then your mailer settings with Craft's own test email.

## A Pro feature stopped working

The `getEffective*` gates read the **edition**, not a stored setting, so an install downgraded to
Lite cannot keep obeying a Pro configuration it can no longer edit — a box plan whose slots were
unreachable would otherwise ship whatever it liked.

The plugin settings screen says so when this has happened, rather than leaving it to a support
ticket.
