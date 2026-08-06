<?php

declare(strict_types=1);

namespace Vortos\PayHere\Webhook;

use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\PayHere\Exception\PayHereException;
use Vortos\PayHere\Inbox\PayHereInboxWriterInterface;
use Vortos\Payments\Exception\SignatureVerificationException;
use Vortos\Security\Csrf\Attribute\SkipCsrf;

/**
 * Receives PayHere IPNs. Verify, persist, acknowledge — nothing else.
 *
 * No handler runs in this request. Once the inbox insert commits the
 * notification is durable, and whether we have *acted* on it becomes a local,
 * retryable problem rather than something a handler bug can lose while PayHere
 * believes it delivered.
 *
 * ── CSRF must be skipped, and that is not a weakening ─────────────────────
 * PayHere is a server, not a browser: it holds no cookie and can double-submit
 * nothing. With CSRF enabled every delivery is rejected before the signature is
 * ever examined — and rejected *silently*, because a webhook that 403s produces
 * no error on our side and only a failed attempt on theirs. This exact failure
 * has already cost this codebase a week on the Paddle endpoint. The request is
 * authenticated by a keyed signature over the fields that matter, which is
 * strictly stronger than an ambient cookie.
 *
 * ── Why a signature failure answers 401 and not 419 ───────────────────────
 * So the two are distinguishable from outside with a single curl. A 419 means
 * CSRF is eating webhooks again; a 401 means the signature genuinely did not
 * verify. Anyone diagnosing a silent payment outage needs to tell those apart
 * in one request, not by reading logs on a box.
 */
#[AsController]
#[SkipCsrf]
final class PayHereIpnController
{
    public function __construct(
        private readonly PayHereIpnVerifier          $verifier,
        private readonly PayHereInboxWriterInterface $inbox,
        private readonly LoggerInterface             $logger,
    ) {}

    #[Route('/webhooks/payhere', name: 'vortos_payhere.ipn', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string, string> $fields */
        $fields = array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $request->request->all(),
        );

        try {
            $this->verifier->verify(new \Vortos\Payments\Webhook\SignedPayload(
                rawBody: $request->getContent(),
                headers: [],
                fields:  $fields,
            ));
        } catch (SignatureVerificationException $e) {
            // The message is deliberately not echoed to the caller: an endpoint
            // that reports *why* a signature failed tells an attacker how close
            // they got.
            $this->logger->warning('PayHere IPN rejected: signature did not verify.', [
                'order_id' => $fields['order_id'] ?? null,
                'reason'   => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $event = PayHereIpnEvent::fromVerifiedFields($fields);
        } catch (PayHereException $e) {
            // Verified, but malformed — an unrecognised status code or an
            // unparseable amount. Answering 400 stops PayHere retrying
            // something that will never parse, and the log carries the payload
            // we could not read.
            $this->logger->error('PayHere IPN verified but not understood.', [
                'order_id' => $fields['order_id'] ?? null,
                'reason'   => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Unprocessable notification'], Response::HTTP_BAD_REQUEST);
        }

        $webhookEvent = $event->toWebhookEvent(new \DateTimeImmutable());
        $stored       = $this->inbox->accept($webhookEvent->id, $webhookEvent->type, $fields);

        if (!$stored) {
            // A redelivery. PayHere retries until it sees a 2xx, so this
            // usually means our previous 200 was lost in transit rather than
            // that anything went wrong.
            $this->logger->debug('PayHere IPN already recorded; redelivery acknowledged.', [
                'event_id' => $webhookEvent->id,
            ]);
        }

        return new JsonResponse(['status' => 'accepted']);
    }
}
