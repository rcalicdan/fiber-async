<?php

use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Api\Timer;

require_once 'vendor/autoload.php';

$startTime = microtime(true);
Task::run(function () {
    process_defer(function () {
        delay(10)->await();
        write_file_async('log.txt', 'Hello, world!')->await();
    });

    delay(1)->then(function () {
        exit(0);
    });
});

$endTime = microtime(true);
$executionTime = $endTime - $startTime;
echo "Execution time: " . $executionTime . " seconds";
