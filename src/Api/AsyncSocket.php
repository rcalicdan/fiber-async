<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\Async\AsyncOperations;
use Rcalicdan\FiberAsync\EventLoop\ValueObjects\Socket;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Socket\AsyncSocketOperations;
use Rcalicdan\FiberAsync\Socket\Exceptions\ConnectionException;
use Rcalicdan\FiberAsync\Socket\Exceptions\SocketException;
use Rcalicdan\FiberAsync\Socket\Exceptions\TimeoutException;

/**
 * AsyncSocket provides a fluent interface for asynchronous socket operations.
 *
 * This class serves as a facade for AsyncSocketOperations, providing static
 * methods for common socket operations while maintaining a singleton pattern
 * for the underlying operations instance.
 */
final class AsyncSocket
{
    /**
     * Singleton instance of AsyncSocketOperations.
     */
    private static ?AsyncSocketOperations $ops = null;

    /**
     * Get the singleton instance of AsyncSocketOperations.
     *
     * Creates a new instance if one doesn't exist yet.
     *
     * @return AsyncSocketOperations The singleton instance
     */
    protected static function getInstance(): AsyncSocketOperations
    {
        if (self::$ops === null) {
            self::$ops = new AsyncSocketOperations;
        }

        return self::$ops;
    }

    /**
     * Reset the singleton instance.
     *
     * This method clears the current instance, allowing a fresh instance
     * to be created on the next call to getInstance().
     */
    public static function reset(): void
    {
        self::$ops = null;
    }

    /**
     * Get the async operations helper instance.
     *
     * @return AsyncOperations The async operations instance
     */
    public static function getAsyncOps(): AsyncOperations
    {
        return self::getInstance()->getAsyncOps();
    }

    /**
     * Create a Socket value object from a resource.
     *
     * @param  resource  $resource  The socket resource
     * @param  array<string, mixed>  $metadata  Socket metadata
     * @return Socket The created Socket value object
     */
    public static function createSocket($resource, array $metadata = []): Socket
    {
        return self::getInstance()->createSocket($resource, $metadata);
    }

    /**
     * Establish an asynchronous connection to a socket address.
     *
     * Creates a non-blocking socket connection that resolves when the connection
     * is established or rejects if the connection fails or times out.
     *
     * @param  string  $address  The socket address to connect to (e.g., 'tcp://example.com:80')
     * @param  float|null  $timeout  Connection timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @param  array  $contextOptions  Additional context options for the socket connection
     * @return PromiseInterface<Socket> A promise that resolves with a Socket object on successful connection
     *
     * @throws ConnectionException If the socket cannot be created
     * @throws TimeoutException If the connection times out
     */
    public static function connect(string $address, ?float $timeout = 10.0, array $contextOptions = []): PromiseInterface
    {
        return self::getInstance()->connect($address, $timeout, $contextOptions);
    }

    /**
     * Read data asynchronously from a socket.
     *
     * Reads up to the specified number of bytes from the socket. The promise
     * resolves when data is available or the socket is closed.
     *
     * @param  Socket  $socket  The socket to read from
     * @param  int|null  $length  Maximum number of bytes to read (default: 8192)
     * @param  float|null  $timeout  Read timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @return PromiseInterface<string|null> A promise that resolves with the data read, or null if socket is closed
     *
     * @throws SocketException If the socket is closed or reading fails
     * @throws TimeoutException If the read operation times out
     */
    public static function read(Socket $socket, ?int $length = null, ?float $timeout = 10.0): PromiseInterface
    {
        return self::getInstance()->read($socket, $length, $timeout);
    }

    /**
     * Write data asynchronously to a socket.
     *
     * Writes the specified data to the socket. Handles partial writes by
     * recursively continuing until all data is written.
     *
     * @param  Socket  $socket  The socket to write to
     * @param  string  $data  The data to write
     * @param  float|null  $timeout  Write timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @return PromiseInterface<int> A promise that resolves with the number of bytes written
     *
     * @throws SocketException If the socket is closed or writing fails
     * @throws TimeoutException If the write operation times out
     */
    public static function write(Socket $socket, string $data, ?float $timeout = 10.0): PromiseInterface
    {
        return self::getInstance()->write($socket, $data, $timeout);
    }

    /**
     * Close a socket connection and return a new Socket instance marked as closed.
     *
     * Closes the socket connection and removes all event loop watchers
     * associated with the socket to prevent memory leaks.
     *
     * @param  Socket  $socket  The socket to close
     * @return Socket A new Socket instance marked as closed
     */
    public static function close(Socket $socket): Socket
    {
        return self::getInstance()->close($socket);
    }
}
