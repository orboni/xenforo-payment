<?php

/**
 * Orboni for XenForo.
 *
 * @author    Orboni <support@orboni.com>
 * @copyright 2026 Orboni
 * @license   MIT
 */

namespace Orboni\Payment;

/**
 * The currencies Orboni takes a price in, and how amounts are written for it:
 * in each currency's smallest unit (ISO 4217), 1250 for 12.50 EUR, 1500 for
 * 1500 JPY. Orboni turns a price into dollars at the rate of the day, the
 * European Central Bank's or the market's.
 */
class Currencies
{
    const DECIMALS = [
        'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'CHF' => 2, 'PLN' => 2, 'CZK' => 2,
        'HUF' => 2, 'RON' => 2, 'DKK' => 2, 'SEK' => 2, 'NOK' => 2, 'ISK' => 0,
        'TRY' => 2, 'JPY' => 0, 'KRW' => 0, 'CNY' => 2, 'HKD' => 2, 'SGD' => 2,
        'INR' => 2, 'IDR' => 2, 'MYR' => 2, 'PHP' => 2, 'THB' => 2, 'ILS' => 2,
        'AUD' => 2, 'NZD' => 2, 'CAD' => 2, 'MXN' => 2, 'BRL' => 2, 'ZAR' => 2,
        'UAH' => 2, 'ARS' => 2, 'CLP' => 0, 'AED' => 2, 'SAR' => 2, 'TWD' => 2,
        'VND' => 0,
    ];

    /** Orboni's least payment is worth $1: checked here for dollars, by Orboni in any other currency. */
    const MIN_USD_CENTS = 100;

    public static function supports(string $currency): bool
    {
        return isset(self::DECIMALS[strtoupper($currency)]);
    }

    public static function decimals(string $currency): int
    {
        $currency = strtoupper($currency);

        return isset(self::DECIMALS[$currency]) ? self::DECIMALS[$currency] : 2;
    }

    /** An amount in the currency's smallest unit. */
    public static function minor(float $amount, string $currency): int
    {
        return (int) round($amount * (10 ** self::decimals($currency)));
    }

    /** "12.50 EUR" for 1250 euro cents, "1500 JPY" for 1500 yen. */
    public static function format(int $minor, string $currency): string
    {
        $decimals = self::decimals($currency);

        return number_format($minor / (10 ** $decimals), $decimals, '.', '').' '.strtoupper($currency);
    }

    /**
     * One of a session's decimal amounts with its currency: "25.00 EUR".
     *
     * @param  mixed  $amount
     */
    public static function formatDecimal($amount, string $currency): string
    {
        return number_format((float) $amount, self::decimals($currency), '.', '').' '.strtoupper($currency);
    }
}
