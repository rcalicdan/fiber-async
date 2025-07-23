<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Socket\AsyncSocketOperations;

final class AsyncSocket
{
    private static ?AsyncSocketOperations $ops = null;

    protected static function getInstance(): AsyncSocketOperations
    {
        if (self::$ops === null) {
            self::$ops = new AsyncSocketOperations;
        }

        return self::$ops;
    }

    public static function reset(): void
    {
        self::$ops = null;
    }

    public static function connect(string $address, ?float $timeout = 10.0, array $contextOptions = []): PromiseInterface
    {
        return self::getInstance()->connect($address, $timeout, $contextOptions);
    }
}
