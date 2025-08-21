<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;

function http2()
{
    return Http::request()->http3();
}

$start = microtime(true);

Task::run(function () {
    echo "=== Testing HTTP/2 with Updated FiberAsync ===\n";

    try {
        $urls = [
            http2()->get('https://http2.github.io/'),
            http2()->get('https://http2.github.io/'),
            http2()->get('https://http2.github.io/'),
        ];

        $res = await(http2()->get('https://http2.github.io/'));
        var_dump($res);
    } catch (Exception $e) {
        echo 'Error: '.$e->getMessage()."\n";
        echo $e->getTraceAsString()."\n";
    }
});

$end = microtime(true);
$executionTime = $end - $start;
echo 'Execution Time: '.$executionTime." seconds\n";
