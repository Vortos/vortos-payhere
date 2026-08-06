<?php

declare(strict_types=1);

namespace Vortos\PayHere\Checkout;

/**
 * PayHere's two MD5 signatures, in one place.
 *
 * ── On MD5 ────────────────────────────────────────────────────────────────
 * MD5 is not our choice. It is PayHere's published scheme and both sides must
 * compute the same thing, so this implements it exactly rather than improving
 * on it. What that costs is worth being precise about: MD5 is broken for
 * collision resistance, but this is a keyed construction over a short, fully
 * structured input where every field is independently re-checked downstream —
 * the notification's amount and currency are compared against the frozen
 * pricing snapshot before anything is credited. The signature proves origin;
 * it is not the only thing standing between a POST and a ledger.
 *
 * ── The formulas, verbatim from PayHere's documentation ───────────────────
 *   checkout hash
 *     = to_upper_case(md5(merchant_id + order_id + amount + currency
 *                         + to_upper_case(md5(merchant_secret))))
 *
 *   notification md5sig
 *     = to_upper_case(md5(merchant_id + order_id + payhere_amount
 *                         + payhere_currency + status_code
 *                         + to_upper_case(md5(merchant_secret))))
 *
 * Note that `amount` enters the hash as the *formatted string* — "6000.00",
 * two decimals, no separators. The hash covers the characters, not the number,
 * so a value that renders as "6000.0" or "6,000.00" is a rejected checkout.
 * That formatting is done once, in {@see PayHereAmountFormatter}, and never
 * inline.
 */
final class PayHereSigner
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $merchantSecret,
    ) {
        if (trim($merchantSecret) === '') {
            throw new \InvalidArgumentException(
                'PayHere merchant secret is empty. Every checkout would be signed with a hash of nothing and rejected, and every notification would verify against that same nothing.'
            );
        }
    }

    /** The `hash` field posted with a checkout. */
    public function checkoutHash(
        string $merchantId,
        string $orderId,
        string $formattedAmount,
        string $currency,
    ): string {
        return $this->digest($merchantId . $orderId . $formattedAmount . $currency);
    }

    /** The `md5sig` PayHere sends on a notification. */
    public function notificationSignature(
        string $merchantId,
        string $orderId,
        string $payhereAmount,
        string $payhereCurrency,
        string $statusCode,
    ): string {
        return $this->digest($merchantId . $orderId . $payhereAmount . $payhereCurrency . $statusCode);
    }

    /**
     * Constant-time comparison of a received signature against an expected one.
     *
     * `hash_equals`, never `===`. A short-circuiting comparison leaks how many
     * leading characters were right through its own timing, which turns a
     * 32-character space into 32 sequential guesses.
     *
     * Case is normalised first because PayHere documents upper case but is not
     * guaranteed to be consistent about it, and a case mismatch would present
     * as a forged notification — sending us hunting for an attacker that does
     * not exist while real payments silently fail to settle.
     */
    public function matches(string $expected, string $received): bool
    {
        return hash_equals(strtoupper($expected), strtoupper(trim($received)));
    }

    private function digest(string $material): string
    {
        return strtoupper(md5($material . strtoupper(md5($this->merchantSecret))));
    }
}
