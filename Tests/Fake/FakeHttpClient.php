<?php

declare(strict_types=1);

namespace Vortos\PayHere\Tests\Fake;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Answers PayHere's merchant API from a script of canned responses.
 *
 * Keyed by a fragment of the request path so a test can say what
 * `/payment/search` returns without caring what order the token call happens
 * in.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $sent = [];

    /** @param array<string, array{int, array<string, mixed>}> $responses path fragment => [status, body] */
    public function __construct(private array $responses = [])
    {
        $this->responses += [
            '/oauth/token' => [200, ['access_token' => 'tok_fake', 'expires_in' => 600]],
        ];
    }

    /** @param array<string, mixed> $body */
    public function willRespond(string $pathFragment, int $status, array $body): void
    {
        $this->responses[$pathFragment] = [$status, $body];
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;

        $path = $request->getUri()->getPath() . ($request->getUri()->getQuery() !== '' ? '?' . $request->getUri()->getQuery() : '');

        foreach ($this->responses as $fragment => [$status, $body]) {
            if (str_contains($path, $fragment)) {
                return new FakeResponse($status, json_encode($body, JSON_THROW_ON_ERROR));
            }
        }

        return new FakeResponse(404, json_encode(['msg' => 'not scripted'], JSON_THROW_ON_ERROR));
    }
}
