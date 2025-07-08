<?php

namespace Rcalicdan\FiberAsync\Database\MySQL;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\Config\DatabaseConfig;
use Rcalicdan\FiberAsync\Contracts\Database\ConnectionInterface;
use Rcalicdan\FiberAsync\Database\Exceptions\ConnectionException;

class MySQLConnection implements ConnectionInterface
{
    private $stream;
    private bool $connected = false;
    private DatabaseConfig $config;
    private MySQLProtocol $protocol;
    private AsyncEventLoop $eventLoop;
    private array $readBuffer = [];
    private int $expectedPacketLength = 0;

    public function __construct(DatabaseConfig $config)
    {
        $this->config = $config;
        $this->protocol = new MySQLProtocol();
        $this->eventLoop = AsyncEventLoop::getInstance();
    }

    public function connect(): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) {
            try {
                $context = stream_context_create([
                    'socket' => [
                        'tcp_nodelay' => true,
                        'so_reuseport' => true,
                    ],
                ]);

                $this->stream = stream_socket_client(
                    "tcp://{$this->config->host}:{$this->config->port}",
                    $errno,
                    $errstr,
                    $this->config->timeout,
                    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                    $context
                );

                if (!$this->stream) {
                    throw new ConnectionException("Failed to connect: $errstr ($errno)");
                }

                stream_set_blocking($this->stream, false);

                $this->waitForConnection()
                    ->then(function () {
                        return $this->performHandshake();
                    })
                    ->then(function () {
                        return $this;
                    })
                    ->then($resolve, $reject);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });
    }

    public function sendPacket(string $data): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($data) {
            if (!$this->connected) {
                $reject(new ConnectionException('Not connected'));
                return;
            }

            try {
                $this->writeData($data, 0)
                    ->then(function ($totalSent) use ($resolve) {
                        $resolve($totalSent);
                    }, $reject);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });
    }

    public function readPacket(): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) {
            if (!$this->connected) {
                $reject(new ConnectionException('Not connected'));
                return;
            }

            $this->waitForReadable()
                ->then($resolve, $reject);
        });
    }

    public function close(): void
    {
        if ($this->stream) {
            fclose($this->stream);
            $this->stream = null;
        }
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->stream && !feof($this->stream);
    }

    public function getSocket()
    {
        return $this->stream;
    }

    private function waitForConnection(): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) {
            $this->eventLoop->addStreamWatcher($this->stream, function () use ($resolve, $reject) {
                $socketName = stream_socket_get_name($this->stream, true);

                if ($socketName === false) {
                    $reject(new ConnectionException('Connection failed'));
                    return;
                }

                $this->connected = true;
                $resolve(true);
            });
        });
    }

    private function performHandshake(): PromiseInterface
    {
        return $this->readPacket()
            ->then(function ($handshakeData) {
                $handshake = $this->protocol->parseHandshake($handshakeData);

                $authPacket = $this->protocol->createAuthPacket(
                    $this->config->username,
                    $this->config->password,
                    $this->config->database,
                    $handshake
                );

                return $this->sendPacket($authPacket);
            })
            ->then(function () {
                return $this->readPacket();
            })
            ->then(function ($authResponse) {
                $result = $this->protocol->parseResult($authResponse);

                if ($result['type'] === 'error') {
                    throw new ConnectionException("Authentication failed: {$result['message']}");
                }

                return true;
            });
    }

    private function writeData(string $data, int $offset): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($data, $offset) {
            $dataLength = strlen($data);
            
            if ($offset >= $dataLength) {
                $resolve($dataLength);
                return;
            }

            $sent = fwrite($this->stream, substr($data, $offset));

            if ($sent === false) {
                $reject(new ConnectionException('Failed to send data'));
                return;
            }

            if ($sent === 0) {
                $this->waitForWriteable()
                    ->then(function () use ($data, $offset, $resolve, $reject) {
                        $this->writeData($data, $offset)
                            ->then($resolve, $reject);
                    }, $reject);
                return;
            }

            $newOffset = $offset + $sent;
            
            if ($newOffset < $dataLength) {
                $this->writeData($data, $newOffset)
                    ->then($resolve, $reject);
            } else {
                $resolve($dataLength);
            }
        });
    }

    private function waitForWriteable(): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) {
            $this->eventLoop->addStreamWatcher($this->stream, function () use ($resolve, $reject) {
                try {
                    $resolve(true);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        });
    }

    private function waitForReadable(): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) {
            $this->eventLoop->addStreamWatcher($this->stream, function () use ($resolve, $reject) {
                try {
                    $data = fread($this->stream, 4096);

                    if ($data === false) {
                        throw new ConnectionException('Failed to read data');
                    }

                    if ($data === '') {
                        if (feof($this->stream)) {
                            throw new ConnectionException('Connection closed by server');
                        }
                        // No data available, wait again
                        $this->waitForReadable()
                            ->then($resolve, $reject);
                        return;
                    }

                    $this->readBuffer[] = $data;
                    $completePacket = $this->tryParsePacket();

                    if ($completePacket !== null) {
                        $resolve($completePacket);
                    } else {
                        $this->waitForReadable()
                            ->then($resolve, $reject);
                    }
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        });
    }

    private function tryParsePacket(): ?string
    {
        if (empty($this->readBuffer)) {
            return null;
        }

        $buffer = implode('', $this->readBuffer);

        if (strlen($buffer) < 4) {
            return null;
        }

        if ($this->expectedPacketLength === 0) {
            $lengthBytes = substr($buffer, 0, 3);
            $this->expectedPacketLength = unpack('V', $lengthBytes . "\0")[1];
        }

        $totalPacketLength = $this->expectedPacketLength + 4;

        if (strlen($buffer) >= $totalPacketLength) {
            $packet = substr($buffer, 0, $totalPacketLength);
            $remaining = substr($buffer, $totalPacketLength);

            $this->readBuffer = $remaining ? [$remaining] : [];
            $this->expectedPacketLength = 0;

            return $packet;
        }

        return null;
    }
}