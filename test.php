<?php

require_once "vendor/autoload.php";

use Rcalicdan\FiberAsync\Benchmark\BenchmarkConfig;
use Rcalicdan\FiberAsync\Benchmark\BenchmarkRunner;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\Promise;

$start_time = microtime(true);
$results = run(function () use($start_time) {
    $promise = Promise::all([
        "todos" => fetch('https://jsonplaceholder.typicode.com/todos')->then(function ($result) use ($start_time) {
            $end_time = microtime(true);
            $execution_time = $end_time - $start_time;
            printf("todos execution time: %f seconds\n", $execution_time);
        }),
        "users" => fetch('https://jsonplaceholder.typicode.com/users')->then(function ($result) use ($start_time) {
            $end_time = microtime(true);
            $execution_time = $end_time - $start_time;
            printf("users execution time: %f seconds\n", $execution_time);
        }),
        "posts" => fetch('https://jsonplaceholder.typicode.com/posts')->then(function ($result) use ($start_time) {
            $end_time = microtime(true);
            $execution_time = $end_time - $start_time;
            printf("posts execution time: %f seconds\n", $execution_time);
        }),
    ]);

    return await($promise);
});
$end_time = microtime(true);
$execution_time = $end_time - $start_time;
printf("Execution time: %f seconds\n", $execution_time);
