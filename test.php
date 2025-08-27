<?php

use Rcalicdan\FiberAsync\Api\Benchmark;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\Benchmark\BenchmarkRunner;

require_once 'vendor/autoload.php';

ini_set("MEMORY_LIMIT", '2048M');

$urls = array_fill(0, 5, 'https://jsonplaceholder.typicode.com/posts/1');
BenchmarkRunner::create("Fiber Async Test")
->callback(function () use ($urls) {
    Task::run(function () use ($urls) {
        foreach ($urls as $url) {
            await(Http::request()->get($url));
        }
    });
})
->run();
