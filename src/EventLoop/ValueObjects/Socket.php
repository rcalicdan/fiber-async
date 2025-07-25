<?php

namespace Rcalicdan\FiberAsync\EventLoop\ValueObjects;

use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Socket\AsyncSocketOperations;
use Rcalicdan\FiberAsync\Socket\Exceptions\SocketException;

class Socket
{
    private $resource;
    private AsyncSocketOperations $operations;
    private bool $isClosed = false;

    public function __construct($resource, AsyncSocketOperations $operations)
    {
        $this->resource = $resource;
        $this->operations = $operations;
        stream_set_blocking($this->resource, false);
    }

    public function read(?int $length = null, ?float $timeout = 10.0): PromiseInterface
    {
        if ($this->isClosed) {
            return $this->operations->getAsyncOps()->reject(new SocketException('Socket is closed.'));
        }

        $readLength = $length ?? 8192; // Default to 8192 if not specified

        return $this->operations->read($this, $readLength, $timeout);
    }

    public function write(string $data, ?float $timeout = 10.0): PromiseInterface
    {
        if ($this->isClosed) {
            return $this->operations->getAsyncOps()->reject(new SocketException('Socket is closed.'));
        }

        return $this->operations->write($this, $data, $timeout);
    }

    public function close(): void
    {
        if (! $this->isClosed) {
            $this->isClosed = true;
            $this->operations->close($this);
        }
    }

    public function getResource()
    {
        return $this->resource;
    }

    public function isClosed(): bool
    {
        return $this->isClosed;
    }

    public function __destruct()
    {
        $this->close();
    }
}
