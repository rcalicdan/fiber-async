<?php

namespace Rcalicdan\FiberAsync\ValueObjects;

use Rcalicdan\FiberAsync\AsyncSocketOperations;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

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

    public function read(int $length = 8192, ?float $timeout = 10.0): PromiseInterface
    {
        if ($this->isClosed) {
            return $this->operations->getAsyncOps()->reject(new \Rcalicdan\FiberAsync\Exceptions\SocketException('Socket is closed.'));
        }

        return $this->operations->read($this, $length, $timeout);
    }

    public function write(string $data, ?float $timeout = 10.0): PromiseInterface
    {
        if ($this->isClosed) {
            return $this->operations->getAsyncOps()->reject(new \Rcalicdan\FiberAsync\Exceptions\SocketException('Socket is closed.'));
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
