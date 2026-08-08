<?php

declare(strict_types=1);

namespace Vortos\PayHere\Inbox;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Vortos\PayHere\Webhook\PayHereIpnEvent;

/**
 * Processes stored PayHere notifications, with retries and a dead letter.
 *
 * Runs outside the request that received the notification, which is what makes
 * "PayHere delivered it" and "we acted on it" independently true or false.
 *
 * ── Claiming ──────────────────────────────────────────────────────────────
 * Rows are claimed by an atomic `UPDATE ... RETURNING`, not a bare
 * `SELECT ... FOR UPDATE`. Several workers run at once in a long-lived pool, so
 * two of them picking up the same notification would credit the same payment
 * twice — the one outcome nothing downstream can undo. See claim() for why the
 * obvious SELECT does not actually prevent that.
 *
 * ── Backoff ───────────────────────────────────────────────────────────────
 * Exponential, capped. After the attempt ceiling a row is dead-lettered rather
 * than retried forever: a notification that has failed a dozen times is a bug
 * to be looked at, and a queue that hides it behind endless retries is a queue
 * nobody reads.
 */
final class PayHereInboxWorker
{
    private const MAX_ATTEMPTS = 12;

    /**
     * How long a claimed notification is held before another worker may retry
     * it. Long enough that an ordinary handler finishes well inside it, short
     * enough that a worker killed mid-flight does not strand a payment for an
     * afternoon.
     */
    private const LEASE_SECONDS = 300;

    public function __construct(
        private readonly Connection                 $connection,
        /**
         * Supplied by the application. Null when it has not wired one yet, in
         * which case notifications stay pending in the inbox rather than being
         * consumed by a worker with nowhere to send them.
         */
        private readonly ?PayHereIpnHandlerInterface $handler,
        private readonly LoggerInterface           $logger,
        private readonly string                    $table,
    ) {}

    /**
     * Processes up to `$limit` due notifications.
     *
     * @return array{processed: int, failed: int, dead: int}
     */
    public function run(int $limit = 50): array
    {
        $processed = 0;
        $failed    = 0;
        $dead      = 0;

        if ($this->handler === null) {
            return ['processed' => 0, 'failed' => 0, 'dead' => 0];
        }

        foreach ($this->claim($limit) as $row) {
            try {
                $payload = json_decode((string) $row['payload'], associative: true);

                if (!is_array($payload)) {
                    throw new \RuntimeException('Stored payload is not a JSON object.');
                }

                /** @var array<string, string> $fields */
                $fields = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $payload);

                $this->handler->handle(PayHereIpnEvent::fromVerifiedFields($fields));

                $this->markProcessed((int) $row['id']);
                $processed++;
            } catch (\Throwable $e) {
                $attempts = (int) $row['attempts'] + 1;

                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->markDead((int) $row['id'], $e->getMessage());
                    $dead++;

                    // Escalated, not swallowed. A dead-lettered payment
                    // notification is money that arrived and was never
                    // recorded, which is exactly the kind of silence this
                    // system is built to avoid.
                    $this->logger->critical('PayHere notification dead-lettered; a payment may be unrecorded.', [
                        'event_id' => $row['event_id'],
                        'attempts' => $attempts,
                        'error'    => $e->getMessage(),
                    ]);

                    continue;
                }

                $this->reschedule((int) $row['id'], $attempts, $e->getMessage());
                $failed++;

                $this->logger->warning('PayHere notification failed; will retry.', [
                    'event_id' => $row['event_id'],
                    'attempts' => $attempts,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return ['processed' => $processed, 'failed' => $failed, 'dead' => $dead];
    }

    /**
     * Claims rows by transitioning them, atomically, in one statement.
     *
     * ── Why not a plain SELECT ... FOR UPDATE SKIP LOCKED ─────────────────
     * Because that is a lie under autocommit. The row locks a bare SELECT takes
     * are released the instant the statement returns, so by the time the caller
     * loops over the rows it holds nothing — SKIP LOCKED skips nothing, and two
     * workers happily claim the same notification. The guarantee reads as though
     * it is there and is not.
     *
     * An UPDATE ... RETURNING carries its own implicit transaction, so the
     * SKIP LOCKED in its sub-select is held for the whole claim and the status
     * transition commits with it. One statement, no explicit transaction to
     * leak, and a row is claimed by exactly one worker.
     *
     * ── Recovering a worker that died mid-claim ───────────────────────────
     * A claimed row is parked with `next_attempt_at` a lease ahead. If the
     * worker never finishes — killed, OOM, deploy — the lease expires and the
     * row is picked up again by the clause below. Nothing is stranded in
     * `processing` forever waiting for a human to notice.
     *
     * @return list<array<string, mixed>>
     */
    private function claim(int $limit): array
    {
        $now = new \DateTimeImmutable();

        return $this->connection->fetchAllAssociative(
            'UPDATE ' . $this->table . '
                SET status = :processing,
                    next_attempt_at = :lease
              WHERE id IN (
                    SELECT id
                      FROM ' . $this->table . '
                     WHERE (status = :pending OR status = :processing)
                       AND next_attempt_at <= :now
                     ORDER BY received_at
                     LIMIT ' . max(1, $limit) . '
                       FOR UPDATE SKIP LOCKED
                    )
          RETURNING id, event_id, payload, attempts',
            [
                'pending'    => 'pending',
                'processing' => 'processing',
                'now'        => $now->format('Y-m-d H:i:s'),
                'lease'      => $now->modify('+' . self::LEASE_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
            ],
        );
    }

    private function markProcessed(int $id): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . $this->table . ' SET status = :status, processed_at = :now, last_error = NULL WHERE id = :id',
            ['status' => 'processed', 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    private function reschedule(int $id, int $attempts, string $error): void
    {
        // 2^attempts seconds, capped at an hour. Fast enough that a transient
        // database blip clears in seconds, slow enough that a genuine outage is
        // not amplified into a retry storm.
        $delay = min(3_600, 2 ** min($attempts, 12));

        $this->connection->executeStatement(
            'UPDATE ' . $this->table . '
                SET status = \'pending\', attempts = :attempts, last_error = :error, next_attempt_at = :next
              WHERE id = :id',
            [
                'attempts' => $attempts,
                'error'    => mb_substr($error, 0, 2_000),
                'next'     => (new \DateTimeImmutable('+' . $delay . ' seconds'))->format('Y-m-d H:i:s'),
                'id'       => $id,
            ],
        );
    }

    private function markDead(int $id, string $error): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . $this->table . ' SET status = :status, attempts = attempts + 1, last_error = :error WHERE id = :id',
            ['status' => 'dead', 'error' => mb_substr($error, 0, 2_000), 'id' => $id],
        );
    }
}
