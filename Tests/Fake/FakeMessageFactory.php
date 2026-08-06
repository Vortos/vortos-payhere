<?php

declare(strict_types=1);

namespace Vortos\PayHere\Tests\Fake;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * The PSR-17 pair the API client needs, without pulling a full HTTP
 * implementation into a unit test.
 */
final class FakeMessageFactory implements RequestFactoryInterface, StreamFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new FakeRequest($method, (string) $uri);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return new FakeStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }
}
