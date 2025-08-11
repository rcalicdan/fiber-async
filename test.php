<?php
require __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Promise;



$startTime = microtime(true);
run(function () {
    $promises = delay(1)->setTimerId();
    await($promises);
});


$endTime = microtime(true);
$executionTime = $endTime - $startTime;
echo "Execution time: " . $executionTime . " seconds" . PHP_EOL;
