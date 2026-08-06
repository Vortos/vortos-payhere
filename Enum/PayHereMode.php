<?php

declare(strict_types=1);

namespace Vortos\PayHere\Enum;

/**
 * Which PayHere environment this deployment talks to.
 *
 * The two have different hosts *and* different merchant credentials, and a
 * sandbox secret against the live host produces a hash mismatch rather than an
 * obvious error — so the host and the credentials are chosen together, from
 * one value, rather than configured independently and hoped to agree.
 */
enum PayHereMode: string
{
    case Sandbox = 'sandbox';
    case Live    = 'live';

    public function checkoutUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://sandbox.payhere.lk/pay/checkout',
            self::Live    => 'https://www.payhere.lk/pay/checkout',
        };
    }

    /** Base for the merchant API (OAuth token, retrieval, refunds). */
    public function apiBaseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://sandbox.payhere.lk',
            self::Live    => 'https://www.payhere.lk',
        };
    }

    public function isLive(): bool
    {
        return $this === self::Live;
    }
}
