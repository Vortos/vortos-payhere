<?php

declare(strict_types=1);

namespace Vortos\PayHere\Api;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Vortos\PayHere\Enum\PayHereMode;
use Vortos\PayHere\Exception\PayHereApiException;
use Vortos\PayHere\Exception\PayHereUnavailableException;
use Vortos\PayHere\Failover\PayHereCircuitBreaker;

/**
 * PayHere's merchant API — OAuth token, payment retrieval, refunds.
 *
 * Separate credentials from the checkout: the checkout is signed with the
 * merchant *secret*, while this authenticates with an app id and app secret
 * created in the merchant portal. They are not interchangeable, and a
 * deployment that has only the checkout credentials can still take payments —
 * it just cannot read or refund them, which is why the gateway degrades
 * explicitly rather than failing at boot.
 *
 * ── Token handling ────────────────────────────────────────────────────────
 * The token is short-lived and cached only for the life of this object, never
 * on a property that outlives a request in a long-lived worker beyond its own
 * expiry check. Four workers each fetching their own token is cheaper than one
 * stale token producing 401s that look like revoked credentials.
 */
final class PayHereApiClient
{
    private ?string $token        = null;
    private float   $tokenExpires = 0.0;

    public function __construct(
        private readonly ClientInterface         $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface  $streams,
        private readonly PayHereMode             $mode,
        #[\SensitiveParameter] private readonly string $appId,
        #[\SensitiveParameter] private readonly string $appSecret,
        private readonly PayHereCircuitBreaker   $breaker,
    ) {}

    /** Whether merchant-API credentials were configured at all. */
    public function isConfigured(): bool
    {
        return trim($this->appId) !== '' && trim($this->appSecret) !== '';
    }

    /**
     * The payments recorded against one of our order ids.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchPaymentsByOrderId(string $orderId): array
    {
        $response = $this->send(
            'GET',
            '/merchant/v1/payment/search?order_id=' . rawurlencode($orderId),
        );

        $data = $response['data'] ?? [];

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function refund(string $paymentId, ?string $formattedAmount, string $reason): array
    {
        $body = ['payment_id' => $paymentId, 'description' => $reason];

        // Omitted means full. Sending an explicit amount equal to the capture
        // would be equivalent, but only if our idea of the capture is right —
        // and letting PayHere decide what "all of it" means removes a way to be
        // wrong about someone's money.
        if ($formattedAmount !== null) {
            $body['amount'] = $formattedAmount;
        }

        return $this->send('POST', '/merchant/v1/payment/refund', $body);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body = null): array
    {
        if (!$this->isConfigured()) {
            throw new PayHereApiException(
                'PayHere merchant API credentials are not configured; retrieval and refunds are unavailable on this deployment.'
            );
        }

        if (!$this->breaker->isAvailable()) {
            throw new PayHereUnavailableException('PayHere merchant API circuit is open.');
        }

        try {
            $request = $this->requests
                ->createRequest($method, $this->mode->apiBaseUrl() . $path)
                ->withHeader('Authorization', 'Bearer ' . $this->token())
                ->withHeader('Accept', 'application/json');

            if ($body !== null) {
                $request = $request
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($this->streams->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
            }

            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // The request never reached a decision. The caller must treat the
            // outcome as unknown rather than as a failure.
            $this->breaker->recordFailure();

            throw new PayHereUnavailableException('PayHere merchant API is unreachable: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new PayHereApiException('Could not encode a PayHere request body: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();

        if ($status >= 500) {
            $this->breaker->recordFailure();

            throw new PayHereUnavailableException(sprintf('PayHere merchant API returned %d.', $status));
        }

        $this->breaker->recordSuccess();

        $decoded = json_decode((string) $response->getBody(), associative: true);

        if (!is_array($decoded)) {
            throw new PayHereApiException('PayHere merchant API returned a body that is not JSON.');
        }

        if ($status >= 400) {
            throw new PayHereApiException(sprintf(
                'PayHere merchant API rejected the request (%d): %s',
                $status,
                is_string($decoded['msg'] ?? null) ? $decoded['msg'] : 'no message',
            ));
        }

        return $decoded;
    }

    private function token(): string
    {
        if ($this->token !== null && microtime(true) < $this->tokenExpires) {
            return $this->token;
        }

        try {
            $request = $this->requests
                ->createRequest('POST', $this->mode->apiBaseUrl() . '/merchant/v1/oauth/token')
                ->withHeader('Authorization', 'Basic ' . base64_encode($this->appId . ':' . $this->appSecret))
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withHeader('Accept', 'application/json')
                ->withBody($this->streams->createStream('grant_type=client_credentials'));

            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->breaker->recordFailure();

            throw new PayHereUnavailableException('Could not reach PayHere to authenticate: ' . $e->getMessage(), 0, $e);
        }

        $decoded = json_decode((string) $response->getBody(), associative: true);
        $token   = is_array($decoded) ? ($decoded['access_token'] ?? null) : null;

        if (!is_string($token) || $token === '') {
            throw new PayHereApiException('PayHere did not return an access token; check the merchant app credentials.');
        }

        $lifetime = is_numeric($decoded['expires_in'] ?? null) ? (int) $decoded['expires_in'] : 600;

        // Expire early on purpose. A token used in the last seconds of its life
        // arrives expired often enough to be a recurring, unexplained 401.
        $this->tokenExpires = microtime(true) + max(30, $lifetime - 60);
        $this->token        = $token;

        return $token;
    }
}
