<?php

declare(strict_types=1);

namespace Vortos\PayHere\Inbox;

use Vortos\PayHere\Webhook\PayHereIpnEvent;

/**
 * What the application does with a verified PayHere notification.
 *
 * Implemented in the application, because settling a payment is a business
 * decision this package has no business making. Specifically, the handler is
 * the layer that must compare the notification's amount and currency against
 * the pricing snapshot frozen when the checkout was opened — a valid signature
 * proves PayHere sent these values, not that they are the values we charged.
 *
 * Handlers must be idempotent. The inbox de-duplicates deliveries, but a
 * handler that fails after a partial write will be retried against the same
 * event.
 */
interface PayHereIpnHandlerInterface
{
    /**
     * @throws \Throwable Any failure leaves the inbox row pending for retry.
     */
    public function handle(PayHereIpnEvent $event): void;
}
