<?php

declare(strict_types=1);

namespace Vortos\PayHere\Inbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Persists verified PayHere notifications into the inbox table.
 *
 * Called after signature verification and before anything is credited. The
 * unique index on `event_id` is the idempotency check: a redelivery collides
 * and is reported as already-stored, so a payment cannot be credited twice by
 * PayHere simply repeating itself.
 */
final class PayHereInboxWriter implements PayHereInboxWriterInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string     $table,
    ) {}

    public function accept(string $eventId, string $eventType, array $payload): bool
    {
        $now = new \DateTimeImmutable();

        try {
            $this->connection->executeStatement(
                'INSERT INTO ' . $this->table . '
                 (event_id, event_type, payload, status, attempts, received_at, next_attempt_at)
                 VALUES (:event_id, :event_type, :payload, :status, 0, :received_at, :next_attempt_at)',
                [
                    'event_id'        => $eventId,
                    'event_type'      => $eventType,
                    // Stored verbatim. A notification is evidence, and evidence
                    // that has been normalised on the way in cannot later be
                    // re-verified against the signature it arrived with.
                    'payload'         => json_encode($payload, JSON_THROW_ON_ERROR),
                    'status'          => 'pending',
                    'received_at'     => $now->format('Y-m-d H:i:s'),
                    'next_attempt_at' => $now->format('Y-m-d H:i:s'),
                ],
            );

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
