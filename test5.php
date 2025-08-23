<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;

require 'vendor/autoload.php';

Task::run(function () {
    $handler = Http::testing();

    $url = 'https://api.example.com/data';

    // First attempt - retryable timeout
    Http::mock('GET')
        ->url($url)
        ->retryableFailure('Connection timed out')
        ->delay(1.0)
        ->register();

    // Second attempt - retryable network error  
    Http::mock('GET')
        ->url($url)
        ->retryableFailure('Connection failed')
        ->delay(0.5)
        ->register();

    // Third attempt - another timeout
    Http::mock('GET')
        ->url($url)
        ->timeoutFailure(2.0)
        ->register();

    // Fourth attempt - SUCCESS!
    Http::mock('GET')
        ->url($url)
        ->respondWith(200)
        ->json(['status' => 'success', 'data' => 'finally worked!'])
        ->register();

    // Make request with retry config
    try {
        $response = await(Http::fetch($url, [
            'retry' => [
                'max_retries' => 5,
                'base_delay' => 0.5,
                'backoff_multiplier' => 1.5,
            ]
        ]));

        echo "Success! Response: " . $response->body();
        // Will output: Success! Response: {"status":"success","data":"finally worked!"}

    } catch (Exception $e) {
        echo "Failed: " . $e->getMessage();
    }
});
