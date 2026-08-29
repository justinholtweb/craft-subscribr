<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\helpers;

use Craft;
use craft\commerce\Plugin as Commerce;

/**
 * Currency formatting, in one place.
 *
 * `Store::getCurrency()` returns a `Money\Currency` object, not an ISO string, and handing that to
 * `Formatter::asCurrency()` throws "The $textOptions array values must be strings" — from inside
 * intl, several frames away from the mistake. Everything here takes and returns strings.
 */
abstract class Money
{
    /**
     * The store's primary currency as an ISO code.
     */
    public static function storeCurrency(?int $storeId = null): string
    {
        $commerce = Commerce::getInstance();

        if (!$commerce) {
            return 'USD';
        }

        try {
            return $commerce->getPaymentCurrencies()->getPrimaryPaymentCurrencyIso($storeId);
        } catch (\Throwable) {
            return 'USD';
        }
    }

    public static function format(float $amount, ?string $currency = null): string
    {
        $currency = $currency ?: self::storeCurrency();

        try {
            return Craft::$app->getFormatter()->asCurrency($amount, $currency);
        } catch (\Throwable) {
            return $currency . ' ' . number_format($amount, 2);
        }
    }

    /**
     * Round to the currency's own precision.
     *
     * Prorating a monthly price across 31 days produces numbers with six decimal places, and
     * summing those before rounding is how a total ends up a penny away from the sum of the lines
     * that are shown to the customer. Round at the line, then add.
     */
    public static function round(float $amount, int $precision = 2): float
    {
        return round($amount, $precision);
    }
}
