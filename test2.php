<?php

use Rcalicdan\FiberAsync\Api\Promise;

require "vendor/autoload.php";

// This should now work correctly
foreach ([1, 2, 3] as $concurrency) {
    $startTime = microtime(true);
    run(function () use ($concurrency) {
        $task = Promise::concurrent([
            fn() => await(delay(1)),  
            fn() => await(delay(5)),    
            fn() => await(delay(3)),  
        ], $concurrency);
        await($task);
    });
    $endTime = microtime(true);
    echo "Concurrency $concurrency: " . ($endTime - $startTime) . " seconds\n";
}

// This should throw a clear error
try {
    run(function () {
        $task = Promise::concurrent([
            delay(1),  // ❌ Pre-created promise
            delay(5),  // ❌ Pre-created promise
        ], 2);
        await($task);
    });
} catch (Exception $e) {
    echo "Error (as expected): " . $e->getMessage() . "\n";
}
