<?php

use Rcalicdan\FiberAsync\Api\AsyncMySQL;
use Rcalicdan\FiberAsync\Api\AsyncPDO;

require "vendor/autoload.php";

const POOL_SIZE = 20;
const QUERY_SIZE = 100;


AsyncMySQL::init([
    'host' => 'localhost',
    'username' => 'hey',
    'password' => '1234',
    'database' => 'yo',
    'port' => 3306
], POOL_SIZE);

for ($i = 1; $i < 10; $i++) {
    echo "Round " . $i . "\n";
    $startTime = microtime(true);
    run(function () {
        $task = [];
        for ($i = 0; $i < QUERY_SIZE; $i++) {
            $task[] = AsyncMySQL::query("select sleep(1)");
        }

        await(all($task));
    });
    $endTime = microtime(true);
    echo "Total Time: " . $endTime - $startTime . "\n";
}
