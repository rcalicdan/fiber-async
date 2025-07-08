<?php

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Facades\AsyncMysql;

if (! function_exists('mysql_query')) {
    function mysql_query(string $sql): PromiseInterface
    {
        return AsyncMysql::query($sql);
    }
}

if (! function_exists('mysql_prepare')) {
    function mysql_prepare(string $sql): PromiseInterface
    {
        return AsyncMysql::prepare($sql);
    }
}

if (! function_exists('mysql_transaction')) {
    function mysql_transaction(): PromiseInterface
    {
        return AsyncMysql::beginTransaction();
    }
}

if (! function_exists('mysql_close')) {
    function mysql_close(): void
    {
        AsyncMysql::close();
    }
}