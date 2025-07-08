<?php
namespace Rcalicdan\FiberAsync;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Exceptions\ConnectionException;
use Rcalicdan\FiberAsync\Exceptions\SocketException;
use Rcalicdan\FiberAsync\Exceptions\TimeoutException;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\ValueObjects\Socket;
class AsyncSocketOperations
{
    private AsyncEventLoop $loop;
    private AsyncOperations $asyncOps;
    public function __construct()
    {
        $this->loop = AsyncEventLoop::getInstance();
        $this->asyncOps = new AsyncOperations();
    }
    public function getAsyncOps(): AsyncOperations
    {
        return $this->asyncOps;
    }
    public function connect(string $address, ?float $timeout = 10.0, array $contextOptions = []): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($address, $timeout, $contextOptions) {
            $context = stream_context_create($contextOptions);
            $socket = @stream_socket_client(
                $address,
                $errno,
                $errstr,
                0,
                STREAM_CLIENT_ASYNC_CONNECT,
                $context
            );
            if ($socket === false) {
                $reject(new ConnectionException("Failed to create socket: {$errstr}", $errno));
                return;
            }
            $timerId = null;
            $connectPromise = new AsyncPromise(function ($resolveConnect, $rejectConnect) use ($socket, &$timerId) {
                $this->loop->getSocketManager()->addWriteWatcher($socket, function () use ($socket, $resolveConnect, $rejectConnect, &$timerId) {
                    if ($timerId) {
                        $this->loop->cancelTimer($timerId);
                    }
                    if (stream_socket_get_name($socket, true) !== false) {
                        $resolveConnect(new Socket($socket, $this));
                    } else {
                        if (function_exists('socket_get_last_error')) {
                            $error = \socket_get_last_error($socket);
                            $errorMsg = \socket_strerror($error);
                            $rejectConnect(new ConnectionException("Connection failed: " . $errorMsg, $error));
                        } else {
                            $rejectConnect(new ConnectionException("Connection failed. Enable 'sockets' PHP extension for details."));
                        }
                    }
                });
            });
            if ($timeout > 0) {
                $timerId = $this->loop->addTimer($timeout, function () use ($socket, $reject, $address, $timeout) {
                    $this->loop->getSocketManager()->clearAllWatchersForSocket($socket);
                    @fclose($socket);
                    $reject(new TimeoutException("Connection to {$address} timed out after {$timeout} seconds."));
                });
            }
            $connectPromise->then(
                $resolve,
                function ($reason) use ($reject, $socket, &$timerId) {
                    if ($timerId) {
                        $this->loop->cancelTimer($timerId);
                    }
                    $this->loop->getSocketManager()->clearAllWatchersForSocket($socket);
                    @fclose($socket);
                    $reject($reason);
                }
            );
        });
    }
    public function read(Socket $client, int $length, ?float $timeout = 10.0): PromiseInterface
    {
        $readPromise = new AsyncPromise(function ($resolve, $reject) use ($client, $length) {
            $this->loop->getSocketManager()->addReadWatcher($client->getResource(), function () use ($client, $length, $resolve, $reject) {
                $data = @fread($client->getResource(), $length);
                if ($data === false) {
                    $reject(new SocketException("Failed to read from socket."));
                } elseif ($data === '' && feof($client->getResource())) {
                    $client->close();
                    $resolve(null);
                } else {
                    $resolve($data);
                }
            });
        });
        if ($timeout === null) {
            return $readPromise;
        }
        $timeoutPromise = $this->asyncOps->async(function () use ($timeout) {
            Async::await($this->asyncOps->delay($timeout));
            throw new TimeoutException("Read operation timed out after {$timeout} seconds.");
        })();
        return $this->asyncOps->race([$readPromise, $timeoutPromise]);
    }
    public function write(Socket $client, string $data, ?float $timeout = 10.0): PromiseInterface
    {
        $writePromise = new AsyncPromise(function ($resolve, $reject) use ($client, $data) {
            $this->writeAll($client, $data, $resolve, $reject);
        });
        if ($timeout === null) {
            return $writePromise;
        }
        $timeoutPromise = $this->asyncOps->async(function () use ($timeout) {
            Async::await($this->asyncOps->delay($timeout));
            throw new TimeoutException("Write operation timed out after {$timeout} seconds.");
        })();
        return $this->asyncOps->race([$writePromise, $timeoutPromise]);
    }
    private function writeAll(Socket $client, string $data, callable $resolve, callable $reject): void
    {
        $this->loop->getSocketManager()->addWriteWatcher($client->getResource(), function () use ($client, $data, $resolve, $reject) {
            $bytesToWrite = strlen($data);
            if ($bytesToWrite === 0) {
                $resolve(0);
                return;
            }
            $bytesWritten = @fwrite($client->getResource(), $data);
            if ($bytesWritten === false) {
                $reject(new SocketException("Failed to write to socket."));
                return;
            }
            if ($bytesWritten < $bytesToWrite) {
                $remainingData = substr($data, $bytesWritten);
                $this->writeAll($client, $remainingData, fn () => $resolve($bytesToWrite), $reject);
            } else {
                $resolve($bytesWritten);
            }
        });
    }
    public function close(Socket $client): void
    {
        if ($client->getResource()) {
            $this->loop->getSocketManager()->clearAllWatchersForSocket($client->getResource());
            @fclose($client->getResource());
        }
    }
}