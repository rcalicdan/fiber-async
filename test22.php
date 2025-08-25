<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;

require 'vendor/autoload.php';

echo "====== Global Random Delay Simulation Test ======\n";

Task::run(function () {
    $url = 'https://testings.com';

    $handler = Http::startTesting()
        ->withGlobalRandomDelay(1.0, 1.5)
    ;

    $handler->mock('GET')->url($url)
        ->respondWithStatus(200)
        ->json([
            'status_code' => 200,
            'success' => true,
        ])
        ->persistent()
        ->register()
    ;

    $startTime = microtime(true);

    $response = Http::fetch($url, [
        'cache' => true,
    ]);

    await($response);

    echo microtime(true) - $startTime." seconds response 1 time\n";

    $startTime = microtime(true);

    await($response);

    echo microtime(true) - $startTime." seconds response 2 time\n";
});
