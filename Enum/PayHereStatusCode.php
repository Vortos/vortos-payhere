<?php

declare(strict_types=1);

namespace Vortos\PayHere\Enum;

use Vortos\Payments\Enum\TransactionStatus;

/**
 * PayHere's `status_code`, as documented.
 *
 * An integer-backed enum rather than raw comparisons, because the values are
 * signed and easy to mistype: `-2` (failed) and `-3` (charged back) differ by
 * one character and by who is out of pocket.
 *
 * Anything not listed here is unknown and must never be mapped optimistically —
 * an unrecognised code that resolves to "completed" credits an organisation for
 * money that may have been reversed.
 */
enum PayHereStatusCode: int
{
    case Success     = 2;
    case Pending     = 0;
    case Cancelled   = -1;
    case Failed      = -2;
    case ChargedBack = -3;

    public function toTransactionStatus(): TransactionStatus
    {
        return match ($this) {
            self::Success     => TransactionStatus::Completed,
            self::Pending     => TransactionStatus::Pending,
            self::Cancelled   => TransactionStatus::Cancelled,
            self::Failed      => TransactionStatus::Failed,
            // On a gateway rail this liability is ours, not the rail's. It is a
            // distinct status precisely so that a reversal is never quietly
            // filed alongside an ordinary refund.
            self::ChargedBack => TransactionStatus::ChargedBack,
        };
    }

    /** The only code that may credit a ledger. */
    public function isSuccess(): bool
    {
        return $this === self::Success;
    }
}
