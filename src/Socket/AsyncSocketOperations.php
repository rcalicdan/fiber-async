<?php

namespace Rcalicdan\FiberAsync\Socket;

use Rcalicdan\FiberAsync\Async\AsyncOperations;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\EventLoop\ValueObjects\Socket;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;
use Rcalicdan\FiberAsync\Socket\Exceptions\ConnectionException;
use Rcalicdan\FiberAsync\Socket\Exceptions\SocketException;
use Rcalicdan\FiberAsync\Socket\Exceptions\TimeoutException;

/**
 * AsyncSocketOperations handles asynchronous socket operations using an event loop.
 * 
 * This class provides methods for connecting to sockets, reading from sockets,
 * writing to sockets, and closing sockets in a non-blocking manner using
 * promises and an event loop system.
 */
class AsyncSocketOperations
{
    /**
     * The event loop instance for managing asynchronous operations.
     * 
     * @var EventLoop
     */
    private EventLoop $loop;

    /**
     * Asynchronous operations helper.
     * 
     * @var AsyncOperations
     */
    private AsyncOperations $asyncOps;

    /**
     * Constructor initializes the event loop and async operations.
     */
    public function __construct()
    {
        $this->loop = EventLoop::getInstance();
        $this->asyncOps = new AsyncOperations;
    }

    /**
     * Get the async operations helper instance.
     * 
     * @return AsyncOperations The async operations instance
     */
    public function getAsyncOps(): AsyncOperations
    {
        return $this->asyncOps;
    }

    /**
     * Establish an asynchronous connection to a socket address.
     * 
     * Creates a non-blocking socket connection that resolves when the connection
     * is established or rejects if the connection fails or times out.
     * 
     * @param string $address The socket address to connect to (e.g., 'tcp://example.com:80')
     * @param float|null $timeout Connection timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @param array $contextOptions Additional context options for the socket connection
     * 
     * @return PromiseInterface A promise that resolves with a Socket object on successful connection
     * 
     * @throws ConnectionException If the socket cannot be created
     * @throws TimeoutException If the connection times out
     */
    public function connect(string $address, ?float $timeout = 10.0, array $contextOptions = []): PromiseInterface
    {
        return new Promise(function ($resolve, $reject) use ($address, $timeout, $contextOptions) {
            $context = stream_context_create($contextOptions);
            $socket = @stream_socket_client($address, $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT, $context);
            if ($socket === false) {
                $reject(new ConnectionException("Failed to create socket: {$errstr}", $errno));

                return;
            }

            $timerId = null;

            $connectCallback = function () use ($socket, $resolve, &$timerId) {
                if ($timerId) {
                    $this->loop->cancelTimer($timerId);
                    $timerId = null;
                }
                $this->loop->getSocketManager()->removeWriteWatcher($socket);
                $resolve(new Socket($socket, $this));
            };

            $this->loop->getSocketManager()->addWriteWatcher($socket, $connectCallback);

            if ($timeout > 0) {
                $timerId = $this->loop->addTimer($timeout, function () use ($socket, $reject, $address, $timeout) {
                    $this->loop->getSocketManager()->removeWriteWatcher($socket);
                    @fclose($socket);
                    $reject(new TimeoutException("Connection to {$address} timed out after {$timeout} seconds."));
                });
            }
        });
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
     * @throws SocketException If reading from the socket fails
     * @throws TimeoutException If the read operation times out
     */
    public function read(Socket $client, int $length, ?float $timeout = 10.0): PromiseInterface
    {
        return new Promise(function ($resolve, $reject) use ($client, $length, $timeout) {
            $socketResource = $client->getResource();
            $timerId = null;

            $readCallback = function () use ($client, $length, $resolve, $reject, &$timerId) {
                if ($timerId) {
                    $this->loop->cancelTimer($timerId);
                    $timerId = null;
                }
                // Watcher is one-shot, so it's already removed by the manager.

                $data = @fread($client->getResource(), $length);
                if ($data === false) {
                    $reject(new SocketException('Failed to read from socket.'));
                } elseif ($data === '' && feof($client->getResource())) {
                    $client->close();
                    $resolve(null);
                } else {
                    $resolve($data);
                }
            };

            $this->loop->getSocketManager()->addReadWatcher($socketResource, $readCallback);

            if ($timeout > 0) {
                $timerId = $this->loop->addTimer($timeout, function () use ($reject, $socketResource, $timeout) {
                    $this->loop->getSocketManager()->removeReadWatcher($socketResource);
                    $reject(new TimeoutException("Read operation timed out after {$timeout} seconds."));
                });
            }
        });
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
     * @throws SocketException If writing to the socket fails
     * @throws TimeoutException If the write operation times out
     */
    public function write(Socket $client, string $data, ?float $timeout = 10.0): PromiseInterface
    {
        return new Promise(function ($resolve, $reject) use ($client, $data, $timeout) {
            $timerId = null;

            // Start the robust, recursive write operation.
            $this->performWrite($client, $data, $resolve, $reject, $timerId);

            if ($timeout > 0) {
                $timerId = $this->loop->addTimer($timeout, function () use ($client, $reject) {
                    // On timeout, clear any pending watchers for this socket.
                    $this->loop->getSocketManager()->removeWriteWatcher($client->getResource());
                    $reject(new TimeoutException('Write operation timed out.'));
                });
            }
        });
    }

    /**
     * Perform the actual write operation with support for partial writes.
     * 
     * This private method handles the recursive writing of data, continuing
     * to write remaining data if the initial write is partial.
     * 
     * @param Socket $client The socket to write to
     * @param string $data The data to write
     * @param callable $resolve Promise resolve callback
     * @param callable $reject Promise reject callback
     * @param string|null $timerId Reference to the timer ID for timeout management
     * 
     * @return void
     * 
     * @throws SocketException If writing to the socket fails
     */
    private function performWrite(Socket $client, string $data, callable $resolve, callable $reject, ?string &$timerId): void
    {
        $this->loop->getSocketManager()->addWriteWatcher($client->getResource(), function () use ($client, $data, $resolve, $reject, &$timerId) {
            $bytesWritten = @fwrite($client->getResource(), $data);

            if ($bytesWritten === false) {
                if ($timerId) {
                    $this->loop->cancelTimer($timerId);
                }
                $reject(new SocketException('Failed to write to socket.'));

                return;
            }

            if ($bytesWritten === strlen($data)) {
                if ($timerId) {
                    $this->loop->cancelTimer($timerId);
                }
                $resolve($bytesWritten);

                return;
            }

            $remainingData = substr($data, $bytesWritten);
            $this->performWrite($client, $remainingData, $resolve, $reject, $timerId);
        });
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
    public function close(Socket $client): void
    {
        if ($client->getResource() && ! $client->isClosed()) {
            $this->loop->getSocketManager()->clearAllWatchersForSocket($client->getResource());
            @fclose($client->getResource());
        }
    }
}