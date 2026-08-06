<?php

declare(strict_types=1);

namespace Vortos\PayHere\Gateway;

use Vortos\PayHere\Api\PayHereApiClient;
use Vortos\PayHere\Checkout\PayHereAmountFormatter;
use Vortos\PayHere\Checkout\PayHereCheckoutBuilder;
use Vortos\PayHere\Enum\PayHereStatusCode;
use Vortos\PayHere\Exception\MissingPayerDetailsException;
use Vortos\PayHere\Exception\PayHereApiException;
use Vortos\PayHere\Exception\PayHereException;
use Vortos\PayHere\Exception\PayHereUnavailableException;
use Vortos\Payments\Contract\GatewayInterface;
use Vortos\Payments\Enum\CheckoutMode;
use Vortos\Payments\Enum\TransactionStatus;
use Vortos\Payments\Exception\ChargeRejectedException;
use Vortos\Payments\Exception\CurrencyNotSupportedException;
use Vortos\Payments\Exception\GatewayUnavailableException;
use Vortos\Payments\Exception\RefundNotSupportedException;
use Vortos\Payments\Exception\TransactionNotFoundException;
use Vortos\Payments\ValueObject\ChargeRequest;
use Vortos\Payments\ValueObject\ChargeResult;
use Vortos\Payments\ValueObject\GatewayTransaction;
use Vortos\Payments\ValueObject\Money;
use Vortos\Payments\ValueObject\PayoutTotals;
use Vortos\Payments\ValueObject\ProcessorFee;
use Vortos\Payments\ValueObject\RailCapabilities;
use Vortos\Payments\ValueObject\RefundRequest;
use Vortos\Payments\ValueObject\RefundResult;

/**
 * PayHere as one interchangeable payment rail.
 *
 * ── Why this rail exists ──────────────────────────────────────────────────
 * It bills LKR natively. That single fact is the whole point: an organiser
 * pricing in LKR is charged in LKR, no quote is taken, no spread is applied,
 * and the organisation is credited exactly the fee it published. On a rail that
 * cannot bill LKR the same registration converts to USD and lands short by the
 * spread, which no amount of tuning fixes.
 *
 * ── What PayHere is, and is not ───────────────────────────────────────────
 * A gateway, not a merchant of record. The money is collected in our name, so
 * the tax registration, the filing and the chargeback liability are ours, and
 * {@see RailCapabilities} says so explicitly rather than leaving it to be
 * discovered. It also does not report a per-transaction fee, which is why
 * reconciliation on this rail must record "could not check" rather than a zero.
 *
 * ── No outbound call at checkout ──────────────────────────────────────────
 * PayHere's hosted checkout is a signed form the payer's own browser posts, so
 * `createCharge()` builds and signs — it never leaves the process. This rail
 * therefore cannot time out while opening a checkout, and equally knows nothing
 * about the payment until the notification arrives.
 */
final class PayHereGateway implements GatewayInterface
{
    public const ID = 'payhere';

    /**
     * What PayHere can bill.
     *
     * LKR first because it is the reason this rail is here. The others are
     * PayHere's published support and are listed so that routing by currency
     * has the truth to work from — not so that traffic is steered onto them.
     */
    private const SUPPORTED_CURRENCIES = ['LKR', 'USD', 'GBP', 'EUR', 'AUD'];

