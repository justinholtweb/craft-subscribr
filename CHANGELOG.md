# Release Notes for Subscribr

## 5.0.0

Initial release.

### Added

- **Subscriptions on any gateway.** Renewals go through Commerce's own `Payments::processPayment()`
  against a stored `PaymentSource`, so any gateway that can charge a saved card off-session can
  carry a subscription. Gateways that cannot store a card renew by invoice and a payment link that
  loads into the store's own checkout.
- **Renewals as real Commerce orders**, so tax, shipping, discounts, coupons, order statuses,
  emails, PDFs, reporting and refunds all work without being reimplemented.
- **Mixed carts** — one-off products and subscriptions checked out together, with a summary of what
  the basket commits the customer to after today.
- **Subscription boxes**, in three modes: curated, build-a-box, and surprise with repeat avoidance.
  Slot rules, add-on slots, three pricing models, and a pick-and-pack content lock.
- **Skip, pause, resume, swap a shipment** — self-service, booked rather than applied, so a
  subscriber can change their mind. A pause stops the clock rather than accruing behind it.
- **Prepaid plans** and **gift subscriptions**, with claim tokens, scheduled delivery, and a gift
  that ends rather than quietly renewing against the giver's card.
- **Proration** with a preview the subscriber sees before agreeing to it, computed by the same code
  that takes the charge. Downgrade credit is carried, not silently refunded.
- **Dunning** in the control panel: named sequences per plan, retry schedules measured from the
  first failure, permanent declines skipped straight to the end, and a dashboard showing money at
  risk and the real recovery rate.
- Subscriber portal, templated emails, a dashboard widget with normalised MRR, an element index with
  status sources and bulk actions, a subscriptions panel on the user's edit screen, and console
  commands for renewals, dunning, gateways, plans and gifts.
