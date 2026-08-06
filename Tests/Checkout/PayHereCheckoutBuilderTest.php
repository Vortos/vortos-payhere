<?php

declare(strict_types=1);

namespace Vortos\PayHere\Tests\Checkout;

use PHPUnit\Framework\TestCase;
use Vortos\PayHere\Checkout\PayHereCheckoutBuilder;
use Vortos\PayHere\Checkout\PayHereSigner;
use Vortos\PayHere\Enum\PayHereMode;
use Vortos\PayHere\Exception\MissingPayerDetailsException;
use Vortos\Payments\Enum\CheckoutMode;
use Vortos\Payments\ValueObject\ChargeLine;
use Vortos\Payments\ValueObject\ChargeRequest;
use Vortos\Payments\ValueObject\Money;
use Vortos\Payments\ValueObject\PayerDetails;

final class PayHereCheckoutBuilderTest extends TestCase
{
    private const MERCHANT_ID = '1221149';
    private const SECRET      = 'MzQ1Njc4OTAxMjM0NTY3ODkw';

    /**
     * The case this entire project exists to fix.
     *
     * An organiser publishes LKR 6,000. The payer is asked for "6000.00" in
     * LKR — no conversion, no spread, no quote — so the organisation can be
     * credited exactly the fee it published.
     */
    public function testAnLkrChargeIsPostedInLkrAtTheExactPublishedAmount(): void
    {
        $instruction = $this->builder()->build($this->charge());

        self::assertSame(CheckoutMode::Redirect, $instruction->mode);
        self::assertSame('https://sandbox.payhere.lk/pay/checkout', $instruction->actionUrl);
        self::assertSame('LKR', $instruction->fields['currency']);
        self::assertSame('6000.00', $instruction->fields['amount']);
    }

    public function testTheHashCoversTheFormattedAmountString(): void
    {
        $fields = $this->builder()->build($this->charge())->fields;

        $expected = (new PayHereSigner(self::SECRET))->checkoutHash(
            self::MERCHANT_ID,
            $fields['order_id'],
            $fields['amount'],
            $fields['currency'],
        );

        self::assertSame($expected, $fields['hash']);
    }

    public function testTheNotifyUrlIsDeploymentConfigurationNotCallerInput(): void
    {
        $fields = $this->builder()->build($this->charge())->fields;

        self::assertSame('https://api.example.com/webhooks/payhere', $fields['notify_url']);
    }

    /**
     * PayHere refuses these at its own page — after the payer has already left
     * ours, where we cannot see the error and they cannot fix it. So the
     * failure has to happen here, naming what is missing.
     */
    public function testAPayerMissingMandatoryFieldsIsRefusedBeforeRedirecting(): void
    {
        try {
            $this->builder()->build(new ChargeRequest(
                reference: 'reg-1',
                total:     Money::fromMinor(600_000, 'LKR'),
                lines:     [new ChargeLine('Tournament registration', Money::fromMinor(600_000, 'LKR'))],
                payer:     new PayerDetails('payer@example.com', firstName: 'Nimal'),
                returnUrl: 'https://app.example.com/pay/complete',
                cancelUrl: 'https://app.example.com/pay/cancelled',
            ));

            self::fail('Expected the checkout to be refused.');
        } catch (MissingPayerDetailsException $e) {
            self::assertSame(
                ['lastName', 'phone', 'addressLine1', 'city', 'countryCode'],
                $e->fields,
            );
        }
    }

    public function testARedirectRailNeedsSomewhereToComeBackTo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder()->build(new ChargeRequest(
            reference: 'reg-1',
            total:     Money::fromMinor(600_000, 'LKR'),
            lines:     [new ChargeLine('Tournament registration', Money::fromMinor(600_000, 'LKR'))],
            payer:     $this->payer(),
        ));
    }

    public function testCustomSlotsCarryCallerMetadata(): void
    {
        $fields = $this->builder()->build(new ChargeRequest(
            reference: 'reg-1',
            total:     Money::fromMinor(600_000, 'LKR'),
            lines:     [new ChargeLine('Tournament registration', Money::fromMinor(600_000, 'LKR'))],
            payer:     $this->payer(),
            returnUrl: 'https://app.example.com/pay/complete',
            cancelUrl: 'https://app.example.com/pay/cancelled',
            metadata:  ['custom_1' => 'ent-9'],
        ))->fields;

        self::assertSame('ent-9', $fields['custom_1']);
    }

    /** A notify_url PayHere cannot reach is a payment we never learn about. */
    public function testANonHttpsNotifyUrlIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PayHereCheckoutBuilder(
            mode:       PayHereMode::Sandbox,
            merchantId: self::MERCHANT_ID,
            signer:     new PayHereSigner(self::SECRET),
            notifyUrl:  'http://localhost:8000/webhooks/payhere',
        );
    }

    private function builder(): PayHereCheckoutBuilder
    {
        return new PayHereCheckoutBuilder(
            mode:       PayHereMode::Sandbox,
            merchantId: self::MERCHANT_ID,
            signer:     new PayHereSigner(self::SECRET),
            notifyUrl:  'https://api.example.com/webhooks/payhere',
        );
    }

    private function charge(): ChargeRequest
    {
        return new ChargeRequest(
            reference: 'reg-1',
            total:     Money::fromMinor(600_000, 'LKR'),
            lines:     [
                new ChargeLine('Tournament registration', Money::fromMinor(560_000, 'LKR')),
                new ChargeLine('Processing & platform fee', Money::fromMinor(40_000, 'LKR')),
            ],
            payer:     $this->payer(),
            returnUrl: 'https://app.example.com/pay/complete',
            cancelUrl: 'https://app.example.com/pay/cancelled',
        );
    }

    private function payer(): PayerDetails
    {
        return new PayerDetails(
            email:        'payer@example.com',
            firstName:    'Nimal',
            lastName:     'Perera',
            phone:        '+94771234567',
            addressLine1: '221 Galle Road',
            city:         'Colombo',
            countryCode:  'LK',
        );
    }
}
