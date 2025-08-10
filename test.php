<?php

use Rcalicdan\FiberAsync\Promise\Promise;

require_once __DIR__ . '/vendor/autoload.php';

$start = microtime(true);

$results = run(function () {
    $flags = [
        'v1' => false,
        'v2' => false,
        'v3' => false,
    ];
    $resolvedCount = 0;

    // This promise will fail after 0.5s
    $errorPromise = async(function () {
        await(delay(0.5));
        throw new ErrorException('boom');
    });

    // These promises set flags when their `then` handlers run
    $p1 = delay(1)->then(function () use (&$flags, &$resolvedCount) {
        $flags['v1'] = true;
        $resolvedCount++;
        echo "v1 then executed\n";
        return "value1\n";
    });

    $p2 = delay(2)->then(function () use (&$flags, &$resolvedCount) {
        $flags['v2'] = true;
        $resolvedCount++;
        echo "v2 then executed\n";
        return "value2\n";
    });

    $p3 = delay(3)->then(function () use (&$flags, &$resolvedCount) {
        $flags['v3'] = true;
        $resolvedCount++;
        echo "v3 then executed\n";
        return "value3\n";
    });

    $aggregate = Promise::all([
        $errorPromise(),
        $p1,
        $p2,
        $p3,
    ]);

    // Await the aggregate so `run()` returns the resolved value (not a Promise)
    try {
        $value = await($aggregate);
        // If all resolved (unexpected here), return the resolved values
        return [
            'status' => 'resolved',
            'value' => $value,
            'flags' => $flags,
            'resolvedCount' => $resolvedCount,
        ];
    } catch (\Throwable $e) {
        // Promise::all rejected — return the reason and current flags
        return [
            'status' => 'rejected',
            'error' => $e->getMessage(),
            'flags' => $flags,
            'resolvedCount' => $resolvedCount,
        ];
    }
});

$elapsed = microtime(true) - $start;

echo "Elapsed: {$elapsed}\n";
var_dump($results);

// Basic checks
$fastReject = ($elapsed < 1.0); // error after 0.5s expected -> should finish well before 1s
$rejected = ($results['status'] === 'rejected');
$noThenExecuted = ($results['flags'] === ['v1' => false, 'v2' => false, 'v3' => false]);

echo "Fast reject (<1s): " . ($fastReject ? "YES" : "NO") . PHP_EOL;
echo "Promise::all rejected: " . ($rejected ? "YES" : "NO") . PHP_EOL;
echo "Other then() handlers ran? " . ($noThenExecuted ? "NO" : "YES") . PHP_EOL;

if ($fastReject && $rejected && $noThenExecuted) {
    echo "TEST PASS: Promise::all rejected quickly and other then() handlers did not run.\n";
} elseif ($fastReject && $rejected) {
    echo "PARTIAL PASS: Promise::all rejected quickly, but other producers' then() ran (no active cancellation).\n";
} else {
    echo "TEST FAIL: Promise::all didn't reject as expected or timing differs.\n";
}
