<?php

namespace Rcalicdan\FiberAsync\Database\MySQL\ValueObjects;

final readonly class StatementResult
{
    public function __construct(
        public ?array $rows = null,
        public ?int $affectedRows = null,
        public ?int $lastInsertId = null,
    ) {
    }
}