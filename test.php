<?php

use Rcalicdan\FiberAsync\Api\Promise;

require_once __DIR__ . '/vendor/autoload.php';

for ($i = 0; $i < 100; $i++) {
    $startTime = microtime(true);
    run(function () {
        $delays = Promise::concurrent([
            delay(1)->then(fn()=>print "1\n"),
            delay(1)->then(fn()=>print "2\n"),
            delay(1)->then(fn()=>print "3\n"),
            delay(1)->then(fn()=>print "4\n"),
            delay(1)->then(fn()=>print "5\n"),
            delay(1)->then(fn()=>print "6\n"),
        ]);
 
        await($delays);
    });
    $endTime = microtime(true);
    $executionTime = $endTime - $startTime;
    echo "Execution time: " . $executionTime . " seconds\n";
}
