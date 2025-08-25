<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;

require 'vendor/autoload.php';
echo "====== Global Random Delay Simulation Test ======\n";
Task::run(function () {
    $handler = Http::testing()
        ->withGlobalRandomDelay(1, 1);

    $handler->mock("GET")->url('https://testings.com')
        ->respondWithStatus(200)
        ->Json([
            "status_code" => 200,
            "sucess" => true
        ])
        ->register();
    
    $startTime = microtime(true);
    $response = await(Http::get('https://testings.com'));
    $endTime = microtime(true);
    $executionTime = $endTime - $startTime;
    echo "Execution Time: " . $executionTime . " seconds\n";
    echo $response->getBody();
});
