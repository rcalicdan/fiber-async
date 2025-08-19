<?php

use Rcalicdan\FiberAsync\Api\Task;

require 'vendor/autoload.php';

try {
    $result = Task::run(function () {
        $response = await(fetch('https://api.example.com/data', [
            'method' => 'POST',
            'body' => json_encode(['key' => 'value']),
            'headers' => ['Content-Type' => 'application/json'],
            'retry' => [
                'max_retries' => 3,
                'base_delay' => 0,3,
                'backoff_multiplier' => 1.5
            ]
        ]));

        return $response->json();
    });

    print_r($result);
} catch (Exception $e) {
    echo 'Caught exception: ', $e->getMessage(), "\n";
}
