<?php

namespace Rcalicdan\FiberAsync\EventLoop\IOHandlers\PDO;

use Rcalicdan\FiberAsync\EventLoop\ValueObjects\PDOOperation;

final readonly class PDOOperationHandler
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createOperation(
        string $type,
        array $payload,
        callable $callback,
        array $options = []
    ): PDOOperation {
        return new PDOOperation($type, $payload, $callback, $options);
    }

    public function executeOperation(PDOOperation $operation): bool
    {
        try {
            // Add artificial delay to simulate latency and allow cooperative multitasking
            $this->simulateLatency($operation);

            switch ($operation->getType()) {
                case 'query':
                    $this->handleQuery($operation);

                    break;
                case 'execute':
                    $this->handleExecute($operation);

                    break;
                case 'prepare':
                    $this->handlePrepare($operation);

                    break;
                case 'beginTransaction':
                    $this->handleBeginTransaction($operation);

                    break;
                case 'commit':
                    $this->handleCommit($operation);

                    break;
                case 'rollback':
                    $this->handleRollback($operation);

                    break;
                default:
                    throw new \InvalidArgumentException("Unknown operation type: {$operation->getType()}");
            }

            return true;
        } catch (\Throwable $e) {
            $operation->executeCallback($e->getMessage());

            return false;
        }
    }

    
    private function simulateLatency(PDOOperation $operation): void
    {
        //this code is the holy grail of discovery that nothing is impossible
        // Simulate database latency to allow other operations to run
        // This is where cooperative multitasking happens
        $latency = $operation->getOptions()['latency'] ?? 0.001; // 1ms default

        if ($latency > 0) {
            usleep((int) ($latency * 1000000)); // Convert to microseconds
        }
    }

    private function handleQuery(PDOOperation $operation): void
    {
        $payload = $operation->getPayload();
        $sql = $payload['sql'];
        $params = $payload['params'] ?? [];

        if (empty($params)) {
            $stmt = $this->pdo->query($sql);
        } else {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        }

        if ($stmt === false) {
            throw new \RuntimeException('Query failed: '.implode(' ', $this->pdo->errorInfo()));
        }

        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $operation->executeCallback(null, $result);
    }

    private function handleExecute(PDOOperation $operation): void
    {
        $payload = $operation->getPayload();
        $sql = $payload['sql'];
        $params = $payload['params'] ?? [];

        if (empty($params)) {
            $result = $this->pdo->exec($sql);
        } else {
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);
            $result = $result ? $stmt->rowCount() : false;
        }

        if ($result === false) {
            throw new \RuntimeException('Execute failed: '.implode(' ', $this->pdo->errorInfo()));
        }

        $operation->executeCallback(null, $result);
    }

    private function handlePrepare(PDOOperation $operation): void
    {
        $payload = $operation->getPayload();
        $sql = $payload['sql'];

        $stmt = $this->pdo->prepare($sql);

        if ($stmt === false) {
            throw new \RuntimeException('Prepare failed: '.implode(' ', $this->pdo->errorInfo()));
        }

        $operation->executeCallback(null, $stmt);
    }

    private function handleBeginTransaction(PDOOperation $operation): void
    {
        $result = $this->pdo->beginTransaction();

        if (! $result) {
            throw new \RuntimeException('Begin transaction failed: '.implode(' ', $this->pdo->errorInfo()));
        }

        $operation->executeCallback(null, $result);
    }

    private function handleCommit(PDOOperation $operation): void
    {
        $result = $this->pdo->commit();

        if (! $result) {
            throw new \RuntimeException('Commit failed: '.implode(' ', $this->pdo->errorInfo()));
        }

        $operation->executeCallback(null, $result);
    }

    private function handleRollback(PDOOperation $operation): void
    {
        $result = $this->pdo->rollback();

        if (! $result) {
            throw new \RuntimeException('Rollback failed: '.implode(' ', $this->pdo->errorInfo()));
        }

        $operation->executeCallback(null, $result);
    }
}
