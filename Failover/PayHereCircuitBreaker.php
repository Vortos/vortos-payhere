<?php

declare(strict_types=1);

namespace Vortos\PayHere\Failover;

/**
 * Stops hammering PayHere's merchant API once it has clearly stopped answering.
 *
 * Deliberately a copy of the Paddle package's breaker rather than a shared
 * dependency between the two integration packages: a rail package that
 * depends on another rail package is the seam that stops rails being
 * independently releasable, and this is thirty lines.
 *
 * State lives on the instance, so in a long-lived worker pool each worker keeps
 * its own view. That is the intended behaviour — a breaker is a local
 * back-pressure valve, and a shared one would let a single worker's bad network
 * path take every worker offline.
 *
 * It guards the merchant API only. Checkout needs no breaker: it is a signed
 * form the payer's own browser posts, so there is no outbound call of ours to
 * fail.
 */
final class PayHereCircuitBreaker
{
    private PayHereCircuitBreakerState $state               = PayHereCircuitBreakerState::Closed;
    private int                        $consecutiveFailures = 0;
    private float                      $openedAt            = 0.0;

    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $resetTimeoutSeconds = 30,
    ) {}

    public function isAvailable(): bool
    {
        if ($this->state === PayHereCircuitBreakerState::Closed) {
            return true;
        }

        if ($this->state === PayHereCircuitBreakerState::Open) {
            if ((microtime(true) - $this->openedAt) >= $this->resetTimeoutSeconds) {
                $this->state = PayHereCircuitBreakerState::HalfOpen;

                return true;
            }

            return false;
        }

        // Half-open: exactly one probe is allowed through, and its outcome
        // decides whether the circuit closes or re-opens.
        return true;
    }

    public function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->state               = PayHereCircuitBreakerState::Closed;
    }

    public function recordFailure(): void
    {
        $this->consecutiveFailures++;

        if ($this->state === PayHereCircuitBreakerState::HalfOpen
            || $this->consecutiveFailures >= $this->failureThreshold
        ) {
            $this->state    = PayHereCircuitBreakerState::Open;
            $this->openedAt = microtime(true);
        }
    }

    public function state(): PayHereCircuitBreakerState
    {
        return $this->state;
    }

    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }
}
