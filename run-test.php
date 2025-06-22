<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\Async;

// This will process 5 tasks concurrently, completing in ~1 second total
function testConcurrentProcessing() {
    $startTime = microtime(true);
    
    $results = Async::runConcurrent([
        function() {
            echo "Task 1 starting at " . microtime(true) . "\n";
            Async::await(Async::delay(1)); // 1 second delay
            echo "Task 1 finished at " . microtime(true) . "\n";
            return "Task 1 result";
        },
        function() {
            echo "Task 2 starting at " . microtime(true) . "\n";
            Async::await(Async::delay(1)); // 1 second delay
            echo "Task 2 finished at " . microtime(true) . "\n";
            return "Task 2 result";
        },
        function() {
            echo "Task 3 starting at " . microtime(true) . "\n";
            Async::await(Async::delay(1)); // 1 second delay
            echo "Task 3 finished at " . microtime(true) . "\n";
            return "Task 3 result";
        },
        function() {
            echo "Task 4 starting at " . microtime(true) . "\n";
            Async::await(Async::delay(1)); // 1 second delay
            echo "Task 4 finished at " . microtime(true) . "\n";
            return "Task 4 result";
        },
        function() {
            echo "Task 5 starting at " . microtime(true) . "\n";
            Async::await(Async::delay(1)); // 1 second delay
            echo "Task 5 finished at " . microtime(true) . "\n";
            return "Task 5 result";
        }
    ], 5); // Concurrency limit of 5
    
    $duration = microtime(true) - $startTime;
    echo "All 5 tasks completed in: " . round($duration, 2) . " seconds\n";
    print_r($results);
}

testConcurrentProcessing();