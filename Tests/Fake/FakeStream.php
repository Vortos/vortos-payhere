<?php

declare(strict_types=1);

namespace Vortos\PayHere\Tests\Fake;

use Psr\Http\Message\StreamInterface;

/** A read-once string body — all the client ever does is cast it to a string. */
final class FakeStream implements StreamInterface
{
    public function __construct(private readonly string $contents) {}

    public function __toString(): string
    {
        return $this->contents;
    }

    public function getContents(): string
    {
        return $this->contents;
    }

    public function getSize(): ?int
    {
        return strlen($this->contents);
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void {}

    public function rewind(): void {}

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \LogicException('Fake response bodies are read-only.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        return $this->contents;
    }

    public function getMetadata(?string $key = null)
    {
        return null;
    }
}
