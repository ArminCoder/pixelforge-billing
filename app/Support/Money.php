<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * All money in this app is a whole number of nanodollars.
 * 1 USD = 1,000,000,000 nanodollars. Floats are never used for money.
 *
 * Pixelforge reports money with 9 decimals, so nanodollars hold every
 * value exactly.
 */
final class Money
{
    public const NANOS_PER_USD = 1_000_000_000;

    /**
     * Turn a decimal string from the API into nanodollars.
     * "12.50" becomes 12_500_000_000.
     */
    public static function fromDecimal(string $usd): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,9}))?$/', $usd, $parts)) {
            throw new InvalidArgumentException("Not a valid money amount: {$usd}");
        }

        $dollars = (int) $parts[1];
        $fraction = (int) str_pad($parts[2] ?? '', 9, '0');

        return $dollars * self::NANOS_PER_USD + $fraction;
    }

    /**
     * Turn nanodollars back into the 9-decimal string Pixelforge uses.
     * 373_875_000 becomes "0.373875000".
     */
    public static function toDecimal(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $absolute = abs($nanos);

        return sprintf(
            '%s%d.%09d',
            $sign,
            intdiv($absolute, self::NANOS_PER_USD),
            $absolute % self::NANOS_PER_USD,
        );
    }

    /**
     * Integer division that rounds up. Both numbers must be positive.
     * This is the only place where rounding can happen.
     */
    public static function divideRoundingUp(int $amount, int $divisor): int
    {
        return intdiv($amount + $divisor - 1, $divisor);
    }
}
