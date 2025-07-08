<?php
// src/Database/Exceptions/ConnectionException.php

namespace Rcalicdan\FiberAsync\Database\Exceptions;

class ConnectionException extends DatabaseException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}