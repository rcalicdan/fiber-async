<?php

use Rcalicdan\FiberAsync\Benchmark\BenchmarkRunner;
use Rcalicdan\FiberAsync\MySQLi\DB;
use Rcalicdan\FiberAsync\Promise\Promise;

require 'vendor/autoload.php';

for ($i = 1; $i <= 5; $i++) {
    echo "Round $i\n";
    $start = microtime(true);
    $task = run(function () {
        $queries = Promise::all([
            DB::rawExecute("SELECT SLEEP(1)"),
            DB::rawExecute("SELECT SLEEP(2)"),
            DB::rawExecute("SELECT SLEEP(3)"),
        ]);

        await($queries);
    });
    $endTime = microtime(true);
    $executionTime = $endTime - $start;
    echo "Execution time: " . $executionTime . " seconds\n";
}
