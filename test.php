<?php

use Rcalicdan\FiberAsync\Api\DB;
use Rcalicdan\FiberAsync\Benchmark\BenchmarkConfig;
use Rcalicdan\FiberAsync\Benchmark\BenchmarkRunner;

require "vendor/autoload.php";

$config = BenchmarkConfig::create()->warmup(0)->runs(5);

BenchmarkRunner::create("Measure true DB asynchonosity", $config)
    ->callback(function () {
        $startTime = microtime(true);
        run_all([
            DB::table('users')->get()
                ->then(fn() => print "Query 1:" . microtime(true) - $startTime . PHP_EOL),
            DB::table('users')->get()
                ->then(fn() => print "Query 2:" . microtime(true) - $startTime . PHP_EOL),
            DB::table('users')->get()
                ->then(fn() => print "Query 3:" . microtime(true) - $startTime . PHP_EOL),
            DB::table('users')->get()
                ->then(fn() => print "Query 4:" . microtime(true) - $startTime . PHP_EOL),
            DB::table('users')->get()
                ->then(fn() => print "Query 5:" . microtime(true) - $startTime . PHP_EOL),
        ]);
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        echo "Execution time: " . $executionTime . " seconds\n\n";
    })
    ->run();
