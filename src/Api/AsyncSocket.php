<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\EventLoop\ValueObjects\Socket;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Socket\AsyncSocketOperations;

/**
 * AsyncSocket provides a fluent interface for asynchronous socket operations.
 * 
 * This class serves as a facade for AsyncSocketOperations, providing static
 * methods for common socket operations while maintaining a singleton pattern
 * for the underlying operations instance.
 * 
 * @package Rcalicdan\FiberAsync\Api
 */
final class AsyncSocket
{
    /**
     * Singleton instance of AsyncSocketOperations.
     * 
     * @var AsyncSocketOperations|null
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
     * 
     * @return void
     */
    public static function reset(): void
    {
        self::$ops = null;
    }

    /**
     * Establish an asynchronous connection to a socket address.
     * 
     * This method returns a Promise that resolves when the connection
     * is successfully established or rejects if the connection fails
     * or times out.
     * 
     * @param string $address The socket address to connect to (e.g., 'tcp://example.com:80')
     * @param float|null $timeout Connection timeout in seconds. Defaults to 10.0 seconds
     * @param array $contextOptions Additional context options for the socket connection
     * 
     * @return PromiseInterface A promise that resolves with the connected socket resource
     * 
     * @throws \Exception If the connection fails or times out
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
     * @param Socket $client The socket to read from
     * @param int $length Maximum number of bytes to read
     * @param float|null $timeout Read timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * 
     * @return PromiseInterface A promise that resolves with the data read, or null if socket is closed
     * 
     * @throws \Exception If reading from the socket fails or times out
     */
    public static function read(Socket $client, int $length, ?float $timeout = 10.0): PromiseInterface
    {
        return self::getInstance()->read($client, $length, $timeout);
    }

    /**
     * Write data asynchronously to a socket.
     * 
     * Writes the specified data to the socket. Handles partial writes by
     * recursively continuing until all data is written.
     * 
     * @param Socket $client The socket to write to
     * @param string $data The data to write
     * @param float|null $timeout Write timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * 
     * @return PromiseInterface A promise that resolves with the number of bytes written
     * 
     * @throws \Exception If writing to the socket fails or times out
     */
    public static function write(Socket $client, string $data, ?float $timeout = 10.0): PromiseInterface
    {
        return self::getInstance()->write($client, $data, $timeout);
    }

    /**
     * Close a socket connection and clean up resources.
     * 
     * Closes the socket connection and removes all event loop watchers
     * associated with the socket to prevent memory leaks.
     * 
     * @param Socket $client The socket to close
     * 
     * @return void
     */
    public static function close(Socket $client): void
    {
        self::getInstance()->close($client);
    }
}
