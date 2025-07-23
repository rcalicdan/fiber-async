<?php

namespace Rcalicdan\FiberAsync\Socket;

use Rcalicdan\FiberAsync\Async\AsyncOperations;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\EventLoop\ValueObjects\Socket;
use Rcalicdan\FiberAsync\Promise\Promise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Socket\Exceptions\ConnectionException;
use Rcalicdan\FiberAsync\Socket\Exceptions\SocketException;
use Rcalicdan\FiberAsync\Socket\Exceptions\TimeoutException;

class AsyncSocketOperations
{
    private EventLoop $loop;
    private AsyncOperations $asyncOps;

    public function __construct()
    {
        $this->loop = EventLoop::getInstance();
        $this->asyncOps = new AsyncOperations;
    }

    public function getAsyncOps(): AsyncOperations
    {
        return $this->asyncOps;
    }

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

    public function close(Socket $client): void
    {
        if ($client->getResource() && ! $client->isClosed()) {
            $this->loop->getSocketManager()->clearAllWatchersForSocket($client->getResource());
            @fclose($client->getResource());
        }
    }
}
