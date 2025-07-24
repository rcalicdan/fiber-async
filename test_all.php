<?php
// File: test_all.php

use Rcalicdan\FiberAsync\Api\Async; // adjust namespace if needed
require 'vendor/autoload.php';

// 10 endpoints to test
$urls = [
    'posts'     => 'https://jsonplaceholder.typicode.com/posts/1',
    'users'     => 'https://jsonplaceholder.typicode.com/users/1',
    'albums'    => 'https://jsonplaceholder.typicode.com/albums/1',
    'comments'  => 'https://jsonplaceholder.typicode.com/comments/1',
    'todos'     => 'https://jsonplaceholder.typicode.com/todos/1',
    'posts2'    => 'https://jsonplaceholder.typicode.com/posts/2',
    'users2'    => 'https://jsonplaceholder.typicode.com/users/2',
    'albums2'   => 'https://jsonplaceholder.typicode.com/albums/2',
    'comments2' => 'https://jsonplaceholder.typicode.com/comments/2',
    'todos2'    => 'https://jsonplaceholder.typicode.com/todos/2',
];

// Measure only all()
$memStart = memory_get_usage(false);
$t0       = microtime(true);

$results = run(function() use ($urls) {
    $tasks = [];
    foreach ($urls as $key => $url) {
        $tasks[$key] = function() use ($key, $url) {
            $resp = await(fetch($url));
            return [
                'type'   => $key,
                'status' => $resp->status(),
                'body'   => $resp->json(),
            ];
        };
    }
    return await(all($tasks));
});

$duration = microtime(true) - $t0;
$memEnd   = memory_get_usage(false);
$memPeak  = memory_get_peak_usage(false);

printf(
    "FiberAsync all           : %7.4f s | Start: %6.1f KB | End: %6.1f KB | Peak: %6.1f KB | Count: %d\n\n",
    $duration,
    $memStart / 1024,
    $memEnd   / 1024,
    $memPeak  / 1024,
    count($results)
);

// print results
foreach ($results as $item) {
    printf(
        "%-10s | Status: %3d | Data: %s\n",
        $item['type'],
        $item['status'],
        isset($item['body']['title']) ? $item['body']['title']
          : (isset($item['body']['name']) ? $item['body']['name']
          : json_encode($item['body']))
    );
}
