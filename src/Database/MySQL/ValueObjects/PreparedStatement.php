<?php

namespace Rcalicdan\FiberAsync\Database\MySQL\ValueObjects;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\MySQL\Pool;

class PreparedStatement
{
    public function __construct(
        private readonly Pool $pool,
        public readonly int $statementId,
        public readonly int $paramCount,
        public readonly int $columnCount
    ) {
    }

    public function execute(array $params = []): PromiseInterface
    {
        return $this->pool->execute($this->statementId, $params, $this->columnCount > 0);
    }

    public function close(): PromiseInterface
    {
        return $this->pool->closeStatement($this->statementId);
    }
}