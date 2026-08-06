<?php

declare(strict_types=1);

namespace Vortos\PayHere\DependencyInjection;

/**
 * PayHere configuration, read from the environment and overridable in
 * `config/payhere.php`.
 *
 * Mirrors the Paddle package's shape so a reader who knows one knows the other.
 *
 * ── Two sets of credentials, deliberately separate ────────────────────────
 * `merchantId` + `merchantSecret` sign checkouts and verify notifications;
 * `appId` + `appSecret` authenticate the merchant API for retrieval and
 * refunds. A deployment can legitimately have the first pair and not the
 * second — it can take payments but not read or refund them — and the gateway
 * reports that honestly through `supportsRefunds` rather than failing at boot.
 */
final class VortosPayHereConfig
{
    private string $mode;
    private string $merchantId;
    private string $merchantSecret;
    private string $appId;
    private string $appSecret;
    private string $notifyUrl;
    private string $inboxTable;
    private int    $circuitBreakerFailureThreshold;
    private int    $circuitBreakerResetTimeoutSeconds;

    public function __construct()
    {
        $this->mode           = $_ENV['PAYHERE_MODE'] ?? 'sandbox';
        $this->merchantId     = $_ENV['PAYHERE_MERCHANT_ID'] ?? '';
        $this->merchantSecret = $_ENV['PAYHERE_MERCHANT_SECRET'] ?? '';
        $this->appId          = $_ENV['PAYHERE_APP_ID'] ?? '';
        $this->appSecret      = $_ENV['PAYHERE_APP_SECRET'] ?? '';

        // Must be absolute and publicly reachable — PayHere posts to it from
        // its own servers, so it can never be a localhost or relative path.
        $this->notifyUrl      = $_ENV['PAYHERE_NOTIFY_URL'] ?? '';

        $this->inboxTable                        = 'payhere_ipn_inbox';
        $this->circuitBreakerFailureThreshold    = 5;
        $this->circuitBreakerResetTimeoutSeconds = 30;
    }

    public function mode(string $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function merchantId(string $merchantId): static
    {
        $this->merchantId = $merchantId;

        return $this;
    }

    public function merchantSecret(string $secret): static
    {
        $this->merchantSecret = $secret;

        return $this;
    }

    public function merchantApp(string $appId, string $appSecret): static
    {
        $this->appId     = $appId;
        $this->appSecret = $appSecret;

        return $this;
    }

    public function notifyUrl(string $url): static
    {
        $this->notifyUrl = $url;

        return $this;
    }

    public function inboxTable(string $table): static
    {
        $this->inboxTable = $table;

        return $this;
    }

    public function circuitBreaker(int $failureThreshold, int $resetTimeoutSeconds): static
    {
        $this->circuitBreakerFailureThreshold    = $failureThreshold;
        $this->circuitBreakerResetTimeoutSeconds = $resetTimeoutSeconds;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode'            => $this->mode,
            'merchant_id'     => $this->merchantId,
            'merchant_secret' => $this->merchantSecret,
            'app_id'          => $this->appId,
            'app_secret'      => $this->appSecret,
            'notify_url'      => $this->notifyUrl,
            'inbox_table'     => $this->inboxTable,
            'circuit_breaker' => [
                'failure_threshold'    => $this->circuitBreakerFailureThreshold,
                'reset_timeout_seconds' => $this->circuitBreakerResetTimeoutSeconds,
            ],
        ];
    }
}
