<?php
// src/Database/Exceptions/DatabaseException.php

namespace Rcalicdan\FiberAsync\Database\Exceptions;

class DatabaseException extends \Exception
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}