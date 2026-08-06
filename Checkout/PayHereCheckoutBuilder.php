<?php

declare(strict_types=1);

namespace Vortos\PayHere\Checkout;

use Vortos\PayHere\Enum\PayHereMode;
use Vortos\PayHere\Exception\MissingPayerDetailsException;
use Vortos\Payments\ValueObject\ChargeRequest;
use Vortos\Payments\ValueObject\CheckoutInstruction;

/**
 * Turns a priced charge into the signed form body PayHere's hosted page expects.
 *
 * No network call happens here, and none happens at checkout at all: PayHere's
 * hosted checkout *is* a signed form POST from the payer's browser. That is why
 * this rail cannot fail with a timeout at the moment of opening a checkout, and
 * also why nothing about the resulting payment is known until the notification
 * arrives.
 *
 * ── The mandatory fields ──────────────────────────────────────────────────
 * PayHere rejects a checkout that is missing any of first_name, last_name,
 * email, phone, address, city or country. Rejects it at *their* page, after the
 * payer has already been sent there — so the failure is invisible to us and
 * total for them. They are therefore checked here, by name, before the payer
 * moves, and the missing ones are named in the exception.
 *
 * Substituting a placeholder like "N/A" was considered and rejected: it turns a
 * loud, fixable configuration error into a silent data-quality one, and the
 * address ends up on a receipt.
 */
final class PayHereCheckoutBuilder
{
    /** Fields PayHere refuses a checkout without. */
    private const REQUIRED_PAYER_FIELDS = [
        'firstName', 'lastName', 'phone', 'addressLine1', 'city', 'countryCode',
    ];

    public function __construct(
        private readonly PayHereMode   $mode,
        private readonly string        $merchantId,
        private readonly PayHereSigner $signer,
        /**
         * Absolute, publicly reachable URL of our IPN endpoint.
         *
         * Deployment configuration rather than a per-charge value: it is the
         * same for every payment, and letting a caller supply it would make
         * "where settlement is reported" something a bug could redirect.
         */
        private readonly string        $notifyUrl,
    ) {
        if (!str_starts_with($notifyUrl, 'https://')) {
            throw new \InvalidArgumentException(sprintf(
                'The PayHere notify_url must be an absolute https URL, got "%s". PayHere cannot reach localhost, and a deployment whose notify_url is wrong takes money it never learns about.',
                $notifyUrl,
            ));
        }
    }

    public function build(ChargeRequest $request): CheckoutInstruction
    {
        if ($request->returnUrl === null || $request->cancelUrl === null) {
            throw new \InvalidArgumentException(
                'A PayHere checkout needs both a return and a cancel URL; the payer leaves our site and must have somewhere to come back to either way.'
            );
        }

        $missing = $request->payer->missing(...self::REQUIRED_PAYER_FIELDS);

        if ($missing !== []) {
            throw MissingPayerDetailsException::forFields($missing);
        }

        $amount   = PayHereAmountFormatter::format($request->total);
        $currency = $request->currency()->code;

        $fields = [
            'merchant_id' => $this->merchantId,
            'return_url'  => $request->returnUrl,
            'cancel_url'  => $request->cancelUrl,
            // Where settlement is actually learned. The return URL carries no
            // payment status at all — PayHere documents this explicitly — so
            // this is the only channel that can confirm a payment.
            'notify_url'  => $this->notifyUrl,
            'order_id'    => $request->reference,
            'items'       => $this->itemsSummary($request),
            'currency'    => $currency,
            'amount'      => $amount,
            'first_name'  => (string) $request->payer->firstName,
            'last_name'   => (string) $request->payer->lastName,
            'email'       => $request->payer->email,
            'phone'       => (string) $request->payer->phone,
            'address'     => (string) $request->payer->addressLine1,
            'city'        => (string) $request->payer->city,
            'country'     => (string) $request->payer->countryCode,
            // Signed over the formatted amount string, not the number.
            'hash'        => $this->signer->checkoutHash($this->merchantId, $request->reference, $amount, $currency),
        ];

        // PayHere echoes custom_1/custom_2 on the notification untouched. They
        // are the only metadata channel this rail has, so a caller that needs
        // one more identifier alongside order_id uses them by name rather than
        // by packing structure into the reference.
        foreach (['custom_1', 'custom_2'] as $slot) {
            if (isset($request->metadata[$slot]) && $request->metadata[$slot] !== '') {
                $fields[$slot] = $request->metadata[$slot];
            }
        }

        return CheckoutInstruction::redirect($this->mode->checkoutUrl(), $fields);
    }

    /**
     * The one-line description the payer sees on PayHere's page.
     *
     * Deliberately not the itemised breakdown. PayHere's optional per-item
     * fields are not covered by the hash, so an itemisation there could
     * disagree with the signed total without anything noticing — and the
     * itemisation the payer is entitled to has already been shown on our own
     * page, where it is authoritative.
     */
    private function itemsSummary(ChargeRequest $request): string
    {
        $first = $request->lines[0]->description;

        return count($request->lines) > 1
            ? sprintf('%s (+%d more)', $first, count($request->lines) - 1)
            : $first;
    }
}
