<?php

namespace Rcalicdan\FiberAsync\Database\MySQL\ValueObjects;

use Dotenv\Dotenv;

final class MysqlConfig
{
    public string $host;
    public int $port;
    public string $user;
    public string $password;
    public string $database;
    public bool $sslEnabled;
    public ?string $sslKey;
    public ?string $sslCert;
    public ?string $sslCa;
    public bool $sslVerifyPeer;

    private static bool $envLoaded = false;

    public function __construct(
        string $host,
        int $port,
        string $user,
        string $password,
        string $database = '',
        bool $sslEnabled = false,
        ?string $sslKey = null,
        ?string $sslCert = null,
        ?string $sslCa = null,
        bool $sslVerifyPeer = true
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->password = $password;
        $this->database = $database;
        $this->sslEnabled = $sslEnabled;
        $this->sslKey = $sslKey;
        $this->sslCert = $sslCert;
        $this->sslCa = $sslCa;
        $this->sslVerifyPeer = $sslVerifyPeer;
    }

    public static function fromEnv(): self
    {
        self::loadEnv();

        return new self(
            $_ENV['DB_HOST'] ?? '127.0.0.1',
            (int) ($_ENV['DB_PORT'] ?? 3306),
            $_ENV['DB_USERNAME'] ?? 'root',
            $_ENV['DB_PASSWORD'] ?? '',
            $_ENV['DB_DATABASE'] ?? '',
            filter_var($_ENV['DB_SSL_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
            $_ENV['DB_SSL_KEY'] ?? null,
            $_ENV['DB_SSL_CERT'] ?? null,
            $_ENV['DB_SSL_CA'] ?? null,
            filter_var($_ENV['DB_SSL_VERIFY_PEER'] ?? true, FILTER_VALIDATE_BOOLEAN)
        );
    }

    public function getConnectionString(): string
    {
        return "tcp://{$this->host}:{$this->port}";
    }

    public function getSslContextOptions(): array
    {
        if (!$this->sslEnabled) {
            return [];
        }

        $context = [
            'verify_peer' => $this->sslVerifyPeer,
            'verify_peer_name' => $this->sslVerifyPeer,
        ];

        if ($this->sslKey) {
            $context['local_key'] = $this->sslKey;
        }
        if ($this->sslCert) {
            $context['local_cert'] = $this->sslCert;
        }
        if ($this->sslCa) {
            $context['cafile'] = $this->sslCa;
        }

        return ['ssl' => $context];
    }
    
    private static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }

        $envPath = self::findEnvFile();
        
        if ($envPath && file_exists($envPath . '/.env')) {
            $dotenv = Dotenv::createImmutable($envPath);
            $dotenv->safeLoad(); 
        }
        
        self::$envLoaded = true;
    }

    private static function findEnvFile(): ?string
    {
        $currentDir = __DIR__;
        $paths = [
            getcwd(),
            dirname($currentDir, 4),
            dirname($currentDir, 5),
            dirname($currentDir, 6),
            $_SERVER['DOCUMENT_ROOT'] ?? '',
        ];

        foreach (array_unique($paths) as $path) {
            if ($path && is_dir($path) && file_exists($path . '/.env')) {
                return $path;
            }
        }

        return null;
    }
}