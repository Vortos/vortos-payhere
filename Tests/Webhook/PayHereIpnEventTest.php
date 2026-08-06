<?php

declare(strict_types=1);

namespace Vortos\PayHere\Tests\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\PayHere\Enum\PayHereStatusCode;
use Vortos\PayHere\Exception\PayHereException;
use Vortos\PayHere\Webhook\PayHereIpnEvent;
use Vortos\Payments\Enum\TransactionStatus;

final class PayHereIpnEventTest extends TestCase
{
    public function testParsesTheLkrAmountBackToExactMinorUnits(): void
    {
        $event = PayHereIpnEvent::fromVerifiedFields($this->fields());

        self::assertSame(600_000, $event->amount->minorUnits);
        self::assertSame('LKR', $event->amount->currency->code);
        self::assertTrue($event->isSuccess());
        self::assertSame('reg-1', $event->orderId);
        self::assertSame('320027039', $event->paymentId);
    }

    #[DataProvider('statusMappings')]
    public function testEachStatusCodeMapsToItsOwnOutcome(string $code, TransactionStatus $expected): void
    {
        $event = PayHereIpnEvent::fromVerifiedFields(['status_code' => $code] + $this->fields());

        self::assertSame($expected, $event->statusCode->toTransactionStatus());
    }

    /** @return iterable<string, array{string, TransactionStatus}> */
    public static function statusMappings(): iterable
    {
        yield 'success'      => ['2', TransactionStatus::Completed];
        yield 'pending'      => ['0', TransactionStatus::Pending];
        yield 'cancelled'    => ['-1', TransactionStatus::Cancelled];
        yield 'failed'       => ['-2', TransactionStatus::Failed];
        // Distinct from a refund on purpose: on a gateway rail the liability
        // for a reversal is ours, and filing it as an ordinary refund hides
        // that from everyone who later reads the ledger.
        yield 'charged back' => ['-3', TransactionStatus::ChargedBack];
    }

    /**
     * A code PayHere adds later must stop here, loudly, rather than falling
     * through a default arm into "completed" and crediting an organisation for
     * money that may have been reversed.
     */
    public function testAnUnrecognisedStatusCodeIsRefused(): void
    {
        $this->expectException(PayHereException::class);

        PayHereIpnEvent::fromVerifiedFields(['status_code' => '7'] + $this->fields());
    }

    /** Each of these is its own kind of malformed, and none may be salvaged. */
    #[DataProvider('unparseableAmounts')]
    public function testAnUnparseableAmountIsRefused(string $amount): void
    {
        $this->expectException(PayHereException::class);

        PayHereIpnEvent::fromVerifiedFields(['payhere_amount' => $amount] + $this->fields());
    }

    /** @return iterable<string, array{string}> */
    public static function unparseableAmounts(): iterable
    {
        yield 'thousand separator' => ['6,000.00'];
        yield 'not a number'       => ['lots'];
        yield 'negative'           => ['-6000.00'];
        // Finer than the currency allows. Rounding it would settle a payment
        // for an amount PayHere never named.
        yield 'excess precision'   => ['6000.001'];
        yield 'empty'              => [''];
    }

    /**
     * PayHere sends no event id of its own, so the inbox key has to be derived
     * — and derived deterministically, or a redelivery is stored as a second
     * payment.
     */
    public function testTheDerivedEventIdIsStableAcrossRedeliveries(): void
    {
        $now   = new \DateTimeImmutable();
        $first = PayHereIpnEvent::fromVerifiedFields($this->fields())->toWebhookEvent($now);
        $again = PayHereIpnEvent::fromVerifiedFields($this->fields())->toWebhookEvent($now->modify('+1 hour'));

        self::assertSame($first->id, $again->id);
        self::assertSame('320027039', $first->id);
        self::assertSame('reg-1', $first->reference);
    }

    public function testWithoutAPaymentIdTheEventIdFallsBackToOrderAndStatus(): void
    {
        $fields = $this->fields();
        unset($fields['payment_id']);

        $event = PayHereIpnEvent::fromVerifiedFields(['status_code' => '-2'] + $fields)
            ->toWebhookEvent(new \DateTimeImmutable());

        // Order plus status, so a failure and a later success on the same order
        // are distinct rows rather than one swallowing the other.
        self::assertSame('reg-1:-2', $event->id);
    }

    /** @return array<string, string> */
    private function fields(): array
    {
        return [
            'merchant_id'      => '1221149',
            'order_id'         => 'reg-1',
            'payment_id'       => '320027039',
            'payhere_amount'   => '6000.00',
            'payhere_currency' => 'LKR',
            'status_code'      => '2',
            'method'           => 'VISA',
            'status_message'   => 'Successfully completed',
        ];
    }
}
