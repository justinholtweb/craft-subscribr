<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\adjusters;

use Craft;
use craft\base\Component;
use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\models\OrderAdjustment;
use justinholtweb\subscribr\helpers\Money;
use justinholtweb\subscribr\models\Plan;
use justinholtweb\subscribr\Plugin;

/**
 * What a subscription costs **today**, as distinct from what it costs every month.
 *
 * Three things change the first payment and none of them change the recurring one, which is
 * exactly why they are adjustments on the signup order rather than a different line price:
 *
 * - **A trial** takes the recurring lines off today's total. The customer still sees what they
 *   have signed up for, at its real price, with the trial credited underneath — a £0.00 line item
 *   tells them nothing about what happens in fourteen days.
 * - **A signup fee** is added once. Charged per plan, not per line: joining a coffee club with
 *   three coffees in the basket is joining once.
 * - **Prepayment** multiplies the recurring lines by the remaining cycles, less the prepaid
 *   discount. Shown as one line — "5 further months, 10% off" — because a customer checking a
 *   £312 total wants to see where it came from.
 *
 * Ordinary one-off items in the same cart are not touched by any of this. That is what makes a
 * mixed cart safe: the adjuster only ever looks at lines that carry a plan handle.
 */
class SubscriptionAdjuster extends Component implements AdjusterInterface
{
    public const ADJUSTMENT_TYPE = 'subscribr';

    public function adjust(Order $order): array
    {
        $carts = Plugin::getInstance()->getCarts();
        $lineItems = $carts->getRecurringLineItems($order);

        if ($lineItems === []) {
            return [];
        }

        $adjustments = [];
        $byPlan = [];

        foreach ($lineItems as $lineItem) {
            $plan = $carts->getPlanForLineItem($lineItem);

            if ($plan !== null) {
                $byPlan[$plan->id]['plan'] = $plan;
                $byPlan[$plan->id]['lineItems'][] = $lineItem;
            }
        }

        foreach ($byPlan as $group) {
            /** @var Plan $plan */
            $plan = $group['plan'];
            /** @var LineItem[] $planLineItems */
            $planLineItems = $group['lineItems'];

            $subtotal = 0.0;

            foreach ($planLineItems as $lineItem) {
                $subtotal += (float)$lineItem->getSubtotal();
            }

            if ($plan->getHasTrial()) {
                $adjustments[] = $this->_adjustment(
                    $order,
                    Craft::t('subscribr', '{n}-day free trial', ['n' => $plan->trialDays]),
                    Craft::t('subscribr', 'You won’t be charged for {plan} until {date}.', [
                        'plan' => $plan->name,
                        'date' => (new \DateTime())->modify('+' . $plan->trialDays . ' days')->format('j M Y'),
                    ]),
                    -Money::round($subtotal),
                );
            } elseif ($prepaid = $this->_prepaidCycles($planLineItems)) {
                // One cycle is already in the line items; the prepayment buys the rest.
                $extra = $plan->prepaidMultiplier($prepaid) - 1;

                if ($extra > 0) {
                    $adjustments[] = $this->_adjustment(
                        $order,
                        Craft::t('subscribr', '{n} further {n, plural, =1{cycle} other{cycles}} paid up front', ['n' => $prepaid - 1]),
                        $plan->prepaidDiscountPercent
                            ? Craft::t('subscribr', '{plan}, with {pct}% off for paying up front.', [
                                'plan' => $plan->name,
                                'pct' => rtrim(rtrim(number_format($plan->prepaidDiscountPercent, 2), '0'), '.'),
                            ])
                            : $plan->name,
                        Money::round($subtotal * $extra),
                    );
                }
            }

            if ($plan->signupFee) {
                $adjustments[] = $this->_adjustment(
                    $order,
                    Craft::t('subscribr', 'Joining fee'),
                    $plan->name,
                    Money::round((float)$plan->signupFee),
                );
            }
        }

        return $adjustments;
    }

    /**
     * The prepaid cycle count, taken from the lines rather than trusted.
     *
     * The option is user input on a cart — it arrives from a form and nothing between there and
     * here has checked it against the plan. A count the plan does not offer is ignored rather than
     * honoured, so a hand-edited request cannot buy twelve months at the three-month discount.
     */
    private function _prepaidCycles(array $lineItems): int
    {
        $carts = Plugin::getInstance()->getCarts();

        foreach ($lineItems as $lineItem) {
            $plan = $carts->getPlanForLineItem($lineItem);
            $requested = (int)($lineItem->getOptions()[\justinholtweb\subscribr\services\Carts::OPTION_PREPAID] ?? 0);

            if ($plan !== null && in_array($requested, $plan->getPrepaidCycleOptions(), true)) {
                return $requested;
            }
        }

        return 0;
    }

    private function _adjustment(Order $order, string $name, string $description, float $amount): OrderAdjustment
    {
        $adjustment = new OrderAdjustment();
        $adjustment->type = self::ADJUSTMENT_TYPE;
        $adjustment->name = $name;
        $adjustment->description = $description;
        $adjustment->amount = $amount;
        $adjustment->setOrder($order);

        return $adjustment;
    }
}
