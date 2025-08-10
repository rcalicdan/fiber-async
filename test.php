<?php
require __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;

$promises = Promise::all([
    delay(1),
    delay(2),
    delay(3),
]);

$startTime = microtime(true);
run(function () use ($promises) {
    await($promises);
});

run(function () use ($promises) {
    await($promises);
});

$endTime = microtime(true);
$executionTime = $endTime - $startTime;
echo "Execution time: " . $executionTime . " seconds" . PHP_EOL;
