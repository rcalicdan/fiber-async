<?php

use Rcalicdan\FiberAsync\Api\AsyncDB;
use Rcalicdan\FiberAsync\Api\AsyncMySQLi;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\Api\Timer;

require_once __DIR__ . '/vendor/autoload.php';

$microtime = microtime(true);
run(function () {
    $Promise = Promise::all([
       AsyncDB::table('users')->get(),
       AsyncDB::table('users')->get(),
       AsyncDB::table('users')->get(),
       AsyncDB::table('users')->get(),
    ]);

    $results = await($Promise);

    foreach ($results as $result) {
        var_dump($result);
    }
});

$microtime = microtime(true) - $microtime;
echo $microtime . PHP_EOL;
