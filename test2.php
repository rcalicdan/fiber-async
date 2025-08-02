<?php

use Rcalicdan\FiberAsync\Api\Promise;

require "vendor/autoload.php";

$startTime = microtime(true);
run(function () {
    $promises = [
        fn() => delay(1),
        fn() => delay(5), 
        fn() => delay(3),
    ];
    
    $task = Promise::concurrent($promises, 1);
    await($task);
});
$endTime = microtime(true);
echo "Test 1 - Should be ~9 seconds: " . ($endTime - $startTime) . "\n";

$startTime = microtime(true);
run(function () {
    $task = Promise::concurrent([
        delay(1),
        delay(5),
        delay(3),
    ], 1);
    await($task);
});
$endTime = microtime(true);
echo "Test 2 - Should be ~9 seconds: " . ($endTime - $startTime) . "\n";

$startTime = microtime(true);
run(function () {
    $task = Promise::concurrent([
        fn() => await(delay(1)),
        fn() => await(delay(5)),
        fn() => await(delay(3)),
    ], 1);
    await($task);
});
$endTime = microtime(true);
echo "Test 3 - Should be ~9 seconds: " . ($endTime - $startTime) . "\n";