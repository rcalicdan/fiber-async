<?php
// src/Database/MySQL/MySQLConnectionPool.php

namespace Rcalicdan\FiberAsync\Database\MySQL;

use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\Config\DatabaseConfig;
use Rcalicdan\FiberAsync\Database\Exceptions\ConnectionException;

class MySQLConnectionPool
{
    private DatabaseConfig $config;
    private array $availableConnections = [];
    private array $busyConnections = [];
    private array $stats = [
        'connections_created' => 0,
        'connections_reused' => 0,
        'connections_closed' => 0,
        'pool_hits' => 0,
        'pool_misses' => 0,
    ];

    public function __construct(DatabaseConfig $config)
    {
        $this->config = $config;
    }

    public function getConnection(): PromiseInterface
    {
        if (!empty($this->availableConnections)) {
            $connection = array_pop($this->availableConnections);
            $this->busyConnections[] = $connection;
            $this->stats['connections_reused']++;
            $this->stats['pool_hits']++;
            return AsyncPromise::resolve($connection);
        }
        
        if (count($this->busyConnections) >= $this->config->maxConnections) {
            return AsyncPromise::reject(new ConnectionException('Maximum connections reached'));
        }
        
        $this->stats['pool_misses']++;
        return $this->createConnection();
    }

    public function releaseConnection(MySQLConnection $connection): void
    {
        $key = array_search($connection, $this->busyConnections, true);
        
        if ($key !== false) {
            unset($this->busyConnections[$key]);
            $this->busyConnections = array_values($this->busyConnections);
            
            if ($connection->isConnected()) {
                $this->availableConnections[] = $connection;
            }
        }
    }

    public function closeAll(): void
    {
        foreach ($this->availableConnections as $connection) {
            $connection->close();
            $this->stats['connections_closed']++;
        }
        
        foreach ($this->busyConnections as $connection) {
            $connection->close();
            $this->stats['connections_closed']++;
        }
        
        $this->availableConnections = [];
        $this->busyConnections = [];
    }

    public function hasConnections(): bool
    {
        return !empty($this->availableConnections) || !empty($this->busyConnections);
    }

    public function getStats(): array
    {
        return array_merge($this->stats, [
            'available_connections' => count($this->availableConnections),
            'busy_connections' => count($this->busyConnections),
            'total_connections' => count($this->availableConnections) + count($this->busyConnections),
        ]);
    }

    private function createConnection(): PromiseInterface
    {
        $connection = new MySQLConnection($this->config);
        
        return $connection->connect()->then(function (MySQLConnection $connection) {
            $this->busyConnections[] = $connection;
            $this->stats['connections_created']++;
            return $connection;
        });
    }
}