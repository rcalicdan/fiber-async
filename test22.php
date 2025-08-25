<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Api\Timer;
use Rcalicdan\FiberAsync\Promise\Promise;

require 'vendor/autoload.php';

echo "====== Global Random Delay Simulation Test ======\n";

Task::run(function () {
    $url = 'https://testings.com';

    $handler = Http::startTesting()
        ->withGlobalRandomDelay(1.0, 1.5);

    $handler->mock("GET")->url($url)
        ->respondWithStatus(200)
        ->json([
            "status_code" => 200,
            "success" => true
        ])
        ->persistent()
        ->register();

    $response = Http::fetch($url);

    $startTime = microtime(true);

    await($response);

    print round(microtime(true) - $startTime, 2) . " seconds response 1 time\n";

    $startTime2 = microtime(true);

    await($response);

    print round(microtime(true) - $startTime2, 2) . " seconds response 2 time\n";
});
