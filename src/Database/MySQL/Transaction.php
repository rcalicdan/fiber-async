<?php

namespace Rcalicdan\FiberAsync\Database\MySQL;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\MySQL\ValueObjects\PendingCommand;

class Transaction 
{
    private bool $isComplete = false;
    
    public function __construct(
        private readonly Pool $pool,
        private Connection $connection
    ) {}

    public function query(string $sql): PromiseInterface
    {
        return $this->connection->enqueue(new PendingCommand(PendingCommand::TYPE_QUERY, $sql));
    }
    
    public function prepare(string $sql): PromiseInterface
    {
        return $this->connection->enqueue(new PendingCommand(PendingCommand::TYPE_PREPARE, $sql));
    }

    public function commit(): PromiseInterface
    {
        $this->isComplete = true;
        return $this->connection->enqueue(new PendingCommand(PendingCommand::TYPE_QUERY, 'COMMIT'))->finally(function() {
            $this->pool->releaseConnection($this->connection);
        });
    }

    public function rollback(): PromiseInterface
    {
        $this->isComplete = true;
        return $this->connection->enqueue(new PendingCommand(PendingCommand::TYPE_QUERY, 'ROLLBACK'))->finally(function() {
            $this->pool->releaseConnection($this->connection);
        });
    }
    
    public function __destruct()
    {
        if (!$this->isComplete && $this->connection->state === Connection::STATE_IDLE) {
            $this->rollback();
        }
    }
}