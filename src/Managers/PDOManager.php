<?php

namespace Rcalicdan\FiberAsync\Managers;

use Rcalicdan\FiberAsync\Database\Handlers\PDOOperationHandler;
use Rcalicdan\FiberAsync\ValueObjects\PDOOperation;


class PDOManager
{
    /** @var PDOOperation[] */
    private array $pendingOperations = [];

    /** @var array<string, PDOOperation> */
    private array $operationsById = [];

    private PDOOperationHandler $operationHandler;

    public function __construct()
    {
        $this->operationHandler = new PDOOperationHandler();
    }

    // Pass custom latency config from outside for testing scenarios
    public function setLatencyConfig(array $config): void
    {
        $this->operationHandler->setLatencyConfig($config);
    }

    public function addOperation(PDOOperation $operation): string
    {
        $this->pendingOperations[] = $operation;
        $this->operationsById[$operation->getId()] = $operation;

        return $operation->getId();
    }

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

        // Notify callback of cancellation (if the operation hasn't started yet)
        // Note: If the blocking PDO call is already running, we can't stop it.
        // The callback just won't be executed with a successful result.
        $operation->executeCallback(new \RuntimeException('PDO Operation cancelled before execution.'));

        return true;
    }

    public function processOperations(): bool
    {
        if (empty($this->pendingOperations)) {
            return false;
        }

        $processed = false;
        $operationsToProcess = $this->pendingOperations;
        $this->pendingOperations = []; // Clear queue for this tick

        foreach ($operationsToProcess as $operation) {
            // Check if it was cancelled before processing
            if (!isset($this->operationsById[$operation->getId()])) {
                continue;
            }
            $this->operationHandler->executeOperation($operation);
            $processed = true;
            unset($this->operationsById[$operation->getId()]); // Clean up after processing
        }

        return $processed;
    }

    public function hasPendingOperations(): bool
    {
        return !empty($this->pendingOperations);
    }
}