<?php

namespace App\Helpers;

/**
 * Money Helper
 *
 * Reads and formats the amounts of the billing pages. An amount is a decimal string
 * such as "1200.50": PHP only adds and compares them, in whole cents.
 */
class MoneyHelper
{
    /**
     * Turn typed input into a decimal string with two places, or null when it is not a plain amount
     *
     * Accepts 1200, 1200.5, 1200.50 and 1200,50. A sign, a thousands separator or a third decimal is refused.
     */
    public static function parse($input): ?string
    {
        if (!is_string($input) && !is_int($input)) {
            return null;
        }
        if (!preg_match('/^(\d{1,10})(?:[.,](\d{1,2}))?$/', trim((string)$input), $m)) {
            return null;
        }
        $whole = ltrim($m[1], '0');

        return ($whole === '' ? '0' : $whole) . '.' . str_pad($m[2] ?? '', 2, '0');
    }

    /**
     * Exchange rate as a decimal string with eight places, or null when it is not a positive number
     */
    public static function parseRate($input): ?string
    {
        if (!is_string($input) && !is_int($input)) {
            return null;
        }
        if (!preg_match('/^(\d{1,9})(?:[.,](\d{1,8}))?$/', trim((string)$input), $m)) {
            return null;
        }
        $whole = ltrim($m[1], '0');
        $rate = ($whole === '' ? '0' : $whole) . '.' . str_pad($m[2] ?? '', 8, '0');

        return self::toCents($rate, 8) === 0 ? null : $rate;
    }

    /**
     * Currency code in upper case, or null when it is not three letters
     */
    public static function currency($input): ?string
    {
        if (!is_string($input)) {
            return null;
        }
        $code = strtoupper(trim($input));

        return preg_match('/^[A-Z]{3}$/', $code) ? $code : null;
    }

    /**
     * A decimal string as a whole number of its smallest unit ("12.5" with 2 places is 1250)
     */
    public static function toCents($amount, int $places = 2): int
    {
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim((string)$amount), $m)) {
            return 0;
        }
        $fraction = substr(str_pad($m[3] ?? '', $places, '0'), 0, $places);
        $units = (int)($m[2] . $fraction);

        return $m[1] === '-' ? -$units : $units;
    }

    /**
     * Whole cents back to a decimal string with two places
     */
    public static function fromCents(int $cents): string
    {
        $digits = str_pad((string)abs($cents), 3, '0', STR_PAD_LEFT);

        return ($cents < 0 ? '-' : '') . substr($digits, 0, -2) . '.' . substr($digits, -2);
    }

    /**
     * Add decimal strings without floating point
     */
    public static function sum(array $amounts): string
    {
        $cents = 0;
        foreach ($amounts as $amount) {
            $cents += self::toCents($amount);
        }

        return self::fromCents($cents);
    }

    /**
     * First amount minus the second
     */
    public static function subtract($a, $b): string
    {
        return self::fromCents(self::toCents($a) - self::toCents($b));
    }

    public static function isZero($amount): bool
    {
        return self::toCents($amount) === 0;
    }

    /**
     * Amount for display: the symbol in front when the currency has one, else the code behind
     */
    public static function format($amount, string $currency, ?string $symbol = null): string
    {
        $cents = self::toCents($amount);
        $number = number_format(abs($cents) / 100, 2, '.', ',');
        $text = ($symbol !== null && $symbol !== '') ? $symbol . $number : $number . ' ' . $currency;

        return ($cents < 0 ? '-' : '') . $text;
    }
}
