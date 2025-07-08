<?php
// src/Database/MySQL/MySQLConnection.php

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
                    $sent = fwrite($this->stream, substr($data, $totalSent));
                    
                    if ($sent === false) {
                        throw new ConnectionException('Failed to send data');
                    }
                    
                    if ($sent === 0) {
                        // Would block, wait for stream to be writable
                        $this->waitForWriteable($data, $totalSent, $resolve, $reject);
                        return;
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

    private function waitForConnection(callable $resolve, callable $reject): void
    {
        $this->eventLoop->addStreamWatcher($this->stream, function () use ($resolve, $reject) {
            // Check if connection is established
            $socketName = stream_socket_get_name($this->stream, true);
            
            if ($socketName === false) {
                $reject(new ConnectionException('Connection failed'));
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
                    $this->waitForReadable($resolve, $reject);
                    return;
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
        $this->eventLoop->addStreamWatcher($this->stream, function () use ($data, $offset, $resolve, $reject) {
            try {
                $sent = fwrite($this->stream, substr($data, $offset));
                
                if ($sent === false) {
                    throw new ConnectionException('Failed to send data');
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