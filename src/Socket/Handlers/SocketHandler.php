<?php

namespace Rcalicdan\FiberAsync\Socket;

use Rcalicdan\FiberAsync\EventLoop\ValueObjects\Socket;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Socket\Exceptions\SocketException;

/**
 * Handler for performing asynchronous operations on Socket value objects.
 *
 * This service handles the business logic and operations while keeping
 * the Socket value object pure and dependency-free.
 */
class SocketHandler
{
    /**
     * Handler for asynchronous socket operations.
     */
    private AsyncSocketOperations $operations;

    /**
     * Default byte size for read operations.
     */
    private const DEFAULT_BYTE_SIZE = 8192;

    /**
     * Creates a new SocketHandler instance.
     *
     * @param  AsyncSocketOperations  $operations  Handler for async operations
     */
    public function __construct(AsyncSocketOperations $operations)
    {
        $this->operations = $operations;
    }

    /**
     * Asynchronously reads data from a socket.
     *
     * @param  Socket  $socket  The socket to read from
     * @param  int|null  $length  Maximum number of bytes to read (default: 8192)
     * @param  float|null  $timeout  Timeout in seconds (default: 10.0)
     * @return PromiseInterface<string> Promise that resolves with the read data
     *
     * @throws SocketException If the socket is closed
     */
    public function read(Socket $socket, ?int $length = null, ?float $timeout = 10.0): PromiseInterface
    {
        if ($socket->isClosed()) {
            return $this->operations->getAsyncOps()->rejected(new SocketException('Socket is closed.'));
        }

        $readLength = $length ?? self::DEFAULT_BYTE_SIZE;

        return $this->operations->read($socket, $readLength, $timeout);
    }

    /**
     * Asynchronously writes data to a socket.
     *
     * @param  Socket  $socket  The socket to write to
     * @param  string  $data  The data to write
     * @param  float|null  $timeout  Timeout in seconds (default: 10.0)
     * @return PromiseInterface<int> Promise that resolves with the number of bytes written
     *
     * @throws SocketException If the socket is closed
     */
    public function write(Socket $socket, string $data, ?float $timeout = 10.0): PromiseInterface
    {
        if ($socket->isClosed()) {
            return $this->operations->getAsyncOps()->rejected(new SocketException('Socket is closed.'));
        }

        return $this->operations->write($socket, $data, $timeout);
    }

    /**
     * Closes a socket connection.
     *
     * @param  Socket  $socket  The socket to close
     * @return Socket A new Socket instance marked as closed
     */
    public function close(Socket $socket): Socket
    {
        if (!$socket->isClosed()) {
            $this->operations->close($socket);
            return $socket->withClosedStatus(true);
        }

        return $socket;
    }

    /**
     * Creates a socket with proper initialization.
     *
     * @param  resource  $resource  The socket resource
     * @param  array<string, mixed>  $metadata  Socket metadata
     * @return Socket The initialized socket
     */
    public function createSocket($resource, array $metadata = []): Socket
    {
        if (is_resource($resource)) {
            stream_set_blocking($resource, false);
        }

        return new Socket($resource, false, $metadata);
    }

    /**
     * Connect to a socket address and return a Socket value object.
     *
     * @param  string  $address  The socket address to connect to
     * @param  float|null  $timeout  Connection timeout in seconds
     * @param  array  $contextOptions  Additional context options
     * @return PromiseInterface<Socket> Promise that resolves with a Socket object
     */
    public function connect(string $address, ?float $timeout = 10.0, array $contextOptions = []): PromiseInterface
    {
        return $this->operations->connect($address, $timeout, $contextOptions);
    }

    /**
     * Get the underlying AsyncSocketOperations instance.
     *
     * @return AsyncSocketOperations
     */
    public function getOperations(): AsyncSocketOperations
    {
        return $this->operations;
    }
}