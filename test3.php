<?php

require "vendor/autoload.php";

$start = microtime(true);

$winner = run(function () {
    $promises = [
        fn() => delay(1)->then(fn() => throw new Exception("Error from 1")),
        fn() => delay(2)->then(fn() => "Success from 2"),
        fn() => delay(3)->then(fn() => "Success from 3"),
    ];

    try {
        $result = await(any($promises));
        echo "Race result: $result\n";
        return $result;
    } catch (Throwable $e) {
        echo "Race threw: {$e->getMessage()}\n";
        return null;
    }
});

$end = microtime(true);
$duration = $end - $start;

echo "Execution time: {$duration} seconds\n";
echo "Winner: {$winner}\n";
