<?php

require "vendor/autoload.php";

function timed_http_get($url)
{
    $start = microtime(true);
    $promise = http_get($url);

    return $promise->then(function ($response) use ($start, $url) {
        $end = microtime(true);
        $duration = $end - $start;
        echo "Request to $url completed in: " . number_format($duration, 3) . "s\n";
        return $response;
    });
}

$stat = microtime(true);

run_all([
    timed_http_get('https://httpbin.org/delay/1'),
    timed_http_get('https://httpbin.org/delay/1'),
    timed_http_get('https://httpbin.org/delay/1'),
]);

$end = microtime(true);
echo "\nTotal execution time: " . number_format($end - $stat, 3) . "s\n";
