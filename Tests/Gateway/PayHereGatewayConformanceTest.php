<?php

declare(strict_types=1);

namespace Vortos\PayHere\Tests\Gateway;

use Vortos\PayHere\Api\PayHereApiClient;
use Vortos\PayHere\Checkout\PayHereCheckoutBuilder;
use Vortos\PayHere\Checkout\PayHereSigner;
use Vortos\PayHere\Enum\PayHereMode;
use Vortos\PayHere\Failover\PayHereCircuitBreaker;
use Vortos\PayHere\Gateway\PayHereGateway;
use Vortos\PayHere\Tests\Fake\FakeHttpClient;
use Vortos\PayHere\Tests\Fake\FakeMessageFactory;
use Vortos\PayHere\Webhook\PayHereIpnVerifier;
use Vortos\Payments\Contract\GatewayInterface;
use Vortos\Payments\Contract\SignatureVerifierInterface;
use Vortos\Payments\Testing\GatewayConformanceTestCase;
use Vortos\Payments\ValueObject\ChargeLine;
use Vortos\Payments\ValueObject\ChargeRequest;
use Vortos\Payments\ValueObject\Currency;
use Vortos\Payments\ValueObject\Money;
use Vortos\Payments\ValueObject\PayerDetails;
use Vortos\Payments\Webhook\SignedPayload;

/**
 * PayHere against the same suite Paddle passes.
 *
 * The value of running one suite over both is that it is the only place the
 * *interchangeability* of the two rails is asserted — a routing layer hands a
 * priced charge to whichever gateway the currency selected, and every property
 * it depends on has to hold identically on both.
 */
final class PayHereGatewayConformanceTest extends GatewayConformanceTestCase
{
    private const MERCHANT_ID = '1221149';
    private const SECRET      = 'MzQ1Njc4OTAxMjM0NTY3ODkw';

    protected function gateway(): GatewayInterface
    {
        $http = new FakeHttpClient();
        // The over-refund case: PayHere refuses it at its own API, and the
        // adapter must surface that as a refusal rather than as an outage.
        $http->willRespond('/payment/refund', 400, ['status' => -1, 'msg' => 'Refund amount exceeds the captured amount']);

        $factory = new FakeMessageFactory();

        return new PayHereGateway(
            checkout: new PayHereCheckoutBuilder(
                mode:       PayHereMode::Sandbox,
                merchantId: self::MERCHANT_ID,
                signer:     new PayHereSigner(self::SECRET),
                notifyUrl:  'https://api.example.com/webhooks/payhere',
            ),
            api: new PayHereApiClient(
                http:      $http,
                requests:  $factory,
                streams:   $factory,
                mode:      PayHereMode::Sandbox,
                appId:     'app_test',
                appSecret: 'secret_test',
                breaker:   new PayHereCircuitBreaker(),
            ),
        );
    }

    protected function chargeRequestIn(Currency $currency): ChargeRequest
    {
        return new ChargeRequest(
            reference: 'reg-conformance-1',
            total:     Money::fromMinor(600_000, $currency),
            lines:     [
                new ChargeLine('Tournament registration', Money::fromMinor(560_000, $currency)),
                new ChargeLine('Processing & platform fee', Money::fromMinor(40_000, $currency)),
            ],
            payer:     $this->payer(),
            returnUrl: 'https://app.example.com/pay/complete',
            cancelUrl: 'https://app.example.com/pay/cancelled',
        );
    }

    protected function signatureVerifier(): ?SignatureVerifierInterface
    {
        return new PayHereIpnVerifier(new PayHereSigner(self::SECRET), self::MERCHANT_ID);
    }

    /**
     * PayHere signs form fields rather than a header, so naming the amount
     * field switches on the suite's highest-value case: a structurally valid
     * notification whose amount has been altered must fail on the signature.
     */
    protected function signedAmountField(): ?string
    {
        return 'payhere_amount';
    }

    protected function validSignedPayload(): ?SignedPayload
    {
        $fields = [
            'merchant_id'      => self::MERCHANT_ID,
            'order_id'         => 'reg-conformance-1',
            'payment_id'       => '320027039',
            'payhere_amount'   => '6000.00',
            'payhere_currency' => 'LKR',
            'status_code'      => '2',
            'method'           => 'VISA',
            'status_message'   => 'Successfully completed',
        ];

        $fields['md5sig'] = (new PayHereSigner(self::SECRET))->notificationSignature(
            $fields['merchant_id'],
            $fields['order_id'],
            $fields['payhere_amount'],
            $fields['payhere_currency'],
            $fields['status_code'],
        );

        return new SignedPayload(rawBody: http_build_query($fields), fields: $fields);
    }

    /** @return array{gatewayReference: string, capturedMinor: int, currency: string} */
    protected function capturedChargeFixture(): array
    {
        return [
            'gatewayReference' => '320027039',
            'capturedMinor'    => 600_000,
            'currency'         => 'LKR',
        ];
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
