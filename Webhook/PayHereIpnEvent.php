<?php

declare(strict_types=1);

namespace Vortos\PayHere\Webhook;

use Vortos\PayHere\Enum\PayHereStatusCode;
use Vortos\PayHere\Exception\PayHereException;
use Vortos\Payments\ValueObject\Money;
use Vortos\Payments\Webhook\WebhookEvent;

/**
 * A verified PayHere notification, typed.
 *
 * Only ever built from an already-verified payload. It exists so that the
 * fields a settlement decision depends on — the amount, the currency, the
 * status — are read once, in one place, with their conversions checked, rather
 * than being pulled out of a string map at each of the four call sites that
 * need them.
 *
 * ── The amount ────────────────────────────────────────────────────────────
 * PayHere sends `payhere_amount` as a decimal string ("6000.00"). It is parsed
 * back to integer minor units here, by string manipulation rather than by
 * `(float)`, because a float round trip on an amount is exactly the defect the
 * rest of this codebase spends so much effort avoiding.
 */
final readonly class PayHereIpnEvent
{
    /**
     * @param array<string, string> $raw The verified form fields, verbatim.
     */
    public function __construct(
        public string            $merchantId,
        /** Our reference — what we sent as order_id. */
        public string            $orderId,
        /** PayHere's own payment identifier. Absent on some non-success codes. */
        public ?string           $paymentId,
        public Money             $amount,
        public PayHereStatusCode $statusCode,
        public ?string           $method,
        public ?string           $statusMessage,
        public array             $raw,
    ) {}

    /**
     * @param array<string, string> $fields
     */
    public static function fromVerifiedFields(array $fields): self
    {
        $code = PayHereStatusCode::tryFrom((int) ($fields['status_code'] ?? PHP_INT_MIN));

        // An unrecognised status is never mapped optimistically. PayHere adding
        // a code we have not seen must stop the notification here, where it is
        // visible, rather than resolving to whatever the default arm says.
        if ($code === null) {
            throw new PayHereException(sprintf(
                'Unrecognised PayHere status_code "%s"; refusing to guess what it means for the money.',
                $fields['status_code'] ?? '',
            ));
        }

        return new self(
            merchantId:    $fields['merchant_id'] ?? '',
            orderId:       $fields['order_id'] ?? '',
            paymentId:     ($fields['payment_id'] ?? '') !== '' ? $fields['payment_id'] : null,
            amount:        self::parseAmount(
                $fields['payhere_amount'] ?? '',
                $fields['payhere_currency'] ?? '',
            ),
            statusCode:    $code,
            method:        ($fields['method'] ?? '') !== '' ? $fields['method'] : null,
            statusMessage: ($fields['status_message'] ?? '') !== '' ? $fields['status_message'] : null,
            raw:           $fields,
        );
    }

    /**
     * The rail-agnostic form, for an inbox and for handlers that do not care
     * which rail delivered.
     *
     * The event id is PayHere's payment_id where there is one, and otherwise a
     * composite of order and status. PayHere sends no event identifier of its
     * own, and an inbox that de-duplicates on a random id de-duplicates
     * nothing — every redelivery would be stored and processed again.
     */
    public function toWebhookEvent(\DateTimeImmutable $receivedAt): WebhookEvent
    {
        return new WebhookEvent(
            id:               $this->paymentId ?? sprintf('%s:%d', $this->orderId, $this->statusCode->value),
            type:             sprintf('payhere.payment.%s', strtolower($this->statusCode->name)),
            // PayHere does not timestamp its notifications, so this is our
            // receipt time and is labelled as such wherever it surfaces. It is
            // not passed off as the moment the payer paid.
            occurredAt:       $receivedAt,
            payload:          $this->raw,
            reference:        $this->orderId,
            gatewayReference: $this->paymentId,
        );
    }

    public function isSuccess(): bool
    {
        return $this->statusCode->isSuccess();
    }

    /**
     * Parses "6000.00" into 600000 minor units, without touching a float.
     */
    private static function parseAmount(string $decimal, string $currency): Money
    {
        $trimmed = trim($decimal);

        if (preg_match('/^\d+(\.\d+)?$/', $trimmed) !== 1) {
            throw new PayHereException(sprintf('PayHere sent an unparseable amount "%s".', $decimal));
        }

        $money    = Money::zero($currency);
        $exponent = $money->currency->exponent();

        [$whole, $fraction] = array_pad(explode('.', $trimmed, 2), 2, '');

        // Pad rather than round. A notification carrying more precision than
        // the currency has is malformed, and silently rounding it would settle
        // a payment for an amount PayHere did not name.
        if (strlen($fraction) > $exponent) {
            throw new PayHereException(sprintf(
                'PayHere sent "%s" for %s, which has more precision than the currency allows.',
                $decimal,
                $money->currency->code,
            ));
        }

        $fraction = str_pad($fraction, $exponent, '0');

        return Money::fromMinor((int) ($whole . $fraction), $money->currency);
    }
}