    public function __construct(
        private readonly PayHereCheckoutBuilder $checkout,
        private readonly PayHereApiClient       $api,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function capabilities(): RailCapabilities
    {
        return new RailCapabilities(
            // A gateway: PayHere moves the money, we are the seller. Everything
            // in the next three flags follows from that, and each of them is a
            // liability that is ours rather than the rail's.
            isMerchantOfRecord:         false,
            remitsTax:                  false,
            handlesChargebacks:         false,
            // PayHere nets its cut at settlement and publishes nothing per
            // transaction. Reconciliation must say so rather than book a zero.
            reportsPerTransactionFee:   false,
            supportsRefunds:            $this->api->isConfigured(),
            supportedCurrencies:        self::SUPPORTED_CURRENCIES,
            settlementCurrency:         'LKR',
            // Null on purpose, and load-bearing. This rail converts nothing: a
            // currency it cannot bill is a currency it refuses, so no
            // conversion can ever happen here that nobody quoted.
            conversionFallbackCurrency: null,
            checkoutMode:               CheckoutMode::Redirect,
        );
    }

    public function createCharge(ChargeRequest $request): ChargeResult
    {
        $currency = $request->currency()->code;

        if (!$this->capabilities()->supports($currency)) {
            throw CurrencyNotSupportedException::forRail(self::ID, $currency, $this->capabilities());
        }

        try {
            $instruction = $this->checkout->build($request);
        } catch (MissingPayerDetailsException $e) {
            // The payer's own form is missing something PayHere insists on.
            // Terminal: retrying sends them to the same refusal.
            throw new ChargeRejectedException($e->getMessage(), self::ID, 'missing_payer_details', $e);
        } catch (PayHereException $e) {
            throw new ChargeRejectedException($e->getMessage(), self::ID, null, $e);
        }

        return new ChargeResult(
            reference: $request->reference,
            // PayHere issues a payment_id only once the payer has paid, so
            // until then its own handle on this charge *is* our order_id — that
            // is the key its retrieval API searches by. Returning it here is
            // honest rather than convenient: there is no other identifier yet.
            gatewayReference: $request->reference,
            total:            $request->total,
            checkout:         $instruction,
        );
    }

    /**
     * @param string $gatewayReference Our order id, or PayHere's payment id.
     */
    public function fetchTransaction(string $gatewayReference): GatewayTransaction
    {
        try {
            $payments = $this->api->searchPaymentsByOrderId($gatewayReference);
        } catch (PayHereUnavailableException $e) {
            throw GatewayUnavailableException::for(self::ID, $e->getMessage(), $e);
        } catch (PayHereApiException $e) {
            throw GatewayUnavailableException::for(self::ID, $e->getMessage(), $e);
        }

        if ($payments === []) {
            throw TransactionNotFoundException::for(self::ID, $gatewayReference);
        }

        // Newest last in PayHere's ordering; a retried payment against the same
        // order id means the most recent attempt is the one that counts.
        $payment = $payments[array_key_last($payments)];

        $status   = $this->mapStatus($payment);
        $currency = is_string($payment['currency'] ?? null) ? $payment['currency'] : 'LKR';
        $amount   = $this->minorUnits($payment['amount'] ?? null, $currency);
        $paidAt   = $this->parseDate($payment['date'] ?? null);

        return new GatewayTransaction(
            reference:        is_string($payment['order_id'] ?? null) ? $payment['order_id'] : $gatewayReference,
            gatewayReference: (string) ($payment['payment_id'] ?? $gatewayReference),
            status:           $status,
            total:            Money::fromMinor($amount, $currency),
            payout:           $this->payoutFor($status, Money::fromMinor($amount, $currency)),
            // PayHere's own payment date when it gives one. When it does not,
            // this is our observation time standing in — imprecise by up to a
            // polling interval, and only ever used for bucketing, never to
            // decide whether something settled.
            settledAt:        $status->isSettled() ? ($paidAt ?? new \DateTimeImmutable()) : null,
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        if (!$this->api->isConfigured()) {
            throw RefundNotSupportedException::byRail(self::ID);
        }

        $formatted = $request->amount !== null
            ? PayHereAmountFormatter::format($request->amount)
            : null;

        try {
            $response = $this->api->refund(
                paymentId:       $request->gatewayReference,
                formattedAmount: $formatted,
                reason:          $request->reason,
            );
        } catch (PayHereUnavailableException $e) {
            // Unknown, not failed. A retry here could refund twice, so the
            // caller must reconcile against PayHere before trying again.
            throw GatewayUnavailableException::for(self::ID, $e->getMessage(), $e);
        } catch (PayHereApiException $e) {
            throw new RefundNotSupportedException($e->getMessage(), 0, $e);
        }

        return new RefundResult(
            gatewayRefundReference: (string) ($response['data']['payment_id'] ?? $request->gatewayReference),
            // A partial refund is exactly what we asked for. A full one is
            // whatever PayHere captured, which it does not report back — so it
            // is reported as unknown rather than as a number we made up.
            amount:                 $request->amount,
            isImmediate:            false,
        );
    }

    /**
     * A settled PayHere payment reports a gross and nothing else.
     *
     * The fee is explicitly unknown — not zero. Booking zero would make every
     * reconciliation report a match it never made and leave the drift alert
     * green forever, which is the failure mode this codebase has already been
     * bitten by once.
     */
    private function payoutFor(TransactionStatus $status, Money $gross): ?PayoutTotals
    {
        if (!$status->isSettled()) {
            return null;
        }

        return new PayoutTotals(
            gross:    $gross,
            fee:      ProcessorFee::unknown(),
            // Equal to gross only because the fee is unknown, not because it is
            // nil. Consumers must read `fee->isKnown` before trusting this.
            earnings: $gross,
        );
    }

    /** @param array<string, mixed> $payment */
    private function mapStatus(array $payment): TransactionStatus
    {
        $raw = $payment['status_code'] ?? $payment['status'] ?? null;

        if (!is_numeric($raw)) {
            throw new PayHereException(sprintf(
                'PayHere returned a payment with no usable status (%s); refusing to guess whether it settled.',
                var_export($raw, true),
            ));
        }

        $code = PayHereStatusCode::tryFrom((int) $raw);

        if ($code === null) {
            throw new PayHereException(sprintf('Unrecognised PayHere status_code "%s".', $raw));
        }

        return $code->toTransactionStatus();
    }

    /** Parses PayHere's decimal amount string into minor units without a float. */
    private function minorUnits(mixed $amount, string $currency): int
    {
        $decimal = is_string($amount) || is_int($amount) ? trim((string) $amount) : '';

        if (preg_match('/^\d+(\.\d+)?$/', $decimal) !== 1) {
            throw new PayHereException(sprintf('PayHere returned an unparseable amount "%s".', $decimal));
        }

        $exponent           = Money::zero($currency)->currency->exponent();
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');

        if (strlen($fraction) > $exponent) {
            throw new PayHereException(sprintf('PayHere returned "%s", which is finer than %s allows.', $decimal, $currency));
        }

        return (int) ($whole . str_pad($fraction, $exponent, '0'));
    }

    private function parseDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
