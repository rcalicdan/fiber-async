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
 * AsyncSocketOperations - Single entry point for all asynchronous socket operations.
 *
 * This class provides methods for connecting to sockets, reading from sockets,
 * writing to sockets, creating sockets, and closing sockets in a non-blocking manner
 * using promises and an event loop system.
 */
class AsyncSocketOperations
{
    /**
     * The event loop instance for managing asynchronous operations.
     */
    private EventLoop $loop;

    /**
     * Asynchronous operations helper.
     */
    private AsyncOperations $asyncOps;

    /**
     * Default byte size for read operations.
     */
    private const DEFAULT_BYTE_SIZE = 8192;

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
     * Create a Socket value object from a resource.
     *
     * @param  resource  $resource  The socket resource
     * @param  array<string, mixed>  $metadata  Socket metadata
     * @return Socket The created Socket value object
     */
    public function createSocket($resource, array $metadata = []): Socket
    {
        if (is_resource($resource)) {
            stream_set_blocking($resource, false);
        }

        return new Socket($resource, false, $metadata);
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
    public function connect(string $address, ?float $timeout = 10.0, array $contextOptions = []): PromiseInterface
    {
        return new Promise(function ($resolve, $reject) use ($address, $timeout, $contextOptions) {
            $context = stream_context_create($contextOptions);
            $resource = @stream_socket_client($address, $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT, $context);
            if ($resource === false) {
                $reject(new ConnectionException("Failed to create socket: {$errstr}", $errno));
                return;
            }

            stream_set_blocking($resource, false);

            $timerId = null;

            $metadata = $this->parseAddressMetadata($address);

            $connectCallback = function () use ($resource, $resolve, &$timerId, $metadata) {
                if ($timerId) {
                    $this->loop->cancelTimer($timerId);
                    $timerId = null;
                }
                $this->loop->getSocketManager()->removeWriteWatcher($resource);
                
                $socket = new Socket($resource, false, $metadata);
                $resolve($socket);
            };

            $this->loop->getSocketManager()->addWriteWatcher($resource, $connectCallback);

            if ($timeout > 0) {
                $timerId = $this->loop->addTimer($timeout, function () use ($resource, $reject, $address, $timeout) {
                    $this->loop->getSocketManager()->removeWriteWatcher($resource);
                    @fclose($resource);
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
     * @param  Socket  $socket  The socket to read from
     * @param  int|null  $length  Maximum number of bytes to read (default: 8192)
     * @param  float|null  $timeout  Read timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @return PromiseInterface<string|null> A promise that resolves with the data read, or null if socket is closed
     *
     * @throws SocketException If the socket is closed or reading fails
     * @throws TimeoutException If the read operation times out
     */
    public function read(Socket $socket, ?int $length = null, ?float $timeout = 10.0): PromiseInterface
    {
        if ($socket->isClosed()) {
            return $this->asyncOps->rejected(new SocketException('Socket is closed.'));
        }

        $readLength = $length ?? self::DEFAULT_BYTE_SIZE;

        return new Promise(function ($resolve, $reject) use ($socket, $readLength, $timeout) {
            $socketResource = $socket->getResource();
            $timerId = null;

            $readCallback = function () use ($socket, $readLength, $resolve, $reject, &$timerId) {
                if ($timerId) {
                    $this->loop->cancelTimer($timerId);
                    $timerId = null;
                }

                $data = @fread($socket->getResource(), $readLength);
                if ($data === false) {
                    $reject(new SocketException('Failed to read from socket.'));
                } elseif ($data === '' && feof($socket->getResource())) {
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
     * @param  Socket  $socket  The socket to write to
     * @param  string  $data  The data to write
     * @param  float|null  $timeout  Write timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @return PromiseInterface<int> A promise that resolves with the number of bytes written
     *
     * @throws SocketException If the socket is closed or writing fails
     * @throws TimeoutException If the write operation times out
     */
    public function write(Socket $socket, string $data, ?float $timeout = 10.0): PromiseInterface
    {
        if ($socket->isClosed()) {
            return $this->asyncOps->rejected(new SocketException('Socket is closed.'));
        }

        return new Promise(function ($resolve, $reject) use ($socket, $data, $timeout) {
            $timerId = null;
            $totalBytesWritten = 0;

            // Start the robust, recursive write operation.
            $this->performWrite($socket, $data, $resolve, $reject, $timerId, $totalBytesWritten);

            if ($timeout > 0) {
                $timerId = $this->loop->addTimer($timeout, function () use ($socket, $reject) {
                    // On timeout, clear any pending watchers for this socket.
                    $this->loop->getSocketManager()->removeWriteWatcher($socket->getResource());
                    $reject(new TimeoutException('Write operation timed out.'));
                });
            }
        });
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
    public function close(Socket $socket): Socket
    {
        if (!$socket->isClosed() && $socket->getResource()) {
            $this->loop->getSocketManager()->clearAllWatchersForSocket($socket->getResource());
            @fclose($socket->getResource());
        }

        return $socket->withClosedStatus(true);
    }

    /**
     * Perform the actual write operation with support for partial writes.
     *
     * This private method handles the recursive writing of data, continuing
     * to write remaining data if the initial write is partial.
     *
     * @param  Socket  $socket  The socket to write to
     * @param  string  $data  The data to write
     * @param  callable  $resolve  Promise resolve callback
     * @param  callable  $reject  Promise reject callback
     * @param  string|null  $timerId  Reference to the timer ID for timeout management
     * @param  int  $totalBytesWritten  Total bytes written so far
     *
     * @throws SocketException If writing to the socket fails
     */
    private function performWrite(Socket $socket, string $data, callable $resolve, callable $reject, ?string &$timerId, int &$totalBytesWritten): void
    {
        $this->loop->getSocketManager()->addWriteWatcher($socket->getResource(), function () use ($socket, $data, $resolve, $reject, &$timerId, &$totalBytesWritten) {
            $bytesWritten = @fwrite($socket->getResource(), $data);

            if ($bytesWritten === false) {
                if ($timerId) {
                    $this->loop->cancelTimer($timerId);
                }
                $reject(new SocketException('Failed to write to socket.'));
                return;
            }

            $totalBytesWritten += $bytesWritten;

            if ($bytesWritten === strlen($data)) {
                if ($timerId) {
                    $this->loop->cancelTimer($timerId);
                }
                $resolve($totalBytesWritten);
                return;
            }

            $remainingData = substr($data, $bytesWritten);
            $this->performWrite($socket, $remainingData, $resolve, $reject, $timerId, $totalBytesWritten);
        });
    }

    /**
     * Parse address metadata from address string.
     *
     * @param  string  $address  The address string (e.g., 'tcp://example.com:80')
     * @return array<string, mixed> Parsed metadata
     */
    private function parseAddressMetadata(string $address): array
    {
        $metadata = ['address' => $address];

        $parsed = parse_url($address);
        if ($parsed !== false) {
            if (isset($parsed['scheme'])) {
                $metadata['type'] = $parsed['scheme'];
            }
            if (isset($parsed['host'])) {
                $metadata['host'] = $parsed['host'];
            }
            if (isset($parsed['port'])) {
                $metadata['port'] = $parsed['port'];
            }
            if (isset($parsed['path'])) {
                $metadata['path'] = $parsed['path'];
            }
        }

        return $metadata;
    }
}