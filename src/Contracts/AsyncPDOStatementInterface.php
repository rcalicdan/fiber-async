<?php

namespace Rcalicdan\FiberAsync\Contracts;

use Rcalicdan\FiberAsync\Database\Result;

interface AsyncPDOStatementInterface
{
    public function execute(array $params = []): PromiseInterface; 
    public function fetchAll(int $fetchMode = \PDO::FETCH_ASSOC): PromiseInterface; 
    public function fetch(int $fetchMode = \PDO::FETCH_ASSOC): PromiseInterface; 
    public function rowCount(): PromiseInterface; 
    public function closeCursor(): PromiseInterface; 
}