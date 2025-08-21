<?php

use Rcalicdan\FiberAsync\Api\Timer;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;

require 'vendor/autoload.php';

function delay(float $seconds, string $message): PromiseInterface
{
    return new Promise(
        fn ($resolve, $reject) => EventLoop::getInstance()
            ->addTimer(
                delay: $seconds,
                callback: fn () => [
                    $resolve($message),
                    print "Timer executed: $message\n",
                ]
            )
    );
}

echo "=== Testing defer() with manual EventLoop::run() ===\n";

$eventLoop = EventLoop::getInstance();

echo "1. Starting test\n";

// Schedule nextTick (highest priority)
$eventLoop->nextTick(function () {
    echo "2. NextTick callback executed (HIGHEST PRIORITY)\n";
});

// Schedule some work
echo "3. About to schedule timer work\n";
$promise = delay(1, 'hello from timer');

// Schedule defer (runs after current work phase)
$eventLoop->defer(function () {
    echo "4. Deferred callback executed (AFTER WORK PHASE)\n";
});

echo "5. About to start event loop manually\n";

// Start the event loop manually
$startTime = microtime(true);

// The event loop will run until all work is complete
$eventLoop->run();

$endTime = microtime(true);
$elapsedTime = $endTime - $startTime;

echo "6. Event loop finished\n";
echo '7. Total execution time: '.$elapsedTime." seconds\n";

echo "\n=== Testing cleanup with manual control ===\n";

// Reset for clean test
EventLoop::reset();
$eventLoop = EventLoop::getInstance();

echo "Creating temporary resource...\n";
$tempData = 'temporary_data_'.uniqid();

// Schedule cleanup via defer
$eventLoop->defer(function () use ($tempData) {
    echo "Cleaning up resource: $tempData\n";
    echo "Resource cleanup completed\n";
});

// Schedule main work
$promise = delay(0.5, 'work completed');

echo "Starting manual event loop for cleanup test...\n";
$eventLoop->run();
echo "Cleanup test completed\n";

echo "\n=== Testing manual start/stop control ===\n";

EventLoop::reset();
$eventLoop = EventLoop::getInstance();

// Schedule work that will run
$eventLoop->nextTick(fn () => print "NextTick: Loop is running\n");
$eventLoop->defer(fn () => print "Defer: Cleanup work\n");

// Schedule a timer that will stop the loop
$eventLoop->addTimer(2.0, function () use ($eventLoop) {
    echo "Timer: Stopping event loop after 2 seconds\n";
    $eventLoop->stop();
});

// Schedule more work after the stop timer
$eventLoop->addTimer(3.0, function () {
    echo "Timer: This should NOT execute (loop stopped)\n";
});

echo "Starting event loop with manual stop after 2 seconds...\n";
$startTime = microtime(true);

$eventLoop->run();

$endTime = microtime(true);
$elapsedTime = $endTime - $startTime;

echo "Event loop stopped\n";
echo 'Total time: '.$elapsedTime." seconds (should be ~2 seconds)\n";

echo "\n=== Testing event loop state ===\n";

EventLoop::reset();
$eventLoop = EventLoop::getInstance();

echo 'Is running before start: '.($eventLoop->isRunning() ? 'true' : 'false')."\n";

$eventLoop->nextTick(function () use ($eventLoop) {
    echo 'NextTick: Is running inside loop: '.($eventLoop->isRunning() ? 'true' : 'false')."\n";
    echo 'NextTick: Is idle: '.($eventLoop->isIdle() ? 'true' : 'false')."\n";
    echo 'NextTick: Iteration count: '.$eventLoop->getIterationCount()."\n";
});

$eventLoop->addTimer(0.1, function () use ($eventLoop) {
    echo 'Timer: Is running: '.($eventLoop->isRunning() ? 'true' : 'false')."\n";
    echo 'Timer: Is idle: '.($eventLoop->isIdle() ? 'true' : 'false')."\n";
    echo 'Timer: Iteration count: '.$eventLoop->getIterationCount()."\n";
});

echo "Starting event loop for state testing...\n";
$eventLoop->run();

echo 'Is running after completion: '.($eventLoop->isRunning() ? 'true' : 'false')."\n";
echo 'Final iteration count: '.$eventLoop->getIterationCount()."\n";

echo "\nAll manual EventLoop tests completed!\n";
