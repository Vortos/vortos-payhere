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
 * Rows are claimed with `FOR UPDATE SKIP LOCKED`. Several workers may run at
 * once — and in a long-lived worker pool they will — so two of them picking up
 * the same notification would credit the same payment twice, which is the one
 * outcome nothing downstream can undo.
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

    public function __construct(
        private readonly Connection                $connection,
        private readonly PayHereIpnHandlerInterface $handler,
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

    /** @return list<array<string, mixed>> */
    private function claim(int $limit): array
    {
        $now = new \DateTimeImmutable();

        return $this->connection->fetchAllAssociative(
            'SELECT id, event_id, payload, attempts
               FROM ' . $this->table . '
              WHERE status = :status AND next_attempt_at <= :now
              ORDER BY received_at
              LIMIT ' . max(1, $limit) . '
                FOR UPDATE SKIP LOCKED',
            ['status' => 'pending', 'now' => $now->format('Y-m-d H:i:s')],
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
                SET attempts = :attempts, last_error = :error, next_attempt_at = :next
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
