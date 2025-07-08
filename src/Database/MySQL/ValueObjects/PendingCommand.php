<?php

namespace Rcalicdan\FiberAsync\Database\MySQL\ValueObjects;

use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

class PendingCommand
{
    public const TYPE_QUERY = 1;
    public const TYPE_PREPARE = 2;
    public const TYPE_EXECUTE = 3;
    public const TYPE_CLOSE_STMT = 4;
    public const TYPE_AUTH_SWITCH = 5;
    public const TYPE_QUIT = 6;
    public const TYPE_SSL_REQUEST = 7;
    public const TYPE_HANDSHAKE_RESPONSE = 8;

    public PromiseInterface $promise;

    public function __construct(
        public readonly int $type,
        public readonly mixed $data
    ) {
        $this->promise = new AsyncPromise();
    }
}