<?php

use Rcalicdan\FiberAsync\Api\Promise;

require_once __DIR__ . '/vendor/autoload.php';

for ($i = 0; $i < 100; $i++) {
    $startTime = microtime(true);
    run(function () {
        $delays = Promise::all([
            delay(1),
            delay(1),
            delay(1),
            delay(1),
            delay(1),
            delay(1),
        ]);
 
        await($delays);
    });
    $endTime = microtime(true);
    $executionTime = $endTime - $startTime;
    echo "Execution time: " . $executionTime . " seconds\n";
}
