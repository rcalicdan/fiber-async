<?php

namespace Rcalicdan\FiberAsync\EventLoop\ValueObjects;

use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Socket\AsyncSocketOperations;
use Rcalicdan\FiberAsync\Socket\Exceptions\SocketException;

/**
 * Socket represents a socket connection resource with asynchronous operations.
 * 
 * This value object encapsulates a socket resource and provides convenient
 * methods for performing asynchronous read and write operations. It manages
 * the socket's lifecycle and ensures proper cleanup when the object is destroyed.
 * 
 * @package Rcalicdan\FiberAsync\EventLoop\ValueObjects
 */
class Socket
{
    /**
     * The underlying socket resource.
     * 
     * @var resource|null
     */
    private $resource;

    /**
     * The async socket operations handler.
     * 
     * @var AsyncSocketOperations
     */
    private AsyncSocketOperations $operations;

    /**
     * Flag indicating whether the socket has been closed.
     * 
     * @var bool
     */
    private bool $isClosed = false;

    private const DEFAULT_BYTES = 8192;

    /**
     * Constructor initializes the socket with a resource and operations handler.
     * 
     * The socket is automatically set to non-blocking mode.
     * 
     * @param resource $resource The socket resource
     * @param AsyncSocketOperations $operations The async operations handler
     */
    public function __construct($resource, AsyncSocketOperations $operations)
    {
        $this->resource = $resource;
        $this->operations = $operations;
        stream_set_blocking($this->resource, false);
    }

    /**
     * Read data asynchronously from the socket.
     * 
     * Reads up to the specified number of bytes from the socket. If no length
     * is specified, defaults to 8192 bytes. The operation will time out after
     * the specified timeout period.
     * 
     * @param int|null $length Maximum number of bytes to read. Defaults to 8192 if null
     * @param float|null $timeout Read timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * 
     * @return PromiseInterface A promise that resolves with the data read, or rejects if the socket is closed
     * 
     * @throws SocketException If the socket is already closed
     */
    public function read(?int $length = null, ?float $timeout = 10.0): PromiseInterface
    {
        if ($this->isClosed) {
            return $this->operations->getAsyncOps()->reject(new SocketException('Socket is closed.'));
        }

        $readLength = $length ?? self::DEFAULT_BYTES; // Default to 8192 if not specified

        return $this->operations->read($this, $readLength, $timeout);
    }

    /**
     * Write data asynchronously to the socket.
     * 
     * Writes the specified data to the socket. The operation will time out
     * after the specified timeout period.
     * 
     * @param string $data The data to write to the socket
     * @param float|null $timeout Write timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * 
     * @return PromiseInterface A promise that resolves with the number of bytes written, or rejects if the socket is closed
     * 
     * @throws SocketException If the socket is already closed
     */
    public function write(string $data, ?float $timeout = 10.0): PromiseInterface
    {
        if ($this->isClosed) {
            return $this->operations->getAsyncOps()->reject(new SocketException('Socket is closed.'));
        }

        return $this->operations->write($this, $data, $timeout);
    }

    /**
     * Close the socket connection and clean up resources.
     * 
     * Closes the socket connection and marks this socket object as closed.
     * This method is safe to call multiple times.
     * 
     * @return void
     */
    public function close(): void
    {
        if (! $this->isClosed) {
            $this->isClosed = true;
            $this->operations->close($this);
        }
    }

    /**
     * Get the underlying socket resource.
     * 
     * @return resource|null The socket resource, or null if not available
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * Check if the socket has been closed.
     * 
     * @return bool True if the socket is closed, false otherwise
     */
    public function isClosed(): bool
    {
        return $this->isClosed;
    }

    /**
     * Destructor ensures the socket is properly closed when the object is destroyed.
     * 
     * This helps prevent resource leaks by automatically closing the socket
     * when the Socket object goes out of scope.
     * 
     * @return void
     */
    public function __destruct()
    {
        $this->close();
    }
}