<?php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\Handlers\TransactionHandler;

class Transaction
{
    private MySQLClient $client;
    private TransactionHandler $transactionHandler;
    private array $savepoints = [];
    private bool $isCommitted = false;
    private bool $isRolledBack = false;

    public function __construct(MySQLClient $client)
    {
        $this->client = $client;
        $this->transactionHandler = $client->getTransactionHandler();
    }

    public function query(string $sql): PromiseInterface
    {
        return async(function () use ($sql) {
            $this->ensureTransactionActive();

            return await($this->client->query($sql));
        })();
    }

    public function prepare(string $sql): PromiseInterface
    {
        return async(function () use ($sql) {
            $this->ensureTransactionActive();

            return await($this->client->prepare($sql));
        })();
    }

    public function commit(): PromiseInterface
    {
        return async(function () {
            $this->ensureTransactionActive();

            try {
                $result = await($this->transactionHandler->commit());
                $this->isCommitted = true;

                return $result;
            } catch (\Throwable $e) {
                $this->client->debug('Commit failed, attempting rollback');

                try {
                    await($this->transactionHandler->rollback());
                    $this->isRolledBack = true;
                } catch (\Throwable $rollbackError) {
                    $this->client->debug('Rollback also failed: '.$rollbackError->getMessage());
                }

                throw $e;
            }
        })();
    }

    public function rollback(): PromiseInterface
    {
        return async(function () {
            $this->ensureTransactionActive();

            $result = await($this->transactionHandler->rollback());
            $this->isRolledBack = true;

            return $result;
        })();
    }

    public function savepoint(string $name): PromiseInterface
    {
        return async(function () use ($name) {
            $this->ensureTransactionActive();

            $result = await($this->transactionHandler->savepoint($name));
            $this->savepoints[] = $name;

            return $result;
        })();
    }

    public function rollbackToSavepoint(string $name): PromiseInterface
    {
        return async(function () use ($name) {
            $this->ensureTransactionActive();

            if (! in_array($name, $this->savepoints)) {
                throw new \InvalidArgumentException("Savepoint '{$name}' not found");
            }

            return await($this->transactionHandler->rollbackToSavepoint($name));
        })();
    }

    public function releaseSavepoint(string $name): PromiseInterface
    {
        return async(function () use ($name) {
            $this->ensureTransactionActive();

            if (! in_array($name, $this->savepoints)) {
                throw new \InvalidArgumentException("Savepoint '{$name}' not found");
            }

            $result = await($this->transactionHandler->releaseSavepoint($name));
            $this->savepoints = array_filter($this->savepoints, fn ($sp) => $sp !== $name);

            return $result;
        })();
    }

    public function execute(callable $callback): PromiseInterface
    {
        return async(function () use ($callback) {
            try {
                $result = await($callback($this));
                await($this->commit());

                return $result;
            } catch (\Throwable $e) {
                if (! $this->isRolledBack) {
                    try {
                        await($this->rollback());
                    } catch (\Throwable $rollbackError) {
                        $this->client->debug('Rollback failed: '.$rollbackError->getMessage());
                    }
                }

                throw $e;
            }
        })();
    }

    public function isActive(): bool
    {
        return ! $this->isCommitted && ! $this->isRolledBack;
    }

    public function isCommitted(): bool
    {
        return $this->isCommitted;
    }

    public function isRolledBack(): bool
    {
        return $this->isRolledBack;
    }

    private function ensureTransactionActive(): void
    {
        if (! $this->isActive()) {
            throw new \RuntimeException('Transaction is not active');
        }
    }
}
