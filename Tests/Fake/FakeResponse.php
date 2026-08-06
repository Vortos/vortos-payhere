<?php

declare(strict_types=1);

namespace Vortos\PayHere\Tests\Fake;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The minimum of PSR-7 the API client actually touches: a status code and a
 * body. Everything else throws, so a test that starts depending on more fails
 * visibly instead of silently reading an empty default.
 */
final class FakeResponse implements ResponseInterface
{
    public function __construct(
        private readonly int    $status,
        private readonly string $body,
    ) {}

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getBody(): StreamInterface
    {
        return new FakeStream($this->body);
    }

    public function getReasonPhrase(): string
    {
        return '';
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function hasHeader(string $name): bool
    {
        return false;
    }

    public function getHeader(string $name): array
    {
        return [];
    }

    public function getHeaderLine(string $name): string
    {
        return '';
    }

    public function withHeader(string $name, $value): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }

    public function withAddedHeader(string $name, $value): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }

    public function withoutHeader(string $name): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }

    public function withBody(StreamInterface $body): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }
}
