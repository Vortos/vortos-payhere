<?php

declare(strict_types=1);

namespace Vortos\PayHere\Inbox;

/**
 * Makes a verified notification durable before anything acts on it.
 *
 * The split matters: "PayHere told us" and "we processed it" are different
 * facts, and collapsing them means a handler bug loses a payment PayHere
 * believes it delivered. Once the insert commits, PayHere can be acknowledged
 * and processing becomes a retryable local problem.
 */
interface PayHereInboxWriterInterface
{
    /**
     * @param array<string, string> $payload The verified form fields, verbatim.
     *
     * @return bool False when this notification is already stored — a
     *              redelivery, which is a no-op rather than an error.
     */
    public function accept(string $eventId, string $eventType, array $payload): bool;
}
