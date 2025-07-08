<?php
// src/Database/MySQL/MySQLConnection.php

namespace Rcalicdan\FiberAsync\Database\MySQL;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\Config\DatabaseConfig;
use Rcalicdan\FiberAsync\Database\Contracts\ConnectionInterface;
use Rcalicdan\FiberAsync\Database\Exceptions\ConnectionException;

class MySQLConnection implements ConnectionInterface
{
    private $socket;
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
                $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                
                if ($this->socket === false) {
                    throw new ConnectionException('Failed to create socket: ' . socket_strerror(socket_last_error()));
                }
                
                socket_set_nonblock($this->socket);
                socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
                socket_set_option($this->socket, SOL_SOCKET, SO_KEEPALIVE, 1);
                
                $result = socket_connect($this->socket, $this->config->host, $this->config->port);
                
                if ($result === false) {
                    $error = socket_last_error($this->socket);
                    if ($error !== SOCKET_EINPROGRESS && $error !== SOCKET_EALREADY && $error !== SOCKET_EWOULDBLOCK) {
                        throw new ConnectionException('Failed to connect: ' . socket_strerror($error));
                    }
                }
                
                $this->waitForConnection($resolve, $reject);
                
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
                $totalSent = 0;
                $dataLength = strlen($data);
                
                while ($totalSent < $dataLength) {
                    $sent = socket_write($this->socket, substr($data, $totalSent));
                    
                    if ($sent === false) {
                        $error = socket_last_error($this->socket);
                        if ($error === SOCKET_EWOULDBLOCK || $error === SOCKET_EAGAIN) {
                            $this->waitForWriteable($data, $totalSent, $resolve, $reject);
                            return;
                        }
                        throw new ConnectionException('Failed to send data: ' . socket_strerror($error));
                    }
                    
                    $totalSent += $sent;
                }
                
                $resolve($totalSent);
                
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
            
            $this->waitForReadable($resolve, $reject);
        });
    }

    public function close(): void
    {
        if ($this->socket) {
            socket_close($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function getSocket()
    {
        return $this->socket;
    }

    private function waitForConnection(callable $resolve, callable $reject): void
    {
        $this->eventLoop->addStreamWatcher($this->socket, function () use ($resolve, $reject) {
            $error = socket_last_error($this->socket);
            
            if ($error !== 0) {
                $reject(new ConnectionException('Connection failed: ' . socket_strerror($error)));
                return;
            }
            
            $this->connected = true;
            $this->performHandshake($resolve, $reject);
        });
    }

    private function performHandshake(callable $resolve, callable $reject): void
    {
        $this->readPacket()->then(function ($handshakeData) use ($resolve, $reject) {
            try {
                $handshake = $this->protocol->parseHandshake($handshakeData);
                
                $authPacket = $this->protocol->createAuthPacket(
                    $this->config->username,
                    $this->config->password,
                    $this->config->database,
                    $handshake
                );
                
                $this->sendPacket($authPacket)->then(function () use ($resolve, $reject) {
                    $this->readPacket()->then(function ($authResponse) use ($resolve, $reject) {
                        $result = $this->protocol->parseResult($authResponse);
                        
                        if ($result['type'] === 'error') {
                            $reject(new ConnectionException("Authentication failed: {$result['message']}"));
                        } else {
                            $resolve($this);
                        }
                    }, $reject);
                }, $reject);
                
            } catch (\Throwable $e) {
                $reject($e);
            }
        }, $reject);
    }

    private function waitForReadable(callable $resolve, callable $reject): void
    {
        $this->eventLoop->addStreamWatcher($this->socket, function () use ($resolve, $reject) {
            try {
                $data = socket_read($this->socket, 4096);
                
                if ($data === false) {
                    $error = socket_last_error($this->socket);
                    if ($error === SOCKET_EWOULDBLOCK || $error === SOCKET_EAGAIN) {
                        $this->waitForReadable($resolve, $reject);
                        return;
                    }
                    throw new ConnectionException('Failed to read data: ' . socket_strerror($error));
                }
                
                if ($data === '') {
                    throw new ConnectionException('Connection closed by server');
                }
                
                $this->readBuffer[] = $data;
                $completePacket = $this->tryParsePacket();
                
                if ($completePacket !== null) {
                    $resolve($completePacket);
                } else {
                    $this->waitForReadable($resolve, $reject);
                }
                
            } catch (\Throwable $e) {
                $reject($e);
            }
        });
    }

    private function waitForWriteable(string $data, int $offset, callable $resolve, callable $reject): void
    {
        $this->eventLoop->addStreamWatcher($this->socket, function () use ($data, $offset, $resolve, $reject) {
            try {
                $sent = socket_write($this->socket, substr($data, $offset));
                
                if ($sent === false) {
                    $error = socket_last_error($this->socket);
                    if ($error === SOCKET_EWOULDBLOCK || $error === SOCKET_EAGAIN) {
                        $this->waitForWriteable($data, $offset, $resolve, $reject);
                        return;
                    }
                    throw new ConnectionException('Failed to send data: ' . socket_strerror($error));
                }
                
                $newOffset = $offset + $sent;
                
                if ($newOffset < strlen($data)) {
                    $this->waitForWriteable($data, $newOffset, $resolve, $reject);
                } else {
                    $resolve(strlen($data));
                }
                
            } catch (\Throwable $e) {
                $reject($e);
            }
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
            $this->expectedPacketLength = unpack('V', $buffer . "\0")[1] & 0xFFFFFF;
        }
        
        $totalPacketLength = $this->expectedPacketLength + 4;
        
        if (strlen($buffer) >= $totalPacketLength) {
            $packet = substr($buffer, 0, $totalPacketLength);
            $this->readBuffer = [substr($buffer, $totalPacketLength)];
            $this->expectedPacketLength = 0;
            
            return $packet;
        }
        
        return null;
    }
}