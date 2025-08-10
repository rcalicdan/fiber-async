<?php
require __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\Promise;

$start = microtime(true);

function ms()
{
    global $start;
    return round((microtime(true) - $start) * 1000);
}

function logmsg($msg)
{
    echo "$msg @ " . ms() . "ms\n";
}

run(function () {
    $aggregateStart = ms();

    // Create promises with different completion times
    $pFast = delay(1) // 1 second
        ->then(function() {
            logmsg('Fast promise completed');
            return 'fast-result';
        });

    $pMedium = delay(2) // 2 seconds
        ->then(function() {
            logmsg('Medium promise completed');
            return 'medium-result';
        });

    $pSlow = delay(3) // 3 seconds
        ->then(function() {
            logmsg('Slow promise completed');
            return 'slow-result';
        });

    // Test timeout - should complete before 2.5 seconds
    Promise::timeout([$pFast, $pMedium, $pSlow], 2.5)
        ->then(function ($results) use ($aggregateStart) {
            logmsg('Promise::timeout resolved: ' . json_encode($results) .
                ' (duration: ' . (ms() - $aggregateStart) . 'ms)');
        })
        ->catch(function (Throwable $e) use ($aggregateStart) {
            logmsg("Promise::timeout rejected: {$e->getMessage()} " .
                '(duration: ' . (ms() - $aggregateStart) . 'ms)');
        });
});

logmsg("Timeout test completed");