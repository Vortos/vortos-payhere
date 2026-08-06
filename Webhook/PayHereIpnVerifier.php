<?php

declare(strict_types=1);

namespace Vortos\PayHere\Webhook;

use Vortos\PayHere\Checkout\PayHereSigner;
use Vortos\Payments\Contract\SignatureVerifierInterface;
use Vortos\Payments\Exception\SignatureVerificationException;
use Vortos\Payments\Webhook\SignedPayload;

/**
 * Proves an IPN came from PayHere.
 *
 * ── What is signed, and what that means for the endpoint ──────────────────
 * PayHere signs five form fields, not the request body: merchant_id, order_id,
 * payhere_amount, payhere_currency and status_code. So this reads
 * `SignedPayload::$fields`, and the endpoint in front of it must parse the form
 * body faithfully. It notably does *not* read the raw body — re-signing a
 * re-encoded body would prove nothing about what PayHere sent.
 *
 * ── What this does not prove ──────────────────────────────────────────────
 * That the amount is the amount we charged. A valid signature only says PayHere
 * sent these five values; it says nothing about whether they match the payment
 * we opened. The endpoint must still compare `payhere_amount` and
 * `payhere_currency` against the frozen pricing snapshot for that order before
 * anything is credited. Signature first, then reconciliation — neither
 * substitutes for the other.
 */
final class PayHereIpnVerifier implements SignatureVerifierInterface
{
    public const SIGNATURE_FIELD = 'md5sig';

    /** The fields PayHere's signature covers, in signing order. */
    private const SIGNED_FIELDS = [
        'merchant_id', 'order_id', 'payhere_amount', 'payhere_currency', 'status_code',
    ];

    public function __construct(
        private readonly PayHereSigner $signer,
        private readonly string        $merchantId,
    ) {}

    public function verify(SignedPayload $payload): void
    {
        $received = $payload->field(self::SIGNATURE_FIELD) ?? '';

        // Absent is checked before anything else. A bare POST from someone who
        // found the URL must be turned away before it gets to influence a
        // single comparison.
        if (trim($received) === '') {
            throw SignatureVerificationException::missing(self::gatewayId());
        }

        $values = [];
        foreach (self::SIGNED_FIELDS as $field) {
            $value = $payload->field($field);

            // A signed field that is absent cannot be verified, and filling it
            // with '' would make the digest of a truncated notification
            // reproducible by anyone who knows the scheme.
            if ($value === null) {
                throw SignatureVerificationException::malformed(self::gatewayId(), sprintf('%s is missing', $field));
            }

            $values[] = $value;
        }

        // A notification claiming a different merchant is not ours to act on,
        // whatever it is signed with — and our secret could not have produced
        // that signature anyway. Rejected explicitly so the reason is legible.
        if ($values[0] !== $this->merchantId) {
            throw SignatureVerificationException::mismatch(self::gatewayId());
        }

        $expected = $this->signer->notificationSignature(...$values);

        if (!$this->signer->matches($expected, $received)) {
            throw SignatureVerificationException::mismatch(self::gatewayId());
        }
    }

    private static function gatewayId(): string
    {
        return 'payhere';
    }
}
