<?php

namespace Rcalicdan\FiberAsync\Managers;

use Rcalicdan\FiberAsync\Handlers\PDO\PDOOperationHandler;
use Rcalicdan\FiberAsync\ValueObjects\PDOOperation;

class PDOManager
{
    /** @var PDOOperation[] */
    private array $pendingOperations = [];

    /** @var array<string, PDOOperation> */
    private array $operationsById = [];

    private PDOOperationHandler $operationHandler;
    private \PDO $pdo;

    public function __construct(array $config = [])
    {
        $this->pdo = $this->createPDOConnection($config);
        $this->operationHandler = new PDOOperationHandler($this->pdo);
    }

    private function createPDOConnection(array $config): \PDO
    {
        // Default configuration
        $defaults = [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => null,
            'database' => 'test',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]
        ];

        $config = array_merge($defaults, $config);

        $dsn = $this->buildDSN($config);

        try {
            $pdo = new \PDO(
                $dsn,
                $config['username'],
                $config['password'],
                $config['options']
            );

            // Set database-specific configurations
            $this->configureDatabase($pdo, $config);

            return $pdo;
        } catch (\PDOException $e) {
            throw new \RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    private function buildDSN(array $config): string
    {
        switch ($config['driver']) {
            case 'mysql':
                $port = $config['port'] ?? 3306;
                $dsn = "mysql:host={$config['host']};port={$port};dbname={$config['database']}";
                if ($config['charset']) {
                    $dsn .= ";charset={$config['charset']}";
                }
                return $dsn;

            case 'pgsql':
            case 'postgresql':
                $port = $config['port'] ?? 5432;
                $dsn = "pgsql:host={$config['host']};port={$port};dbname={$config['database']}";
                return $dsn;

            case 'sqlite':
                return "sqlite:{$config['database']}";

            case 'sqlsrv':
                $port = $config['port'] ?? 1433;
                return "sqlsrv:Server={$config['host']},{$port};Database={$config['database']}";

            default:
                throw new \InvalidArgumentException("Unsupported database driver: {$config['driver']}");
        }
    }

    private function configureDatabase(\PDO $pdo, array $config): void
    {
        switch ($config['driver']) {
            case 'mysql':
                $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
                $pdo->exec("SET SESSION time_zone = '+00:00'");
                break;

            case 'pgsql':
            case 'postgresql':
                $pdo->exec("SET timezone = 'UTC'");
                break;
        }
    }

    private function createDefaultPDO(): \PDO
    {
        // Create a default SQLite in-memory database for testing
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /**
     * Add a PDO operation and return a unique operation ID for cancellation
     */
    public function addOperation(PDOOperation $operation): string
    {
        $this->pendingOperations[] = $operation;
        $this->operationsById[$operation->getId()] = $operation;

        return $operation->getId();
    }

    /**
     * Cancel a PDO operation by its ID
     */
    public function cancelOperation(string $operationId): bool
    {
        if (!isset($this->operationsById[$operationId])) {
            return false;
        }

        $operation = $this->operationsById[$operationId];

        // Remove from pending operations
        $pendingKey = array_search($operation, $this->pendingOperations, true);
        if ($pendingKey !== false) {
            unset($this->pendingOperations[$pendingKey]);
            $this->pendingOperations = array_values($this->pendingOperations);
        }

        unset($this->operationsById[$operationId]);

        // Notify callback of cancellation
        $operation->executeCallback('Operation cancelled');

        return true;
    }

    /**
     * Process pending PDO operations
     */
    public function processOperations(): bool
    {
        if (empty($this->pendingOperations)) {
            return false;
        }

        $processed = false;
        $operationsToProcess = $this->pendingOperations;
        $this->pendingOperations = [];

        foreach ($operationsToProcess as $operation) {
            if ($this->operationHandler->executeOperation($operation)) {
                $processed = true;
            }

            // Clean up from ID map
            unset($this->operationsById[$operation->getId()]);
        }

        return $processed;
    }

    public function hasPendingOperations(): bool
    {
        return !empty($this->pendingOperations);
    }

    public function getPendingOperationCount(): int
    {
        return count($this->pendingOperations);
    }
}
