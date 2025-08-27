<?php

use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Promise as FiberPromise;
use Rcalicdan\FiberAsync\Benchmark\BenchmarkRunner;

require_once 'vendor/autoload.php';

ini_set("MEMORY_LIMIT", '2048M');

$urls = array_fill(0, 5, 'https://jsonplaceholder.typicode.com/posts/1');

BenchmarkRunner::compareWith()
    ->add('Fiber Async Sequential', function () use ($urls) {
        Task::run(function () use ($urls) {
            foreach ($urls as $url) {
                await(Http::request()->get($url));
            }
        });
    })
    ->add('Fiber Async Concurrent (Promise::all)', function () use ($urls) {
        Task::run(function () use ($urls) {
            $tasks = [];
            foreach ($urls as $url) {
                $tasks[] = Http::request()->get($url); // returns FiberPromise
            }

            await(FiberPromise::all($tasks));
        });
    })
    ->memory()
    ->runs(5)
    ->warmup(5)
    ->isolateRuns()
    ->outputEnabled()
    ->run();
