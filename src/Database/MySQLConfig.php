<?php

namespace Rcalicdan\FiberAsync\Database\Config;

final class MySQLConfig
{
    public readonly string $host;
    public readonly int $port;
    public readonly string $user;
    public readonly string $password;
    public readonly string $database;
    public readonly string $charset;
    public readonly float $timeout;

    // SSL/TLS Configuration
    public readonly bool $ssl;
    public readonly string $sslMode;
    public readonly ?string $sslCa;
    public readonly ?string $sslCert;
    public readonly ?string $sslKey;
    public readonly ?string $sslCipher;
    public readonly bool $sslVerify;

    // Connection Pool Configuration
    public readonly int $poolSize;
    public readonly int $maxIdleTime;
    public readonly int $maxLifetime;
    public readonly int $minConnections;
    public readonly int $maxConnections;

    // Retry Configuration
    public readonly int $maxRetries;
    public readonly float $retryDelay;
    public readonly float $retryBackoffMultiplier;
    public readonly float $maxRetryDelay;

    // Health Check Configuration
    public readonly bool $enableHealthCheck;
    public readonly int $healthCheckInterval;
    public readonly string $healthCheckQuery;

    // Query Cache Configuration
    public readonly bool $enableQueryCache;
    public readonly int $queryCacheSize;
    public readonly int $queryCacheTtl;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 3306,
        string $user = 'root',
        string $password = '',
        string $database = '',
        string $charset = 'utf8mb4',
        float $timeout = 10.0,

        // SSL/TLS
        bool $ssl = false,
        string $sslMode = 'preferred',
        ?string $sslCa = null,
        ?string $sslCert = null,
        ?string $sslKey = null,
        ?string $sslCipher = null,
        bool $sslVerify = true,

        // Connection Pool
        int $poolSize = 10,
        int $maxIdleTime = 600,
        int $maxLifetime = 3600,
        int $minConnections = 1,
        int $maxConnections = 50,

        // Retry
        int $maxRetries = 3,
        float $retryDelay = 0.1,
        float $retryBackoffMultiplier = 2.0,
        float $maxRetryDelay = 5.0,

        // Health Check
        bool $enableHealthCheck = true,
        int $healthCheckInterval = 30,
        string $healthCheckQuery = 'SELECT 1',

        // Query Cache
        bool $enableQueryCache = true,
        int $queryCacheSize = 1000,
        int $queryCacheTtl = 300
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->password = $password;
        $this->database = $database;
        $this->charset = $charset;
        $this->timeout = $timeout;

        $this->ssl = $ssl;
        $this->sslMode = $sslMode;
        $this->sslCa = $sslCa;
        $this->sslCert = $sslCert;
        $this->sslKey = $sslKey;
        $this->sslCipher = $sslCipher;
        $this->sslVerify = $sslVerify;

        $this->poolSize = $poolSize;
        $this->maxIdleTime = $maxIdleTime;
        $this->maxLifetime = $maxLifetime;
        $this->minConnections = $minConnections;
        $this->maxConnections = $maxConnections;

        $this->maxRetries = $maxRetries;
        $this->retryDelay = $retryDelay;
        $this->retryBackoffMultiplier = $retryBackoffMultiplier;
        $this->maxRetryDelay = $maxRetryDelay;

        $this->enableHealthCheck = $enableHealthCheck;
        $this->healthCheckInterval = $healthCheckInterval;
        $this->healthCheckQuery = $healthCheckQuery;

        $this->enableQueryCache = $enableQueryCache;
        $this->queryCacheSize = $queryCacheSize;
        $this->queryCacheTtl = $queryCacheTtl;
    }

    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'user' => $this->user,
            'password' => $this->password,
            'database' => $this->database,
            'charset' => $this->charset,
            'timeout' => $this->timeout,
            'ssl' => $this->ssl,
            'ssl_mode' => $this->sslMode,
            'ssl_ca' => $this->sslCa,
            'ssl_cert' => $this->sslCert,
            'ssl_key' => $this->sslKey,
            'ssl_cipher' => $this->sslCipher,
            'ssl_verify' => $this->sslVerify,
        ];
    }
}
