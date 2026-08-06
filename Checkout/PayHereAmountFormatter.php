<?php

declare(strict_types=1);

namespace Vortos\PayHere\Checkout;

use Vortos\PayHere\Exception\PayHereException;
use Vortos\Payments\ValueObject\Money;

/**
 * Renders an amount the way PayHere's signature expects it.
 *
 * PayHere's own sample formats with `number_format($amount, 2, '.', '')`, and
 * that string — not the number behind it — is what the hash covers. So this is
 * the only place an amount becomes text, and it starts from integer minor
 * units rather than from a float that was itself derived from them.
 *
 * The exponent guard is not paranoia about a currency PayHere does not accept.
 * It is about the one that would silently work: a zero-decimal currency
 * rendered as its own minor units would produce a plausible-looking string for
 * a hundredth of the intended charge, hash correctly, and settle.
 */
final class PayHereAmountFormatter
{
    /** PayHere quotes every supported currency to two decimal places. */
    private const REQUIRED_EXPONENT = 2;

    public static function format(Money $amount): string
    {
        $exponent = $amount->currency->exponent();

        if ($exponent !== self::REQUIRED_EXPONENT) {
            throw new PayHereException(sprintf(
                'PayHere expects two-decimal amounts, but %s has %d. Refusing to render it rather than charging a wrong-by-a-factor-of-a-hundred amount that would hash correctly.',
                $amount->currency->code,
                $exponent,
            ));
        }

        // Money renders from the integer using the ISO exponent, so this is
        // already "6000.00" for 600000 minor LKR — no float, no rounding, no
        // locale-dependent separator.
        return $amount->toDecimalString();
    }
}
