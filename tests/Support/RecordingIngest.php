<?php

declare(strict_types=1);

namespace Publicala\NightwatchCap\Tests\Support;

use Laravel\Nightwatch\Contracts\Ingest;

/**
 * Stands in for Nightwatch's transport and keeps what it would have sent.
 */
final class RecordingIngest implements Ingest
{
    /**
     * @var list<array<mixed>>
     */
    public array $sentNow = [];

    /**
     * @var list<array<mixed>>
     */
    public array $buffered = [];

    public bool $digestsWhenBufferIsFull = true;

    public function write(array $record): void
    {
        $this->buffered[] = $record;
    }

    public function writeNow(array $record): void
    {
        $this->sentNow[] = $record;
    }

    public function ping(): void
    {
        //
    }

    public function shouldDigest(bool $bool = true): void
    {
        //
    }

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
        $this->digestsWhenBufferIsFull = $bool;
    }

    public function digest(): void
    {
        //
    }

    public function flush(): void
    {
        $this->buffered = [];
    }
}
