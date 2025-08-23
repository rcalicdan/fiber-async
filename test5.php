<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;

require 'vendor/autoload.php';

Task::run(function () {
    $handler = Http::testing();

    $url = 'https://api.example.com/data';

    Http::mock('GET')
        ->url($url)
        ->delay(1)
        ->rateLimitedUntilAttempt(10) 
        ->register();

    try {
        $start = microtime(true);
        $response = await(Http::fetch($url, [
            'retry' => [
                'max_retries' => 5,
                'base_delay' => 1,
                'backoff_multiplier' => 1.5,
            ]
        ]));
        $end = microtime(true);
        $elapsed = $end - $start;
        echo "Success! Response: " . $response->body() . " Elapsed: " . $elapsed . "s";
    } catch (Exception $e) {
        echo "Failed: " . $e->getMessage();
    }
});
