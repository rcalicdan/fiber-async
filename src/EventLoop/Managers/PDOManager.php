<?php

namespace Rcalicdan\FiberAsync\EventLoop\Managers;

use Rcalicdan\FiberAsync\EventLoop\IOHandlers\PDO\PDOOperationHandler;
use Rcalicdan\FiberAsync\EventLoop\ValueObjects\PDOOperation;

class PDOManager
{
    /** @var PDOOperation[] */
    private array $pendingOperations = [];
    /** @var array<string, PDOOperation> */
    private array $operationsById = [];

    private ?\PDO $pdo = null;
    private ?PDOOperationHandler $operationHandler = null;
    private bool $isInitialized = false;

    /**
     * Constructor is now clean and takes no arguments.
     * Initialization is deferred until initialize() is called.
     */
    public function __construct() {}

    /**
     * A dedicated method to configure the manager and create the PDO connection.
     */
    public function initialize(array $config): void
    {
        if ($this->isInitialized) {
            return;
        }
        $this->pdo = $this->createPDOConnection($config);
        $this->operationHandler = new PDOOperationHandler($this->pdo);
        $this->isInitialized = true;
    }

    private function createPDOConnection(array $config): \PDO
    {
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
            ],
        ];
        $config = array_merge($defaults, $config);
        $dsn = $this->buildDSN($config);

        try {
            $pdo = new \PDO($dsn, $config['username'], $config['password'], $config['options']);
            $this->configureDatabase($pdo, $config);

            return $pdo;
        } catch (\PDOException $e) {
            throw new \RuntimeException('Database connection failed: '.$e->getMessage());
        }
    }

    private function buildDSN(array $config): string
    {
        switch ($config['driver']) {
            case 'mysql':
                $port = $config['port'] ?? 3306;
                $dsn = "mysql:host={$config['host']};port={$port};dbname={$config['database']}";
                if (! empty($config['charset'])) {
                    $dsn .= ";charset={$config['charset']}";
                }

                return $dsn;
            case 'pgsql':
            case 'postgresql':
                $port = $config['port'] ?? 5432;

                return "pgsql:host={$config['host']};port={$port};dbname={$config['database']}";
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

    public function addOperation(PDOOperation $operation): string
    {
        $this->ensureInitialized();
        $this->pendingOperations[] = $operation;
        $this->operationsById[$operation->getId()] = $operation;

        return $operation->getId();
    }

    public function cancelOperation(string $operationId): bool
    {
        if (! isset($this->operationsById[$operationId])) {
            return false;
        }
        $operation = $this->operationsById[$operationId];
        $pendingKey = array_search($operation, $this->pendingOperations, true);
        if ($pendingKey !== false) {
            unset($this->pendingOperations[$pendingKey]);
            $this->pendingOperations = array_values($this->pendingOperations);
        }
        unset($this->operationsById[$operationId]);
        $operation->executeCallback('Operation cancelled');

        return true;
    }

    public function processOperations(): bool
    {
        if (! $this->isInitialized || empty($this->pendingOperations)) {
            return false;
        }
        $processed = false;
        $operationsToProcess = $this->pendingOperations;
        $this->pendingOperations = [];
        foreach ($operationsToProcess as $operation) {
            if ($this->operationHandler->executeOperation($operation)) {
                $processed = true;
            }
            unset($this->operationsById[$operation->getId()]);
        }

        return $processed;
    }

    public function hasPendingOperations(): bool
    {
        if (! $this->isInitialized) {
            return false;
        }

        return ! empty($this->pendingOperations);
    }

    private function ensureInitialized(): void
    {
        if (! $this->isInitialized) {
            throw new \LogicException('PDOManager has not been initialized. Was EventLoop::configureDatabase() or AsyncPDO::init() called?');
        }
    }
}
