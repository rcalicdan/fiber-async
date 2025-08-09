<?php

namespace Rcalicdan\FiberAsync\EventLoop\ValueObjects;

use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Socket\AsyncSocketOperations;
use Rcalicdan\FiberAsync\Socket\Exceptions\SocketException;

/**
 * Socket wrapper for asynchronous socket operations.
 *
 * This class provides a high-level interface for asynchronous socket operations
 * including reading, writing, and connection management. It wraps a socket resource
 * and delegates operations to the AsyncSocketOperations handler.
 */
class Socket
{
    /**
     * The underlying socket resource.
     *
     * @var resource
     */
    private $resource;

    /**
     * Handler for asynchronous socket operations.
     */
    private AsyncSocketOperations $operations;

    /**
     * Whether the socket has been closed.
     */
    private bool $isClosed = false;

    /**
     * Default byte size for read operations.
     */
    private const DEFAULT_BYTE_SIZE = 8192;

    /**
     * Creates a new Socket instance.
     *
     * @param  resource  $resource  The socket resource
     * @param  AsyncSocketOperations  $operations  Handler for async operations
     *
     * @throws \TypeError If resource is not a valid resource type
     */
    public function __construct($resource, AsyncSocketOperations $operations)
    {
        if (! is_resource($resource)) {
            throw new \TypeError('Expected resource, got '.gettype($resource));
        }

        $this->resource = $resource;
        $this->operations = $operations;
        stream_set_blocking($this->resource, false);
    }

    /**
     * Asynchronously reads data from the socket.
     *
     * @param  int|null  $length  Maximum number of bytes to read (default: 8192)
     * @param  float|null  $timeout  Timeout in seconds (default: 10.0)
     * @return PromiseInterface<string> Promise that resolves with the read data
     *
     * @throws SocketException If the socket is closed
     */
    public function read(?int $length = null, ?float $timeout = 10.0): PromiseInterface
    {
        if ($this->isClosed) {
            return $this->operations->getAsyncOps()->rejected(new SocketException('Socket is closed.'));
        }

        $readLength = $length ?? self::DEFAULT_BYTE_SIZE;

        return $this->operations->read($this, $readLength, $timeout);
    }

    /**
     * Asynchronously writes data to the socket.
     *
     * @param  string  $data  The data to write
     * @param  float|null  $timeout  Timeout in seconds (default: 10.0)
     * @return PromiseInterface<int> Promise that resolves with the number of bytes written
     *
     * @throws SocketException If the socket is closed
     */
    public function write(string $data, ?float $timeout = 10.0): PromiseInterface
    {
        if ($this->isClosed) {
            return $this->operations->getAsyncOps()->rejected(new SocketException('Socket is closed.'));
        }

        return $this->operations->write($this, $data, $timeout);
    }

    /**
     * Closes the socket connection.
     *
     * This method ensures the socket is properly closed and cleaned up.
     * It's safe to call multiple times - subsequent calls will be ignored.
     */
    public function close(): void
    {
        if (! $this->isClosed) {
            $this->isClosed = true;
            $this->operations->close($this);
        }
    }

    /**
     * Gets the underlying socket resource.
     *
     * @return resource The socket resource
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * Checks if the socket is closed.
     *
     * @return bool True if the socket is closed, false otherwise
     */
    public function isClosed(): bool
    {
        return $this->isClosed;
    }

    /**
     * Destructor ensures the socket is properly closed.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->close();
    }
}
