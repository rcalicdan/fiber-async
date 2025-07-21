<?php

namespace Rcalicdan\FiberAsync\MySQL\Handlers;

use Rcalicdan\FiberAsync\MySQL\MySQLClient;
use Rcalicdan\FiberAsync\MySQL\Transaction;
use Rcalicdan\FiberAsync\MySQL\TransactionIsolationLevel;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

class TransactionHandler
{
    public const VALID_ISOLATION_LEVELS = [
        'REPEATABLE READ',
        'READ COMMITTED',
        'READ UNCOMMITTED',
        'SERIALIZABLE',
    ];
    private MySQLClient $client;
    private QueryHandler $queryHandler;
    private ?Transaction $activeTransaction = null;
    private TransactionIsolationLevel $isolationLevel = TransactionIsolationLevel::RepeatableRead;

    public function __construct(MySQLClient $client)
    {
        $this->client = $client;
        $this->queryHandler = $client->getQueryHandler();
    }

    public function setIsolationLevel(TransactionIsolationLevel|string $level): void
    {
        if ($level instanceof TransactionIsolationLevel) {
            $this->isolationLevel = $level;

            return;
        }

        $normalizedLevel = strtoupper(trim($level));

        try {
            $this->isolationLevel = TransactionIsolationLevel::from($normalizedLevel);
        } catch (\ValueError) {
            $validLevels = array_map(fn ($case) => $case->value, TransactionIsolationLevel::cases());

            throw new \InvalidArgumentException(
                "Invalid transaction isolation level string '{$level}'. Must be one of: ".implode(', ', $validLevels)
            );
        }
    }

    public function beginTransaction(TransactionIsolationLevel|string|null $isolationLevel = null): PromiseInterface
    {
        return async(function () use ($isolationLevel) {
            if ($this->activeTransaction) {
                throw new \RuntimeException('Transaction already active');
            }

            $levelToUse = $isolationLevel !== null
                ? $this->resolveIsolationLevel($isolationLevel)
                : $this->isolationLevel;

            $this->client->debug("Setting transaction isolation level to: {$levelToUse->value}");
            await($this->queryHandler->query("SET TRANSACTION ISOLATION LEVEL {$levelToUse->value}"));

            $this->client->debug('Starting formal transaction with beginTransaction()');
            await($this->queryHandler->query('START TRANSACTION'));

            $this->activeTransaction = new Transaction($this->client);

            return $this->activeTransaction;
        })();
    }

    public function commit(): PromiseInterface
    {
        return async(function () {
            if ($this->activeTransaction) {
                $this->client->debug('Committing formal transaction');
                $result = await($this->queryHandler->query('COMMIT'));
                $this->activeTransaction = null;

                return $result;
            }

            $autocommitState = await($this->getAutoCommit());
            if ($autocommitState == 0) {
                $this->client->debug('Sending manual COMMIT (autocommit is OFF)');

                return await($this->queryHandler->query('COMMIT'));
            }

            throw new \RuntimeException('No active transaction to commit');
        })();
    }

    public function rollback(): PromiseInterface
    {
        return async(function () {
            if ($this->activeTransaction) {
                $this->client->debug('Rolling back formal transaction');
                $result = await($this->queryHandler->query('ROLLBACK'));
                $this->activeTransaction = null;

                return $result;
            }

            $autocommitState = await($this->getAutoCommit());
            if ($autocommitState == 0) {
                $this->client->debug('Sending manual ROLLBACK (autocommit is OFF)');

                return await($this->queryHandler->query('ROLLBACK'));
            }

            throw new \RuntimeException('No active transaction to roll back');
        })();
    }

    public function savepoint(string $name): PromiseInterface
    {
        return async(function () use ($name) {
            if (! $this->activeTransaction) {
                throw new \RuntimeException('No active transaction');
            }

            $this->client->debug("Creating savepoint: {$name}");

            return await($this->queryHandler->query("SAVEPOINT `{$name}`"));
        })();
    }

    public function rollbackToSavepoint(string $name): PromiseInterface
    {
        return async(function () use ($name) {
            if (! $this->activeTransaction) {
                throw new \RuntimeException('No active transaction');
            }

            $this->client->debug("Rolling back to savepoint: {$name}");

            return await($this->queryHandler->query("ROLLBACK TO SAVEPOINT `{$name}`"));
        })();
    }

    public function releaseSavepoint(string $name): PromiseInterface
    {
        return async(function () use ($name) {
            if (! $this->activeTransaction) {
                throw new \RuntimeException('No active transaction');
            }

            $this->client->debug("Releasing savepoint: {$name}");

            return await($this->queryHandler->query("RELEASE SAVEPOINT `{$name}`"));
        })();
    }

    public function getActiveTransaction(): ?Transaction
    {
        return $this->activeTransaction;
    }

    public function isInTransaction(): bool
    {
        return $this->activeTransaction !== null;
    }

    public function setAutoCommit(bool $autoCommit): PromiseInterface
    {
        return async(function () use ($autoCommit) {
            $value = $autoCommit ? 1 : 0;
            $this->client->debug("Setting autocommit to: {$value}");

            return await($this->queryHandler->query("SET autocommit = {$value}"));
        })();
    }

    public function getAutoCommit(): PromiseInterface
    {
        return async(function () {
            $result = await($this->queryHandler->query('SELECT @@autocommit'));

            return $result[0]['@@autocommit'] ?? 1;
        })();
    }

    public function reset(): void
    {
        $this->client->debug('Resetting transaction state due to connection loss');
        $this->activeTransaction = null;
    }

    private function resolveIsolationLevel(TransactionIsolationLevel|string $level): TransactionIsolationLevel
    {
        if ($level instanceof TransactionIsolationLevel) {
            return $level;
        }

        $normalizedLevel = strtoupper(trim($level));

        try {
            return TransactionIsolationLevel::from($normalizedLevel);
        } catch (\ValueError) {
            $validLevels = array_map(fn ($case) => $case->value, TransactionIsolationLevel::cases());

            throw new \InvalidArgumentException(
                "Invalid transaction isolation level string '{$level}'. Must be one of: ".implode(', ', $validLevels)
            );
        }
    }
}
